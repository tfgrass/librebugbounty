<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Keeps the deployed v1 readable while an additive migration is prepared. */
final class ReviewSchema
{
    public function __construct(private readonly Connection $connection) {}

    public function available(): bool
    {
        if ($this->connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'finding_review_acknowledgement'") === false) {
            return false;
        }
        return in_array('known_observation_states', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(finding_assessment)'), 'name'), true);
    }
}
