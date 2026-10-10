<?php
namespace App\Tests\Support;

use App\Dto\ContactDiscoveryResult;
use App\Service\ContactDiscoveryProviderInterface;

/** Synthetic local results only. Never creates an HTTP client. */
final class StudioContactFixture implements ContactDiscoveryProviderInterface
{
    public function id(): string { return 'fixture'; }
    public function label(): string { return 'security.txt (fixture)'; }
    public function discover(string $hostname): ContactDiscoveryResult
    {
        if ($hostname !== 'diagnostics.invalid') throw new \LogicException('Unexpected fixture host');
        return new ContactDiscoveryResult('found', 'https://diagnostics.invalid/.well-known/security.txt',
            [['channel' => 'email', 'value' => 'mailto:security@diagnostics.invalid'], ['channel' => 'web', 'value' => 'https://diagnostics.invalid/report']],
            ['https://diagnostics.invalid/disclosure'], ['de', 'en'], '2099-10-10T00:00:00Z');
    }
}
