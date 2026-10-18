<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Dto\FindingReadView;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingReadRepository;
use App\Service\ContactRouteService;
use App\Service\DisclosureRecordService;
use App\Service\FindingListService;
use App\Service\FollowUpService;
use App\Service\InventoryViewService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

final class InventoryWorkbasketTest extends DatabaseTestCase
{
    public function testWorkbasketCanonicalFiltersSurviveSavedViewsWithoutPaging(): void
    {
        $lists = self::getContainer()->get(FindingListService::class);
        $views = self::getContainer()->get(InventoryViewService::class);
        [$filter, $page, $pageSize] = $lists->parse(['view' => 'workbasket', 'page' => '2', 'pageSize' => '25']);
        self::assertSame('workbasket', $filter->view);
        self::assertSame('open', $filter->reminder);
        self::assertSame(2, $page);
        self::assertSame('25', $pageSize);
        self::assertTrue($lists->hasListQuery(['view' => 'workbasket']));
        $expected = ['scope' => 'active', 'view' => 'workbasket', 'reminder' => 'open'];
        self::assertEquals($expected, $lists->filterQuery($filter));
        self::assertEquals($expected, $views->normalize(['view' => 'workbasket', 'page' => '2', 'pageSize' => '25', 'message' => 'Saved']));
        $id = $views->create('My workbasket', ['view' => 'workbasket', 'page' => '2']);
        $saved = array_values(array_filter($views->all(), static fn (array $view): bool => $view['id'] === $id))[0];
        self::assertEquals($expected, $saved['query']);
        parse_str(parse_url($saved['url'], PHP_URL_QUERY), $urlQuery);
        self::assertEquals($expected, $urlQuery);
        self::assertSame($saved['query'], $views->normalize($saved['query']));
        foreach (['today', 'overdue'] as $reminder) {
            [$narrowed] = $lists->parse(['view' => 'workbasket', 'reminder' => $reminder]);
            self::assertSame($reminder, $narrowed->reminder);
        }
    }

