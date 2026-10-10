<?php
namespace App\Service;

use App\Dto\ContactDiscoveryResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Internal extension point: sources only, no Finding, secrets or write/dispatch API. */
#[AutoconfigureTag('app.contact_discovery_provider')]
interface ContactDiscoveryProviderInterface
{
    public function id(): string;
    public function label(): string;
    public function discover(string $hostname): ContactDiscoveryResult;
}
