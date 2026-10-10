<?php
namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\FollowUpService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class RestrictionController
{
    public function __construct(private readonly FollowUpService $followUp, private readonly FindingRepository $findings,
        private readonly CsrfTokenManagerInterface $csrf, private readonly UiTranslator $i18n) {}

    #[Route('/settings/restrictions', name: 'settings_restrictions_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $input = $request->request->all();
        if (!is_string($input['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('settings_restrictions', $input['_token']))) return new Response($this->i18n->trans('Ungültiges Formular. Bitte lade die Seite neu.'), 403, ['Cache-Control' => 'no-store']);
        $kind = 'message';
        try {
            if (!is_string($input['lift'] ?? null)) throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
            if (!is_string($input['revision'] ?? null)) throw new \InvalidArgumentException('Ungültige Formularrevision. Bitte lade die Seite neu.');
            if (($input['scope'] ?? null) === 'domain' && is_string($input['hostname'] ?? null)) {
                $this->followUp->liftDomain($input['hostname'], $input['lift'], $input['revision']);
            } elseif (($input['scope'] ?? null) === 'case' && is_string($input['finding_id'] ?? null) && Uuid::isValid($input['finding_id'])) {
                $finding = $this->findings->find($input['finding_id']);
                if (!$finding instanceof Finding) throw new \InvalidArgumentException('Fall nicht gefunden.');
                $this->followUp->liftCase($finding, $input['lift'], $input['revision']);
            } else throw new \InvalidArgumentException('Ungültige Angaben zur Nachverfolgung.');
            $message = 'Sperren gespeichert. Andere Sperren bleiben unverändert.';
        } catch (\LogicException $exception) { $kind = 'error'; $message = $exception->getMessage(); }
        return new RedirectResponse('/settings?'.http_build_query([$kind => $this->i18n->trans($message)], '', '&', PHP_QUERY_RFC3986).'#restrictions', 303, ['Cache-Control' => 'no-store']);
    }
}
