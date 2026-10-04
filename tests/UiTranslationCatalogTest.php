<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class UiTranslationCatalogTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    public function testGermanAndEnglishCatalogsExposeTheSameNonEmptyStringKeys(): void
    {
        $german = $this->catalog('de');
        $english = $this->catalog('en');

        self::assertSame([], array_keys(array_diff_key($german, $english)), 'Keys missing from the English UI catalog.');
        self::assertSame([], array_keys(array_diff_key($english, $german)), 'Keys missing from the German UI catalog.');

        foreach (['de' => $german, 'en' => $english] as $locale => $messages) {
            foreach ($messages as $key => $message) {
                self::assertIsString($key, sprintf('The %s catalog contains a non-string key.', $locale));
                self::assertNotSame('', $key, sprintf('The %s catalog contains an empty key.', $locale));
                self::assertIsString($message, sprintf('Translation %s in the %s catalog is not a string.', $key, $locale));
                self::assertNotSame('', $message, sprintf('Translation %s in the %s catalog is empty.', $key, $locale));
            }
        }
    }

    public function testEveryLiteralServerAndBrowserTranslationKeyExistsInBothCatalogs(): void
    {
        $usages = [];
        foreach ($this->files(['src', 'templates'], 'php') as $file) {
            foreach ($this->phpLiteralCalls($file) as [$key, $line]) {
                $usages[$key][] = $this->relativePath($file).':'.$line;
            }
        }
        foreach ($this->files(['public/js'], 'js') as $file) {
            foreach ($this->javascriptLiteralCalls($file) as [$key, $line]) {
                $usages[$key][] = $this->relativePath($file).':'.$line;
            }
        }
        ksort($usages);

        self::assertNotEmpty($usages, 'No literal UI translation calls were discovered.');
        foreach (['de', 'en'] as $locale) {
            $catalog = $this->catalog($locale);
            $missing = [];
            foreach ($usages as $key => $locations) {
                if (!array_key_exists($key, $catalog)) {
                    $missing[] = sprintf('%s (%s)', var_export($key, true), implode(', ', $locations));
                }
            }

            self::assertSame(
                [],
                $missing,
                sprintf("Literal UI translation keys missing from translations/ui.%s.php:\n%s", $locale, implode("\n", $missing)),
            );
        }
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $catalog = require self::ROOT.'/translations/ui.'.$locale.'.php';
        self::assertIsArray($catalog);

        return $catalog;
    }

    /** @param list<string> $directories
     *  @return list<string>
     */
    private function files(array $directories, string $extension): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                self::ROOT.'/'.$directory,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<array{string, int}> */
    private function phpLiteralCalls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $calls = [];
        foreach ($tokens as $index => $token) {
            $open = null;
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$t') {
                $open = $this->nextSignificantToken($tokens, $index + 1);
            } elseif (is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $method = $this->nextSignificantToken($tokens, $index + 1);
                if ($method !== null && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING && strtolower($tokens[$method][1]) === 'trans') {
                    $open = $this->nextSignificantToken($tokens, $method + 1);
                }
            }
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $literal = $this->nextSignificantToken($tokens, $open + 1);
            if ($literal === null || !is_array($tokens[$literal]) || $tokens[$literal][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $afterLiteral = $this->nextSignificantToken($tokens, $literal + 1);
            if ($afterLiteral === null || !in_array($tokens[$afterLiteral], [',', ')'], true)) {
                continue;
            }
            $calls[] = [$this->decodePhpString($tokens[$literal][1]), $tokens[$literal][2]];
        }

        return $calls;
    }

    /** @param list<array|string> $tokens */
    private function nextSignificantToken(array $tokens, int $index): ?int
    {
        for ($count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    private function decodePhpString(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return preg_replace_callback('/\\\\([\\\\\'])/', static fn (array $match): string => $match[1], $body) ?? $body;
        }

        return $this->decodeEscapes($body);
    }

    /** @return list<array{string, int}> */
    private function javascriptLiteralCalls(string $file): array
    {
        $source = (string) file_get_contents($file);
        $tokens = $this->javascriptTokens($source);
        $calls = [];
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== 'identifier' || $token['value'] !== 't'
                || (($tokens[$index - 1]['value'] ?? null) === '.')
                || (($tokens[$index - 1]['value'] ?? null) === '?.')
                || (($tokens[$index + 1]['value'] ?? null) !== '(')
            ) {
                continue;
            }
            $literal = $tokens[$index + 2] ?? null;
            $afterLiteral = $tokens[$index + 3]['value'] ?? null;
            if (($literal['type'] ?? null) !== 'string' || !in_array($afterLiteral, [',', ')'], true)) {
                continue;
            }
            $calls[] = [$literal['value'], substr_count($source, "\n", 0, $literal['offset']) + 1];
        }

        return $calls;
    }

    /** @return list<array{type: string, value: string, offset: int}> */
    private function javascriptTokens(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            $character = $source[$offset];
            if (ctype_space($character)) {
                ++$offset;
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '/') {
                $newline = strpos($source, "\n", $offset + 2);
                $offset = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                [$end, $body, $dynamic] = $this->javascriptString($source, $offset, $character);
                $tokens[] = [
                    'type' => $dynamic ? 'dynamic-string' : 'string',
                    'value' => $dynamic ? '' : $this->decodeEscapes($body),
                    'offset' => $offset,
                ];
                $offset = $end;
                continue;
            }
            if (preg_match('/[A-Za-z_$]/', $character) === 1) {
                $end = $offset + 1;
                while ($end < $length && preg_match('/[A-Za-z0-9_$]/', $source[$end]) === 1) {
                    ++$end;
                }
                $tokens[] = ['type' => 'identifier', 'value' => substr($source, $offset, $end - $offset), 'offset' => $offset];
                $offset = $end;
                continue;
            }
            if ($character === '?' && ($source[$offset + 1] ?? '') === '.') {
                $tokens[] = ['type' => 'punctuation', 'value' => '?.', 'offset' => $offset];
                $offset += 2;
                continue;
            }
            $tokens[] = ['type' => 'punctuation', 'value' => $character, 'offset' => $offset];
            ++$offset;
        }

        return $tokens;
    }

    /** @return array{int, string, bool} */
    private function javascriptString(string $source, int $start, string $quote): array
    {
        $length = strlen($source);
        $dynamic = false;
        for ($offset = $start + 1; $offset < $length; ++$offset) {
            if ($source[$offset] === '\\') {
                ++$offset;
                continue;
            }
            if ($quote === '`' && $source[$offset] === '$' && ($source[$offset + 1] ?? '') === '{') {
                $dynamic = true;
            }
            if ($source[$offset] === $quote) {
                return [$offset + 1, substr($source, $start + 1, $offset - $start - 1), $dynamic];
            }
        }

        return [$length, substr($source, $start + 1), true];
    }

    private function decodeEscapes(string $body): string
    {
        return preg_replace_callback('/\\\\(?:u\{([0-9a-fA-F]+)\}|u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2})|([0btnvfre\\\\\'"$]))/', static function (array $match): string {
            if (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '') {
                $codepoint = hexdec(($match[1] ?? '') !== '' ? $match[1] : $match[2]);

                return mb_chr($codepoint, 'UTF-8');
            }
            if (($match[3] ?? '') !== '') {
                return chr(hexdec($match[3]));
            }

            return match ($match[4]) {
                '0' => "\0",
                'b' => "\x08",
                't' => "\t",
                'n' => "\n",
                'v' => "\x0b",
                'f' => "\f",
                'r' => "\r",
                'e' => "\x1b",
                default => $match[4],
            };
        }, $body) ?? $body;
    }

    private function relativePath(string $file): string
    {
        return ltrim(str_replace(realpath(self::ROOT).DIRECTORY_SEPARATOR, '', realpath($file)), DIRECTORY_SEPARATOR);
    }
}
