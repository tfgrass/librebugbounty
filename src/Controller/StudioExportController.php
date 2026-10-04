<?php

namespace App\Controller;

use App\Service\StudioExportProfileService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StudioExportController
{
    public function __construct(
        private readonly StudioExportProfileService $profiles,
        private readonly UiTranslator $i18n,
    ) {}

    #[Route(path: '/export', name: 'studio_export', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $view = $this->profiles->get($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalidFilter($exception);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locale = $this->i18n->locale();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $formatTime = fn (?\DateTimeInterface $at, bool $withSeconds = false): string => $this->i18n->formatDateTime($at, $withSeconds);
        $formatDate = fn (\DateTimeInterface|string $date): string => $this->i18n->formatDate($date);
        $formatNumber = fn (int|float $value, int $decimals = 0): string => $this->i18n->formatNumber($value, $decimals);
        $i18nJson = $this->i18n->browserCatalogJson();
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/export.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route(path: '/export/download', name: 'studio_export_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        try {
            $options = $this->profiles->parse($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalidFilter($exception);
        }

        if ($options->profile === 'report') {
            try {
                $path = $this->profiles->buildReportArchive($options);
            } catch (\InvalidArgumentException $exception) {
                return $this->invalidFilter($exception);
            }
            try {
                $response = new BinaryFileResponse($path, Response::HTTP_OK, [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="librebugbounty-report-'.(new \DateTimeImmutable())->format('Ymd-His').'.zip"',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
                $response->deleteFileAfterSend(true);
                register_shutdown_function(static function () use ($path): void { @unlink($path); });
            } catch (\Throwable $exception) {
                @unlink($path);
                throw $exception;
            }

            return $response;
        }

        $filename = $options->profile === 'urls' ? 'librebugbounty-urls-' : 'librebugbounty-findings-';

        return new StreamedResponse(
            fn () => $this->profiles->writeJson($options, static function (string $part): void { echo $part; }),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.(new \DateTimeImmutable())->format('Ymd-His').'.json"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function invalidFilter(\InvalidArgumentException $exception): Response
    {
        return new Response($this->i18n->trans('Ungültiger Exportfilter: {message}', ['message' => $this->i18n->trans($exception->getMessage())]), Response::HTTP_BAD_REQUEST, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }
}
