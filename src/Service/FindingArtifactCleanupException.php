<?php

namespace App\Service;

final class FindingArtifactCleanupException extends \RuntimeException
{
    public function __construct(public readonly string $findingId, \Throwable $previous)
    {
        parent::__construct(
            'Der Fall wurde aus der Datenbank gelöscht, aber seine Artefakte konnten nicht entfernt werden. Bitte führe app:artifacts:audit aus und prüfe die Artefaktablage.',
            0,
            $previous,
        );
    }
}
