<?php
namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;

/** Deny-only policy shared by manual and queued external work. */
final class FindingWorkPolicy
{
    public function __construct(private readonly Connection $connection) {}

    public static function available(Connection $connection): bool
    {
        return (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('finding_follow_up', 'domain_work_restriction')") === 2;
    }

    public static function checksAllowedSql(Connection $connection, string $alias = 'f'): string
    {
        if (!self::available($connection)) return '1 = 1';
        return "NOT EXISTS (SELECT 1 FROM finding_follow_up fw WHERE fw.finding_id = $alias.id AND (fw.pursuit = 'closed' OR fw.checks_blocked = 1))"
            ." AND NOT EXISTS (SELECT 1 FROM domain_work_restriction dw INNER JOIN domain wd ON LOWER(wd.hostname) = dw.hostname WHERE wd.id = $alias.domain_id AND dw.checks_blocked = 1)";
    }

    public static function pursuitActiveSql(Connection $connection, string $alias = 'f'): string
    {
        return self::available($connection) ? "NOT EXISTS (SELECT 1 FROM finding_follow_up fw WHERE fw.finding_id = $alias.id AND fw.pursuit = 'closed')" : '1 = 1';
    }

    public static function contactAllowedSql(Connection $connection, string $alias = 'f'): string
    {
        if (!self::available($connection)) return '1 = 1';
        return "NOT EXISTS (SELECT 1 FROM finding_follow_up fw WHERE fw.finding_id = $alias.id AND (fw.pursuit = 'closed' OR fw.contact_blocked = 1))"
            ." AND NOT EXISTS (SELECT 1 FROM domain_work_restriction dw INNER JOIN domain wd ON LOWER(wd.hostname) = dw.hostname WHERE wd.id = $alias.domain_id AND dw.contact_blocked = 1)";
    }

    public function checksAllowed(Finding $finding): bool
    {
        if (!self::available($this->connection)) return true;
        $case = $this->connection->fetchAssociative('SELECT pursuit, checks_blocked FROM finding_follow_up WHERE finding_id = ?', [$finding->getId()]);
        return !($case && ($case['pursuit'] === 'closed' || (bool) $case['checks_blocked']))
            && !(bool) $this->connection->fetchOne('SELECT checks_blocked FROM domain_work_restriction WHERE hostname = ?', [strtolower($finding->getDomain()->getHostname())]);
    }

    public function assertChecksAllowed(Finding $finding): void
    {
        if (!$this->checksAllowed($finding)) throw new \LogicException('Weitere Prüfungen sind für diesen Fall gesperrt oder die Nachverfolgung ist beendet.');
    }

    public function assertDiscoveryAllowed(Finding $finding): void
    {
        $this->assertChecksAllowed($finding);
        if (!self::available($this->connection)) throw new \LogicException('Bitte zuerst die Datenbankmigration für Nachverfolgung und Kontakte ausführen.');
        $blocked = $this->connection->fetchOne('SELECT contact_blocked FROM finding_follow_up WHERE finding_id = ?', [$finding->getId()]);
        $domainBlocked = $this->connection->fetchOne('SELECT contact_blocked FROM domain_work_restriction WHERE hostname = ?', [strtolower($finding->getDomain()->getHostname())]);
        if ($blocked || $domainBlocked || $finding->isDiscarded()) throw new \LogicException('Kontaktermittlung ist für diesen Fall gesperrt.');
    }
}
