<?php
namespace App\Controller;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use App\Service\ContactDiscoveryService;
use App\Service\FindingNavigation;
use App\Service\FollowUpService;
use App\Service\UiTranslator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

final class FindingWorkController
{
    public function __construct(private readonly FindingRepository $findings, private readonly FollowUpService $followUp,
        private readonly ContactDiscoveryService $contacts, private readonly CsrfTokenManagerInterface $csrf,
        private readonly FindingNavigation $navigation, private readonly UiTranslator $i18n) {}

    #[Route('/findings/{id}/follow-up', name: 'finding_follow_up', methods: ['POST'])]
    public function followUp(string $id, Request $request): Response { return $this->change($id, $request, false); }
    #[Route('/findings/{id}/contact-discovery', name: 'finding_contact_discovery', methods: ['POST'])]
    public function discovery(string $id, Request $request): Response { return $this->change($id, $request, true); }

    private function change(string $id, Request $request, bool $discovery): Response
    {
        $input = $request->request->all();
        $token = $input['_token'] ?? null;
        if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(($discovery ? 'finding_contact_discovery_' : 'finding_follow_up_').$id, $token))) return new Response($this->i18n->trans('Ungültiges Formular. Bitte lade die Seite neu.'), 403, ['Cache-Control' => 'no-store']);
        $finding = Uuid::isValid($id) ? $this->findings->find($id) : null;
        if (!$finding instanceof Finding) return new Response($this->i18n->trans('Fall nicht gefunden.'), 404, ['Cache-Control' => 'no-store']);
        $return = $this->navigation->findingReturnPath($id, $input);
        $kind = 'message';
        try {
            if ($discovery) {
                if (!is_string($input['provider'] ?? null)) throw new \InvalidArgumentException('Unbekannter Kontaktanbieter.');
                $this->contacts->discover($finding, $input['provider']);
                $message = 'Kontaktvorschläge aktualisiert. Wiederholte Anfragen innerhalb einer Minute werden zusammengefasst.';
            } else {
                $this->followUp->save($finding, $input);
                $message = 'Nachverfolgung gespeichert. Die fachliche Bewertung bleibt unverändert.';
            }
        } catch (\LogicException $exception) { $kind = 'error'; $message = $exception->getMessage(); }
        return new RedirectResponse($return.(str_contains($return, '?') ? '&' : '?').http_build_query([$kind => $this->i18n->trans($message)], '', '&', PHP_QUERY_RFC3986).($discovery ? '#kontakte' : '#nachverfolgung'), 303, ['Cache-Control' => 'no-store']);
    }
}
