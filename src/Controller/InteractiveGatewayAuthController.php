<?php

namespace App\Controller;

use App\Service\InteractiveBrowserCoordinator;
use App\Service\InteractiveBrowserLeaseService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Private DDEV listener only; independently requires a gateway service secret. */
final class InteractiveGatewayAuthController
{
    public function __construct(private readonly InteractiveBrowserCoordinator $browser, private readonly InteractiveBrowserLeaseService $leases, private readonly ?string $gatewayToken = null) {}

    #[Route('/internal/interactive-gateway/authorize', name: 'internal_interactive_gateway_authorize', methods: ['POST'])]
    public function authorize(Request $request): JsonResponse
    {
        $deny = static fn (int $status = 403): JsonResponse => new JsonResponse(['decision' => 'deny', 'reason' => 'unauthorized'], $status, ['Cache-Control' => 'no-store']);
        $expected = $this->gatewayToken;
        if ($expected === null || $expected === '') { return $deny(503); }
        if (!hash_equals('Bearer '.$expected, $request->headers->get('Authorization', ''))) { return $deny(401); }
        if (strlen($request->getContent()) > 4096) { return $deny(413); }
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !is_string($body['findingId'] ?? null) || !Uuid::isValid($body['findingId'])
                || !is_string($body['channelId'] ?? null) || !preg_match('/^[a-f0-9]{48}$/D', $body['channelId'])
                || (isset($body['generation']) && (!is_int($body['generation']) || $body['generation'] < 1))) { return $deny(400); }
            $session = $request->getSession();
            if (!$request->cookies->has($session->getName())) { return $deny(); }
            $session->start();
            $owner = $this->leases->ownerHash($session->getId());
            $session->save(); // never hold native session lock for DB/grant refresh
            return new JsonResponse($this->browser->authorize($body['findingId'], $owner, $body['channelId'], $body['generation'] ?? null), 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) { return $deny(); }
    }
}
