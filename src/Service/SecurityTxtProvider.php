<?php
namespace App\Service;

use App\Dto\ContactDiscoveryResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Only the two published security.txt locations, HTTPS, no redirects/crawling. */
final class SecurityTxtProvider implements ContactDiscoveryProviderInterface
{
    public function __construct(private readonly HttpClientInterface $client) {}
    public function id(): string { return 'security_txt'; }
    public function label(): string { return 'security.txt'; }

    public function discover(string $hostname): ContactDiscoveryResult
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($hostname, '.')
            || filter_var($hostname, FILTER_VALIDATE_IP) || preg_match('/\.(localhost|local|internal|invalid|test)$/D', $hostname)) {
            return new ContactDiscoveryResult('error', '', warnings: ['Nur öffentliche DNS-Hostnamen werden abgefragt.']);
        }
        foreach (['/.well-known/security.txt', '/security.txt'] as $path) {
            $url = 'https://'.$hostname.$path;
            $response = null;
            try {
                $response = $this->client->request('GET', $url, ['timeout' => 4, 'max_duration' => 6, 'max_redirects' => 0,
                    'headers' => ['Accept' => 'text/plain', 'User-Agent' => 'LibreBugBounty security.txt contact discovery'], 'buffer' => false,
                    'on_progress' => static function (int $downloaded, int $size): void { if (max($downloaded, $size) > 65536) throw new \RuntimeException('Response too large.'); }]);
                $status = $response->getStatusCode();
                if (in_array($status, [404, 410], true)) continue;
                if ($status >= 300 && $status < 400) return new ContactDiscoveryResult('error', $url, warnings: ['Weiterleitungen werden nicht automatisch verfolgt.']);
                if ($status !== 200) return new ContactDiscoveryResult('error', $url, warnings: ['Kontaktdaten konnten nicht abgerufen werden.']);
                $headers = $response->getHeaders(false);
                $contentType = strtolower(explode(';', $headers['content-type'][0] ?? '')[0]);
                if ($contentType !== 'text/plain') return new ContactDiscoveryResult('invalid', $url, warnings: ['Die Antwort ist keine Textdatei.']);
                $body = '';
                foreach ($this->client->stream($response, 4) as $chunk) {
                    if ($chunk->isTimeout()) throw new \RuntimeException('Timeout');
                    $body .= $chunk->getContent();
                    if (strlen($body) > 65536) throw new \RuntimeException('Response too large.');
                }
                return $this->parse($body, $url);
            } catch (\Throwable) {
                return new ContactDiscoveryResult('error', $url, warnings: ['Kontaktdaten konnten nicht abgerufen werden.']);
            } finally {
                $response?->cancel();
            }
        }
        return new ContactDiscoveryResult('not_found', 'https://'.$hostname.'/.well-known/security.txt');
    }

    public function parse(string $body, string $source, ?\DateTimeImmutable $now = null): ContactDiscoveryResult
    {
        $now ??= new \DateTimeImmutable();
        if (strlen($body) > 65536 || !mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) return new ContactDiscoveryResult('invalid', $source);
        // Do not present an unverified signature as an authenticated document.
        if (str_contains($body, '-----BEGIN PGP')) return new ContactDiscoveryResult('invalid', $source, warnings: ['Signierte security.txt-Dateien werden derzeit nicht ausgewertet.']);
        $contacts = $policies = $languages = $expiresValues = $canonicals = [];
        $warnings = [];
        foreach (preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $body)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^([A-Za-z-]+):\s*(.*?)\s*$/D', $line, $match)) continue;
            [$unused, $field, $value] = $match;
            if (preg_match('/[\x00-\x20\x7f]/', $value) && !in_array(strtolower($field), ['preferred-languages'], true)) continue;
            switch (strtolower($field)) {
                case 'contact':
                    if (str_starts_with(strtolower($value), 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL)) $contacts[$value] = ['channel' => 'email', 'value' => $value];
                    elseif ($this->httpsUrl($value)) $contacts[$value] = ['channel' => 'web', 'value' => $value];
                    else $warnings[] = 'Nicht unterstützter Kontaktweg wurde ausgelassen.';
                    break;
                case 'policy': if ($this->httpsUrl($value)) $policies[$value] = $value; break;
                case 'canonical': $canonicals[] = $value; break;
                case 'expires': $expiresValues[] = $value; break;
                case 'preferred-languages':
                    foreach (explode(',', $value) as $language) {
                        $language = trim($language);
                        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $language)) $languages[$language] = $language;
                    }
                    break;
            }
        }
        $expiry = null;
        if (count($expiresValues) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expiresValues[0])) {
            try { $candidate = new \DateTimeImmutable($expiresValues[0]); $errors = \DateTimeImmutable::getLastErrors(); if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) $expiry = $candidate; } catch (\Exception) {}
        }
        $status = 'found';
        if ($contacts === [] || $expiry === null || ($canonicals !== [] && !in_array($source, $canonicals, true))) {
            $status = 'invalid';
            $warnings[] = 'Kontakt, gültiges Ablaufdatum oder passende kanonische Quelle fehlen.';
        } elseif ($expiry <= $now) {
            $status = 'expired';
            $warnings[] = 'Die veröffentlichten Kontaktdaten sind abgelaufen.';
        }
        return new ContactDiscoveryResult($status, $source, array_values($contacts), array_values($policies), array_values($languages), $expiry?->format(DATE_ATOM), array_values(array_unique($warnings)));
    }

    private function httpsUrl(string $value): bool
    {
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) && $parts !== false && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']);
    }
}
