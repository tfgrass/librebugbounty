<?php

namespace App\Service;

use App\Value\EvidenceKind;
use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\DomainVerificationMethod;
use App\Value\RetestMode;
use App\Value\RetestResult;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ValidationService
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function normalizeHostname(string $hostname): string
    {
        $candidate = trim($hostname);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
            $parts = parse_url($candidate);
            $candidate = $parts['host'] ?? $candidate;
        } elseif (str_contains($candidate, '/')) {
            $candidate = explode('/', $candidate, 2)[0];
        }

        $candidate = strtolower(trim($candidate, ". \t\n\r\0\x0B"));

        if ($candidate === '') {
            throw new \InvalidArgumentException('Hostname must not be empty.');
        }

        if (function_exists('idn_to_ascii')) {
            $converted = idn_to_ascii($candidate, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $candidate = $converted;
            }
        }

        $this->assertHostname($candidate);

        return $candidate;
    }

    public function assertHostname(string $hostname): void
    {
        $this->assertNoViolation($this->validator->validate($hostname, [
            new Assert\NotBlank(),
            new Assert\Length(max: 255),
            new Assert\Regex(
                pattern: '/^(?=.{1,255}$)(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
                message: 'The hostname "{{ value }}" is not valid.',
            ),
        ]));
    }

    public function assertUrl(string $url): void
    {
        $this->assertNoViolation($this->validator->validate($url, [
            new Assert\NotBlank(),
            new Assert\Length(max: 4096),
        ]));
    }

    public function assertScheme(string $scheme): void
    {
        $this->assertChoice(strtolower(trim($scheme)), ['http', 'https'], 'scheme');
    }

    public function assertHttpMethod(string $method): void
    {
        $this->assertChoice(strtoupper(trim($method)), ['GET', 'POST'], 'method');
    }

    public function assertStatus(string $status): void
    {
        $this->assertChoice($status, FindingStatus::values(), 'status');
    }

    public function assertSeverity(string $severity): void
    {
        $this->assertChoice($severity, FindingSeverity::values(), 'severity');
    }

    public function assertVerificationMethod(?string $method): void
    {
        if ($method === null) {
            return;
        }

        $this->assertChoice($method, DomainVerificationMethod::values(), 'verification-method');
    }

    public function assertEvidenceKind(string $kind): void
    {
        $this->assertChoice($kind, EvidenceKind::values(), 'kind');
    }

    public function assertRetestMode(string $mode): void
    {
        $this->assertChoice($mode, RetestMode::values(), 'mode');
    }

    public function assertRetestResult(string $result): void
    {
        $this->assertChoice($result, RetestResult::values(), 'result');
    }

    private function assertChoice(string $value, array $choices, string $label): void
    {
        $this->assertNoViolation($this->validator->validate($value, [
            new Assert\NotBlank(),
            new Assert\Choice(choices: $choices, message: sprintf('The %s "{{ value }}" is not valid.', $label)),
        ]));
    }

    private function assertNoViolation(iterable $violations): void
    {
        $messages = [];

        foreach ($violations as $violation) {
            $messages[] = $violation->getMessage();
        }

        if ($messages !== []) {
            throw new \InvalidArgumentException(implode(' ', $messages));
        }
    }
}
