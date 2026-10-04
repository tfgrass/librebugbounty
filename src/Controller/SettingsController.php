<?php

namespace App\Controller;

use App\AppInfo;
use App\Service\SettingsService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly UiTranslator $i18n,
    ) {
    }

    #[Route(path: '/settings', name: 'studio_settings', methods: ['GET'])]
    #[Route(path: '/legacy/settings', name: 'legacy_settings', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $settings = $this->settings->all();

        return $this->render(
            request: $request,
            defaultPayload: (string) ($settings['intake.default_payload'] ?? SettingsService::DEFAULTS['intake.default_payload']),
            reviewTimeout: (string) $this->settings->getReviewScanTimeoutMs(),
            reviewDecisionDelay: (string) $this->settings->getReviewDecisionDelaySeconds(),
            inventoryPageSize: $this->settings->getInventoryPageSize(),
            exportProfile: $this->settings->getExportProfile(),
            exportScreenshotMode: $this->settings->getExportScreenshotMode(),
            errors: [],
            message: $request->query->getString('message') ?: null,
        );
    }

    #[Route(path: '/settings', name: 'studio_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $parameters = $request->request->all();
        $optional = [
            'review_decision_delay_seconds' => (string) $this->settings->getReviewDecisionDelaySeconds(),
            'inventory_page_size' => $this->settings->getInventoryPageSize(),
            'export_profile' => $this->settings->getExportProfile(),
            'export_screenshot_mode' => $this->settings->getExportScreenshotMode(),
        ];
        if (!is_string($parameters['default_payload'] ?? null)
            || !is_string($parameters['review_timeout_ms'] ?? null)
        ) {
            return new Response($this->i18n->trans('Ungültige Einstellungsangaben.'), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
        foreach (array_keys($optional) as $field) {
            if (array_key_exists($field, $parameters)) {
                if (!is_string($parameters[$field])) {
                    return new Response($this->i18n->trans('Ungültige Einstellungsangaben.'), Response::HTTP_BAD_REQUEST, [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Cache-Control' => 'no-store',
                    ]);
                }
                $optional[$field] = trim($parameters[$field]);
            }
        }

        $defaultPayload = trim($parameters['default_payload']);
        $reviewTimeout = trim($parameters['review_timeout_ms']);
        $reviewDecisionDelay = $optional['review_decision_delay_seconds'];
        $inventoryPageSize = $optional['inventory_page_size'];
        $exportProfile = $optional['export_profile'];
        $exportScreenshotMode = $optional['export_screenshot_mode'];
        $errors = [];
        if ($defaultPayload === '') {
            $errors['default_payload'] = $this->i18n->trans('Das Standardkennzeichen darf nicht leer sein.');
        }
        if ($reviewTimeout === '' || !ctype_digit($reviewTimeout)) {
            $errors['review_timeout_ms'] = $this->i18n->trans('Das Zeitlimit muss eine ganze Zahl zwischen 1000 und 120000 Millisekunden sein.');
        } else {
            $timeout = (int) $reviewTimeout;
            if ($timeout < 1000 || $timeout > 120000) {
                $errors['review_timeout_ms'] = $this->i18n->trans('Das Zeitlimit muss zwischen 1000 und 120000 Millisekunden liegen.');
            }
        }
        if (!in_array($reviewDecisionDelay, ['0', '3', '5'], true)) {
            $errors['review_decision_delay_seconds'] = $this->i18n->trans('Wähle für die Entscheidungspause Aus, 3 oder 5 Sekunden.');
        }
        if (!in_array($inventoryPageSize, ['10', '25', '50', '100'], true)) {
            $errors['inventory_page_size'] = $this->i18n->trans('Wähle 10, 25, 50 oder 100 Fälle pro Seite.');
        }
        if (!in_array($exportProfile, ['urls', 'state', 'report'], true)) {
            $errors['export_profile'] = $this->i18n->trans('Wähle eine verfügbare Exportvorlage.');
        }
        if (!in_array($exportScreenshotMode, ['basis', 'latest', 'all', 'none'], true)) {
            $errors['export_screenshot_mode'] = $this->i18n->trans('Wähle eine verfügbare Screenshot-Auswahl.');
        }

        if ($errors !== []) {
            return $this->render($request, $defaultPayload, $reviewTimeout, $reviewDecisionDelay, $inventoryPageSize, $exportProfile, $exportScreenshotMode, $errors, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = [
            'intake.default_payload' => $defaultPayload,
            'review.scan_timeout_ms' => (string) (int) $reviewTimeout,
        ];
        foreach ([
            'review_decision_delay_seconds' => 'review.decision_delay_seconds',
            'inventory_page_size' => 'inventory.page_size',
            'export_profile' => 'export.default_profile',
            'export_screenshot_mode' => 'export.screenshot_mode',
        ] as $field => $key) {
            if (array_key_exists($field, $parameters)) {
                $values[$key] = $optional[$field];
            }
        }
        $this->settings->save($values);

        return new RedirectResponse('/settings?message='.rawurlencode($this->i18n->trans('Einstellungen gespeichert.')));
    }

    /** @param array<string, string> $errors */
    private function render(
        Request $request,
        string $defaultPayload,
        string $reviewTimeout,
        string $reviewDecisionDelay,
        string $inventoryPageSize,
        string $exportProfile,
        string $exportScreenshotMode,
        array $errors,
        ?string $message,
        int $status = Response::HTTP_OK,
    ): Response {
        $app = [
            'name' => AppInfo::NAME,
            'version' => AppInfo::VERSION,
            'releaseName' => AppInfo::RELEASE_NAME,
            'author' => AppInfo::AUTHOR,
            'homepage' => AppInfo::HOMEPAGE,
            'flickrUrl' => AppInfo::FLICKR_URL,
            'donationUrl' => AppInfo::DONATION_URL,
            'profile' => AppInfo::OPENBUGBOUNTY_PROFILE,
            'profileUrl' => AppInfo::OPENBUGBOUNTY_URL,
            'repository' => AppInfo::REPOSITORY,
            'license' => AppInfo::LICENSE,
        ];
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locale = $this->i18n->locale();
        $languageReturnPath = $request->getRequestUri();
        $t = fn (string $key, array $parameters = []): string => $this->i18n->trans($key, $parameters);
        $formatTime = fn (?\DateTimeInterface $at, bool $withSeconds = false): string => $this->i18n->formatDateTime($at, $withSeconds);
        $formatDate = fn (\DateTimeInterface|string $date): string => $this->i18n->formatDate($date);
        $formatNumber = fn (int|float $value, int $decimals = 0): string => $this->i18n->formatNumber($value, $decimals);
        $i18nJson = $this->i18n->browserCatalogJson();

        ob_start();
        require dirname(__DIR__, 2).'/templates/studio/settings.php';
        $html = ob_get_clean();

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