    public function testUnsupportedOrNonScalarViewsAreRejected(): void
    {
        foreach (['queue', 'all', 'WORKBASKET'] as $view) {
            try {
                new FindingReadFilter(view: $view);
                self::fail('An unsupported inventory view was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(FindingListService::class)->parse(['view' => ['workbasket']]);
    }

    public function testDueOrderAndPaginationAreStableWithoutChangingDefaultInventoryOrder(): void
    {
        $old = $this->finding('old', '2026-10-01 09:00:00', '2026-10-01 09:00:00');
        $created = $this->finding('created', '2026-10-01 09:00:00', '2026-10-02 09:00:00');
        $inserted = $this->finding('inserted', '2026-10-01 09:00:00', '2026-10-02 09:00:00');
        $submitted = $this->finding('submitted', '2026-10-02 09:00:00', '2026-10-01 09:00:00');
        $earliest = $this->finding('earliest', '2026-01-01 09:00:00');
        $future = $this->finding('future', '2026-10-15 09:00:00');
        $completed = $this->finding('completed', '2026-10-17 09:00:00');
        $none = $this->finding('none', '2026-10-18 09:00:00');
        foreach ([$old, $created, $inserted, $submitted] as $finding) {
            $this->reminder($finding, '2026-10-20', 'Follow up');
        }
        $this->reminder($earliest, '2026-10-19', 'Earliest task');
        $this->reminder($future, '2026-10-21', 'Future task');
        $this->reminder($completed, '2026-10-01', 'Already done');
        $records = self::getContainer()->get(DisclosureRecordService::class);
        $records->reminder($completed, ['action' => 'complete', 'revision' => $records->state($completed)['revision']]);
        $this->activity($inserted, 'reported', '2026-10-01');
        $this->activity($inserted, 'response', '2026-10-02');
        $this->activity($inserted, 'note', '2026-10-03');
        $before = $this->snapshot();
        $this->entityManager->clear();

        self::assertSame($this->ids([$none, $completed, $future, $submitted, $inserted, $created, $old, $earliest]), $this->pageIds(new FindingReadFilter()));
        $filter = new FindingReadFilter(view: 'workbasket');
        $expected = $this->ids([$earliest, $submitted, $inserted, $created, $old, $future]);
        self::assertSame($expected, $this->pageIds($filter));
        self::assertSame(6, $this->repository()->count($filter));
        $pages = [];
        for ($offset = 0; $offset < count($expected); $offset += 2) {
            $page = $this->repository()->findPage($filter, 2, $offset);
            self::assertCount(2, $page);
            $pages = [...$pages, ...array_column($page, 'id')];
        }
        self::assertSame($expected, $pages);
        self::assertCount(6, array_unique($pages), 'Multiple activities must never duplicate a finding or consume page slots.');
        self::assertSame([], $this->repository()->findPage($filter, 2, 6));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
        self::assertSame($before, $this->snapshot());
    }

    public function testTodayAndOverdueUseBerlinCalendarDaysIndependentlyOfProcessTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            $day = FindingReadFilter::date($today);
            $yesterday = $this->finding('yesterday');
            $dueToday = $this->finding('today');
            $tomorrow = $this->finding('tomorrow');
            $completed = $this->finding('done-today');
            $this->reminder($yesterday, $day->modify('-1 day')->format('Y-m-d'), 'Overdue');
            $this->reminder($dueToday, $today, 'Today');
            $this->reminder($tomorrow, $day->modify('+1 day')->format('Y-m-d'), 'Tomorrow');
            $this->reminder($completed, $today, 'Done');
            $records = self::getContainer()->get(DisclosureRecordService::class);
            $records->reminder($completed, ['action' => 'complete', 'revision' => $records->state($completed)['revision']]);
            $this->assertMatches([$dueToday], new FindingReadFilter(view: 'workbasket', reminder: 'today'));
            $this->assertMatches([$yesterday], new FindingReadFilter(view: 'workbasket', reminder: 'overdue'));
            $this->assertMatches([$yesterday, $dueToday, $tomorrow], new FindingReadFilter(view: 'workbasket', reminder: 'open'));
            self::assertSame($today, $this->repository()->findPage(new FindingReadFilter(view: 'workbasket', reminder: 'today'))[0]->reminderDueOn);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testWorkbasketRetainsArchiveScopeAndExplicitPolicyFilters(): void
    {
        $active = $this->finding('active');
        $closed = $this->finding('closed');
        $blocked = $this->finding('blocked');
        $duplicate = $this->finding('duplicate')->setStatus('duplicate');
        $discarded = $this->finding('discarded')->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->flush();
        foreach ([$active, $closed, $blocked, $duplicate, $discarded] as $finding) {
            $this->reminder($finding, '2026-10-20', 'Next');
        }
        $followUp = self::getContainer()->get(FollowUpService::class);
        $followUp->save($closed, ['pursuit' => 'closed', 'reason' => 'declined']);
        $followUp->save($blocked, ['scope' => 'domain', 'contact_blocked' => '1']);
        $this->assertMatches([$active, $closed, $blocked], new FindingReadFilter(view: 'workbasket'));
        $this->assertMatches([$duplicate, $discarded], new FindingReadFilter(view: 'workbasket', scope: 'discarded'));
        $this->assertMatches([$duplicate], new FindingReadFilter(view: 'workbasket', scope: 'duplicates'));
        $this->assertMatches([$active, $closed, $blocked, $duplicate, $discarded], new FindingReadFilter(view: 'workbasket', scope: 'all'));
        $this->assertMatches([$active, $blocked], new FindingReadFilter(view: 'workbasket', pursuit: 'active'));
        $this->assertMatches([$closed], new FindingReadFilter(view: 'workbasket', pursuit: 'closed', closureReason: 'declined'));
        $this->assertMatches([$closed, $blocked], new FindingReadFilter(view: 'workbasket', contactWork: 'blocked'));
        $this->assertMatches([$active], new FindingReadFilter(view: 'workbasket', contactWork: 'allowed'));
    }

    public function testProjectionUsesSelectedRouteAndLatestActivityByDayThenInsertion(): void
    {
        $finding = $this->finding('projection');
        $plain = $this->finding('plain');
        $this->reminder($finding, '2026-10-20', 'Ask for an update');
        $routes = self::getContainer()->get(ContactRouteService::class);
        $routes->save($finding, ['mode' => 'manual', 'revision' => 'none', 'channel' => 'email', 'destination' => 'security@workbasket.invalid']);
        $routes->save($finding, ['mode' => 'manual', 'revision' => $routes->state($finding)['revision'], 'channel' => 'web', 'destination' => 'https://workbasket.invalid/security/report']);
        $this->activity($finding, 'reported', '2026-10-10', 'ffffffff-ffff-4fff-8fff-ffffffffffff');
        $this->activity($finding, 'response', '2026-10-10', '00000000-0000-4000-8000-000000000001');
        $this->activity($finding, 'note', '2026-10-09');
        $view = $this->repository()->findPage(new FindingReadFilter(view: 'workbasket'))[0];
        self::assertSame('2026-10-20', $view->reminderDueOn);
        self::assertSame('Ask for an update', $view->reminderNextStep);
        self::assertSame('web', $view->contactRouteChannel);
        self::assertSame('https://workbasket.invalid/security/report', $view->contactRouteDestination);
        self::assertSame('response', $view->lastDisclosureActivity);
        self::assertSame('2026-10-10', $view->lastDisclosureActivityOn);
        $views = array_column($this->repository()->findPage(new FindingReadFilter()), null, 'id');
        foreach (['reminderDueOn', 'reminderNextStep', 'contactRouteChannel', 'contactRouteDestination', 'lastDisclosureActivity', 'lastDisclosureActivityOn'] as $field) {
            self::assertNull($views[$plain->getId()]->{$field}, $field);
        }
    }

    #[DataProvider('missingDisclosureTables')]
    public function testMissingDisclosureSchemaKeepsInventoryReadableAndWorkbasketEmpty(string $table): void
    {
        $finding = $this->finding('missing-schema');
        $this->reminder($finding, '2026-10-20', 'Next');
        $this->db()->executeStatement('DROP TABLE '.$table);
        $this->assertMatches([], new FindingReadFilter(view: 'workbasket'));
        $this->assertMatches([], new FindingReadFilter(view: 'workbasket', reminder: 'today'));
        $this->assertMatches([$finding], new FindingReadFilter());
    }

    public static function missingDisclosureTables(): iterable
    {
        yield 'activity' => ['disclosure_activity'];
        yield 'reminder' => ['disclosure_reminder'];
    }

    public function testMissingContactRouteSchemaDoesNotHideOpenReminders(): void
    {
        $finding = $this->finding('missing-route');
        $this->reminder($finding, '2026-10-20', 'Next');
        $this->db()->executeStatement('DROP TABLE finding_contact_route');
        $this->assertMatches([$finding], new FindingReadFilter(view: 'workbasket'));
        $view = $this->repository()->findPage(new FindingReadFilter(view: 'workbasket'))[0];
        self::assertNull($view->contactRouteChannel);
        self::assertNull($view->contactRouteDestination);
    }

    public function testNativeExportFormKeepsWorkbasketSelectionFromPreviewThroughDownload(): void
    {
        $wanted = $this->finding('export-wanted');
        $otherReason = $this->finding('export-other-reason');
        $future = $this->finding('export-future');
        $active = $this->finding('export-active');
        $withoutReminder = $this->finding('export-without-reminder');
        $today = DisclosureRecordService::today();
        foreach ([$wanted, $otherReason, $active] as $finding) {
            $this->reminder($finding, $today, 'Follow up');
        }
        $this->reminder($future, FindingReadFilter::date($today)->modify('+1 day')->format('Y-m-d'), 'Later');
        $followUp = self::getContainer()->get(FollowUpService::class);
        foreach ([$wanted, $future, $withoutReminder] as $finding) {
            $followUp->save($finding, ['pursuit' => 'closed', 'reason' => 'declined']);
        }
        $followUp->save($otherReason, ['pursuit' => 'closed', 'reason' => 'not_relevant']);
        $query = ['view' => 'workbasket', 'reminder' => 'today', 'pursuit' => 'closed', 'closure_reason' => 'declined', 'contact_work' => 'blocked'];
        $before = $this->snapshot();
        $inventory = $this->xpath($this->request('/findings?'.http_build_query($query)));
        $exportUrl = $inventory->evaluate('string(//a[@data-export-selection]/@href)');
        self::assertNotSame('', $exportUrl);
        $preview = $this->request($exportUrl.'&profile=state');
        self::assertSame(200, $preview->getStatusCode());
        $xpath = $this->xpath($preview);
        self::assertSame('1', $xpath->evaluate('string(//*[@data-export-finding-count]/@data-export-finding-count)'));
        $parameters = $this->successfulFormValues($xpath, 'export-filters');
        foreach ($query as $key => $value) {
            self::assertSame($value, $parameters[$key] ?? null, 'Submitting the export form must retain '.$key.'.');
        }
        $download = $this->request('/export/download?'.http_build_query($parameters));
        self::assertSame(200, $download->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $download);
        ob_start();
        try {
            $download->sendContent();
            $json = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['findingCount']);
        self::assertSame([$wanted->getId()], array_column($data['findings'], 'id'));
        foreach ($query as $key => $value) {
            self::assertSame($value, $data['filters'][$key] ?? null);
        }
        self::assertSame($before, $this->snapshot());
    }

    private function finding(string $name, string $submitted = '2026-10-01 09:00:00', string $created = '2026-10-01 09:00:00'): Finding
    {
        $domain = (new Domain())->setHostname($name.'.workbasket.invalid');
        $finding = (new Finding())->setDomain($domain)->setType('stored-case')->setTitle($name)
            ->setUrl('https://'.$domain->getHostname().'/case')->setSubmittedAt(new \DateTimeImmutable($submitted));
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $this->db()->update('finding', ['created_at' => $created], ['id' => $finding->getId()]);

        return $finding;
    }

    private function reminder(Finding $finding, string $dueOn, string $nextStep): void
    {
        $records = self::getContainer()->get(DisclosureRecordService::class);
        $records->reminder($finding, ['action' => 'save', 'revision' => $records->state($finding)['revision'], 'due_on' => $dueOn, 'next_step' => $nextStep]);
    }

    private function activity(Finding $finding, string $activity, string $occurredOn, ?string $id = null): void
    {
        self::getContainer()->get(DisclosureRecordService::class)->addActivity($finding, [
            'entry_id' => $id ?? Uuid::v7()->toRfc4122(), 'activity' => $activity, 'occurred_on' => $occurredOn,
            'channel' => 'email', 'recipient' => 'historical-recipient@workbasket.invalid',
        ]);
    }

    /** @param list<Finding> $expected */
    private function assertMatches(array $expected, FindingReadFilter $filter): void
    {
        $ids = $this->ids($expected);
        $actual = $this->pageIds($filter);
        sort($ids);
        sort($actual);
        self::assertSame($ids, $actual);
        self::assertSame(count($ids), $this->repository()->count($filter));
    }

    /** @param list<Finding> $findings @return list<string> */
    private function ids(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->getId(), $findings);
    }

    /** @return list<string> */
    private function pageIds(FindingReadFilter $filter): array
    {
        return array_map(static fn (FindingReadView $finding): string => $finding->id, $this->repository()->findPage($filter));
    }

    private function repository(): FindingReadRepository
    {
        return self::getContainer()->get(FindingReadRepository::class);
    }

    private function request(string $path): Response
    {
        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    /** Browser-style GET submission, including selected choices and checked checkboxes. */
    private function successfulFormValues(\DOMXPath $xpath, string $formId): array
    {
        $form = $xpath->query('//form[@id="'.$formId.'"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $values = [];
        foreach ($xpath->query('.//input[@name and not(@disabled)] | .//select[@name and not(@disabled)]', $form) as $control) {
            $name = $control->getAttribute('name');
            if ($control->tagName === 'select') {
                $options = $control->getElementsByTagName('option');
                $selected = $options->item(0);
                foreach ($options as $option) {
                    if ($option->hasAttribute('selected')) {
                        $selected = $option;
                        break;
                    }
                }
                if ($selected !== null) {
                    $values[$name] = $selected->getAttribute('value');
                }
                continue;
            }
            $type = strtolower($control->getAttribute('type'));
            if (in_array($type, ['submit', 'button'], true) || (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked'))) {
                continue;
            }
            $values[$name] = $control->getAttribute('value');
        }

        return $values;
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'disclosure_reminder', 'disclosure_activity', 'finding_contact_route'] as $table) {
            $snapshot[$table] = $this->db()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }

        return $snapshot;
    }
}
