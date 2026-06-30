<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove duplicate findings for the same domain/url and add a unique constraint.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DELETE FROM finding
WHERE id IN (
  SELECT f2.id
  FROM finding f2
  INNER JOIN finding f1
    ON f1.domain_id = f2.domain_id
   AND f1.url = f2.url
   AND (
      f1.created_at < f2.created_at
      OR (f1.created_at = f2.created_at AND f1.id < f2.id)
   )
)
SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_finding_domain_url ON finding (domain_id, url)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_finding_domain_url');
    }
}
