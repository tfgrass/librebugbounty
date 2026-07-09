<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\RetestRunRepository;
use App\Service\ValidationService;

final class ExportService
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly FindingRepository $findings,
        private readonly RetestRunRepository $retestRuns,
        private readonly ValidationService $validation,
    ) {
    }

    public function export(?string $domainHostname = null, ?string $status = null): array
    {
        $domainFilter = null;
        if ($domainHostname !== null) {
            $domainFilter = $this->domains->findOneByNormalizedHostname($this->validation->normalizeHostname($domainHostname));
        }

        $rows = [];
        foreach ($this->findings->findByDomainAndStatus($domainFilter, $status) as $finding) {
            \assert($finding instanceof Finding);
            $rows[] = $this->serializeFinding($finding);
        }

        $domainRows = [];
        $selectedDomains = $domainFilter ? [$domainFilter] : $this->domains->findAllOrdered();
        foreach ($selectedDomains as $domain) {
            \assert($domain instanceof Domain);
            $domainRows[] = [
                'id' => $domain->getId(),
                'hostname' => $domain->getHostname(),
                'scheme' => $domain->getScheme(),
                'authorized' => $domain->isAuthorized(),
            ];
        }

        return [
            'domains' => $domainRows,
            'findings' => $rows,
        ];
    }

    private function serializeFinding(Finding $finding): array
    {
        $evidence = [];
        foreach ($finding->getEvidence() as $item) {
            $evidence[] = [
                'id' => $item->getId(),
                'kind' => $item->getKind(),
                'value' => $item->getValue(),
                'filePath' => $item->getFilePath(),
                'sha256' => $item->getSha256(),
                'createdAt' => $item->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        $retests = [];
        foreach ($this->retestRuns->findRecentByFinding($finding, 20) as $run) {
            \assert($run instanceof RetestRun);
            $retests[] = [
                'id' => $run->getId(),
                'mode' => $run->getMode(),
                'result' => $run->getResult(),
                'httpStatus' => $run->getHttpStatus(),
                'finalUrl' => $run->getFinalUrl(),
                'observedEvidence' => $run->getObservedEvidence(),
                'errorMessage' => $run->getErrorMessage(),
                'screenshotPath' => $run->getScreenshotPath(),
                'startedAt' => $run->getStartedAt()->format(DATE_ATOM),
                'finishedAt' => $run->getFinishedAt()?->format(DATE_ATOM),
            ];
        }

        return [
            'id' => $finding->getId(),
            'domain' => $finding->getDomain()->getHostname(),
            'title' => $finding->getTitle(),
            'type' => $finding->getType(),
            'severity' => $finding->getSeverity(),
            'status' => $finding->getStatus(),
            'url' => $finding->getUrl(),
            'method' => $finding->getMethod(),
            'requestParams' => $finding->getRequestParams(),
            'payload' => $finding->getPayload(),
            'expectedEvidence' => $finding->getExpectedEvidence(),
            'privateNotes' => $finding->getPrivateNotes(),
            'reportUrl' => $finding->getReportUrl(),
            'reportedAt' => $finding->getReportedAt()?->format(DATE_ATOM),
            'submittedAt' => $finding->getSubmittedAt()?->format(DATE_ATOM),
            'notifiedOwnerAt' => $finding->getNotifiedOwnerAt()?->format(DATE_ATOM),
            'contactedAt' => $finding->getContactedAt()?->format(DATE_ATOM),
            'lastRetestedAt' => $finding->getLastRetestedAt()?->format(DATE_ATOM),
            'createdAt' => $finding->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $finding->getUpdatedAt()->format(DATE_ATOM),
            'evidence' => $evidence,
            'retestRuns' => $retests,
        ];
    }
}
