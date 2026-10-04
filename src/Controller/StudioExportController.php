<?php

namespace App\Controller;

use App\Service\StudioExportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StudioExportController
{
    public function __construct(private readonly StudioExportService $export)
    {
    }

    #[Route(path: '/export', name: 'studio_export', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $view = $this->export->get($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalidFilter($exception);
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/export.php';
        $html = ob_get_clean();

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    #[Route(path: '/export/download', name: 'studio_export_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        try {
            [$filter, $includeNotes] = $this->export->parse($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->invalidFilter($exception);
        }

        return new StreamedResponse(
            fn () => $this->export->writeDownload($filter, $includeNotes, static function (string $part): void { echo $part; }),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="librebugbounty-findings-'.(new \DateTimeImmutable())->format('Ymd-His').'.json"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function invalidFilter(\InvalidArgumentException $exception): Response
    {
        return new Response('Ungültiger Exportfilter: '.$exception->getMessage(), Response::HTTP_BAD_REQUEST, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }
}
