<?php
namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\DisclosureRecordService;
use App\Service\FindingNavigation;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DisclosureRecordController
{
    public function __construct(private readonly FindingRepository $findings, private readonly DisclosureRecordService $records,
        private readonly CsrfTokenManagerInterface $csrf, private readonly FindingNavigation $navigation, private readonly UiTranslator $i18n) {}

    #[Route('/findings/{id}/disclosure-record', name: 'finding_disclosure_record', methods: ['POST'])]
    public function save(string $id, Request $request): Response
    {
        $input = $request->request->all();
        if (!is_string($input['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('finding_disclosure_record_'.$id, $input['_token']))) return new Response($this->i18n->trans('Ungültiges Formular. Bitte lade die Seite neu.'), 403, ['Cache-Control' => 'no-store']);
        $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
        if (!$finding instanceof Finding) return new Response($this->i18n->trans('Fall nicht gefunden.'), 404, ['Cache-Control' => 'no-store']);
        $return = $this->navigation->findingReturnPath($id, $input);
        $kind = 'message';
        try {
            if (($input['kind'] ?? null) === 'activity') $this->records->addActivity($finding, $input);
            elseif (($input['kind'] ?? null) === 'reminder') $this->records->reminder($finding, $input);
            else throw new \InvalidArgumentException('Ungültige Angaben zur Meldeakte.');
            $message = 'Meldeakte gespeichert. Historische Kontakt- und Versandmarkierungen bleiben unverändert.';
        }
        catch (\LogicException $exception) { $kind = 'error'; $message = $exception->getMessage(); }
        return new RedirectResponse($return.(str_contains($return, '?') ? '&' : '?').http_build_query([$kind => $this->i18n->trans($message)], '', '&', PHP_QUERY_RFC3986).'#meldung', 303, ['Cache-Control' => 'no-store']);
    }
}
