<?php

namespace App\Tests\Support;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\FindingAssessment;
use App\Entity\RetestRun;
use App\Entity\ScreenshotJob;
use App\Service\EvidenceStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Synthetic retained records only; no intake, capture client or queue service. */
final class StudioDiagnosticsFixture
{
    public static function seed(EntityManagerInterface $manager, EvidenceStorageInterface $storage): array
    {
        $domain = (new Domain())->setHostname('diagnostics.invalid')->setScheme('https');
        $manager->persist($domain);
        $case = static function (string $title) use ($manager, $domain): Finding {
            $finding = (new Finding())->setDomain($domain)->setTitle($title)->setType('stored-case')
                ->setUrl('https://diagnostics.invalid/'.rawurlencode($title))->setPrivateNotes('Retained fixture note');
            $manager->persist($finding);
            return $finding;
        };
        $affected = $case('Affected <script id="untrusted">fixture</script>');
        $recovered = $case('Recovered case');
        $technical = $case('Technical only');
        $archived = $case('Archived evidence');
        $archived->setManualAssessment('discarded', null, new \DateTimeImmutable('2026-10-01'));
        $manager->flush();
        $images = [];
        foreach (['basis', 'newer', 'latest', 'missing', 'invalid'] as $index => $name) {
            $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
            $pixel = $name === 'basis' ? "\x49\x85\xcc\xff" : "\x60\xb0\x70\xff";
            $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 400, 240, 8, 6, 0, 0, 0))
                .$chunk('IDAT', gzcompress(str_repeat("\x00".str_repeat($pixel, 400), 240))).$chunk('IEND', '');
            $stored = $storage->storeContents($affected, $name === 'invalid' ? 'not an image' : $bytes, $name.'.png');
            if ($name === 'missing') $storage->deleteFile($stored->relativePath);
            $image = (new Evidence())->setFinding($affected)->setKind('screenshot')->setFilePath($stored->relativePath)->setSha256($stored->sha256);
            $manager->persist($image);
            $images[$name] = $image;
        }
        $missingArchive = (new Evidence())->setFinding($archived)->setKind('screenshot');
        $manager->persist($missingArchive);
        $at = new \DateTimeImmutable('2026-10-01 12:00:00');
        $affected->setManualAssessment('confirmed', null, $at);
        $assessment = new FindingAssessment($affected, 'confirmed', null, $at, evidenceId: $images['basis']->getId());
        $manager->persist($assessment);
        foreach ([$affected, $recovered] as $finding) {
            foreach ([1, 2, 3] as $index) {
                $failed = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('failed')
                    ->setRequestedAt(new \DateTimeImmutable('2026-10-04 12:00:00'))->setFinishedAt(new \DateTimeImmutable('2026-10-04 12:01:00'))
                    ->setErrorMessage('Fixture capture failure '.$index.' <b id="error-markup">literal</b>');
                $manager->persist($failed);
                $manager->flush(); // Same-second latest insertion must win, not random UUID order.
            }
        }
        $capture = (new ScreenshotJob())->setFinding($affected)->setUrl($affected->getUrl())->setStatus('available')
            ->setRequestedAt(new \DateTimeImmutable('2026-10-02'))->setCapturedAt(new \DateTimeImmutable('2026-10-02 12:00:00'))
            ->setScreenshotPath($images['newer']->getFilePath());
        $manager->persist($capture);
        $success = (new ScreenshotJob())->setFinding($recovered)->setUrl($recovered->getUrl())->setStatus('available')
            ->setRequestedAt(new \DateTimeImmutable('2026-10-05'))->setFinishedAt(new \DateTimeImmutable('2026-10-05'));
        $manager->persist($success);
        foreach ([$affected, $technical, $recovered] as $finding) {
            foreach ([1, 2] as $index) {
                $run = (new RetestRun())->setFinding($finding)->setResult('error')->setErrorMessage('Fixture technical failure '.$index)
                    ->setStartedAt(new \DateTimeImmutable('2026-10-04'))->setFinishedAt(new \DateTimeImmutable('2026-10-04'));
                $manager->persist($run);
                $manager->flush();
            }
        }
        $manager->persist((new RetestRun())->setFinding($recovered)->setResult('still_vulnerable')
            ->setStartedAt(new \DateTimeImmutable('2026-10-05'))->setFinishedAt(new \DateTimeImmutable('2026-10-05')));
        $manager->flush();
        // Ensure distinct, deterministic storage dates independent of fixture creation speed.
        foreach ($images as $name => $image) {
            $date = ['basis' => 1, 'newer' => 2, 'latest' => 3, 'missing' => 4, 'invalid' => 5][$name];
            $manager->getConnection()->executeStatement('UPDATE evidence SET created_at = ? WHERE id = ?', ['2026-10-0'.$date.' 12:00:00', $image->getId()]);
        }
        return ['affected' => $affected->getId(), 'recovered' => $recovered->getId(), 'technical' => $technical->getId(), 'archived' => $archived->getId(), 'assessment' => $assessment->getId(), 'images' => array_map(static fn (Evidence $e): string => $e->getId(), $images)];
    }
}
