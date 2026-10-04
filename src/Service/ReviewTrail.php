<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

/** Session-owned navigation, separately addressed by each review tab/durchlauf. */
final class ReviewTrail
{
    private const KEY = 'studio_review_trails';
    public const MAX_STEPS = 200;
    private const MAX_TRAILS = 20;

    public function open(SessionInterface $session, ?string $id): array
    {
        $trails = $session->get(self::KEY, []);
        if ($id !== null) {
            if (!Uuid::isValid($id) || !isset($trails[$id])) {
                throw new \UnexpectedValueException('Dieser Review-Durchlauf ist abgelaufen. Bitte Review neu öffnen.');
            }
            return $trails[$id];
        }
        $id = Uuid::v7()->toRfc4122();
        $trail = ['id' => $id, 'version' => 0, 'entries' => [], 'current' => null, 'truncated' => false];
        $this->save($session, $trail);
        return $trail;
    }

    public function displayed(SessionInterface $session, array $trail, ?array $card): array
    {
        $trail['current'] = $card;
        $this->save($session, $trail);
        return $trail;
    }

    public function check(SessionInterface $session, string $id, string $version, ?string $findingId = null): array
    {
        $trail = $this->open($session, $id);
        if (!ctype_digit($version) || (int) $version !== $trail['version']
            || ($findingId !== null && ($trail['current']['id'] ?? null) !== $findingId)) {
            throw new \UnexpectedValueException('Der Review-Durchlauf wurde inzwischen fortgesetzt. Bitte die aktuelle Karte erneut prüfen.');
        }
        return $trail;
    }

    public function forward(SessionInterface $session, array $trail, array $card): array
    {
        $trail['entries'][] = $card;
        if (count($trail['entries']) > self::MAX_STEPS) {
            array_shift($trail['entries']);
            $trail['truncated'] = true;
        }
        $trail['current'] = null;
        ++$trail['version'];
        $this->save($session, $trail);
        return $trail;
    }

    public function back(SessionInterface $session, array $trail, array $card): array
    {
        array_pop($trail['entries']);
        $trail['current'] = $card;
        ++$trail['version'];
        $this->save($session, $trail);
        return $trail;
    }

    public function removeMissingPrevious(SessionInterface $session, array $trail): array
    {
        array_pop($trail['entries']);
        ++$trail['version'];
        $this->save($session, $trail);
        return $trail;
    }

    private function save(SessionInterface $session, array $trail): void
    {
        $trails = $session->get(self::KEY, []);
        unset($trails[$trail['id']]);
        $trails[$trail['id']] = $trail;
        while (count($trails) > self::MAX_TRAILS) { array_shift($trails); }
        $session->set(self::KEY, $trails);
    }
}
