<?php

namespace App\Tests;

use App\AppInfo;
use App\Service\DocumentationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DocumentationServiceTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = APP_TEST_ROOT.'/documentation-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureDir, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->fixtureDir);
    }

    public function testShippedDocumentsRenderTablesUnicodeAndAllLocalReferences(): void
    {
        $documentation = new DocumentationService(dirname(__DIR__));
        $pages = [];
        foreach ($documentation->pages() as $entry) {
            $page = $documentation->page($entry['slug']);
            self::assertNotNull($page);
            self::assertSame($entry['title'], $page['title']);
            $pages[$entry['slug']] = $this->xpath($page['html']);
            self::assertSame(1, $pages[$entry['slug']]->query('//h1')->length);
        }
        self::assertSame(['usage', 'readme', 'backup', 'changelog', 'roadmap'], array_keys($pages));
        self::assertGreaterThan(0, $pages['usage']->query('//table//th')->length);
        self::assertStringContainsString('Moneta', $pages['readme']->evaluate('string(//h1)'));
        self::assertStringContainsString('Tom Graßmann', $pages['readme']->evaluate('string(//body)'));
        self::assertSame(5, $pages['readme']->query('//img[starts-with(@src,"/docs/images/")]')->length);
        self::assertSame(7, $pages['readme']->query('//a[starts-with(@href,"/docs/images/")]')->length);
        self::assertSame(0, $pages['readme']->query('//img[@class="studio-doc-image-thumbnail"]')->length);
        foreach ($pages['readme']->query('//img') as $image) {
            $path = $documentation->imagePath(substr($image->getAttribute('src'), strlen('/docs/images/')));
            $size = getimagesize($path);
            self::assertNotFalse($size);
            self::assertSame((string) $size[0], $image->getAttribute('width'));
            self::assertSame((string) $size[1], $image->getAttribute('height'));
        }
        self::assertSame(1, $pages['readme']->query('//a[@href="'.AppInfo::REPOSITORY.'/blob/HEAD/LICENSE"]')->length);

        foreach ($pages as $xpath) {
            foreach ($xpath->query('//a[@href]') as $link) {
                $target = $link->getAttribute('href');
                if (str_starts_with($target, '/docs/images/')) {
                    self::assertFileExists($documentation->imagePath(substr($target, strlen('/docs/images/'))));
                } elseif (str_starts_with($target, '/docs/')) {
                    [$slug, $anchor] = array_pad(explode('#', substr($target, strlen('/docs/')), 2), 2, null);
                    self::assertArrayHasKey($slug, $pages, $target);
                    if ($anchor !== null) {
                        self::assertSame(1, $pages[$slug]->query('//*[@id="'.$anchor.'"]')->length, $target);
                    }
                } else {
                    self::assertMatchesRegularExpression('~^(https?://|mailto:|#)~', $target);
                    if (str_starts_with($target, 'http')) {
                        self::assertSame('_blank', $link->getAttribute('target'));
                        self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
                    }
                }
            }
        }
    }

    public function testRendererRetainsSafeGalleryAndAnchorsButDropsActiveAndPrivateContent(): void
    {
        file_put_contents($this->fixtureDir.'/README.md', <<<'MARKDOWN'
# Safe café

[Guide](./USAGE.md#manual-review)
[Section](#safe-caf%C3%A9)
[External](https://example.org/documentation)
[Private](architecture/design.md)
[Database](storage/database/app.sqlite)
[Unsafe](javascript:alert(1))

<p><a href="docs/screenshots/review.png"><img src="docs/screenshots/review.png" alt="Review café" width="100%" onerror="alert(1)"></a></p>

<a href="javascript:alert(2)" onclick="alert(3)">Unsafe HTML link</a>
<img src="https://example.org/tracker.png" alt="Remote tracking image">
<img src="//example.org/tracker.png" alt="Protocol-relative tracking image">
<img src="data:image/svg+xml,anything" alt="Inline image">
<img src="architecture/design.md" alt="Private image">
<iframe src="https://example.org/frame"></iframe>
<form action="/settings"><input name="default_payload"></form>
<script>alert(4)</script>
<div style="position:fixed" onclick="alert(5)">Active styling</div>

| Name | Value |
| --- | --- |
| Café | **Readable** |

```html
<script>This is a documented code example.</script>
```
MARKDOWN);
        $page = (new DocumentationService($this->fixtureDir))->page('readme');
        self::assertNotNull($page);
        $xpath = $this->xpath($page['html']);
        self::assertSame('Safe café', $xpath->evaluate('string(//h1)'));
        self::assertSame(1, $xpath->query('//a[@href="/docs/usage#manual-review"]')->length);
        self::assertSame(1, $xpath->query('//a[@href="#safe-caf%C3%A9"]')->length);
        self::assertSame(1, $xpath->query('//a[@href="https://example.org/documentation" and @target="_blank" and @rel="noopener noreferrer"]')->length);
        self::assertSame(1, $xpath->query('//img')->length);
        self::assertSame('/docs/images/review.png', $xpath->evaluate('string(//img/@src)'));
        self::assertSame('Review café', $xpath->evaluate('string(//img/@alt)'));
        self::assertSame('100%', $xpath->evaluate('string(//img/@width)'));
        self::assertSame(0, $xpath->query('//script|//iframe|//form|//input|//*[@style]|//*[@onclick]|//*[@onerror]')->length);
        self::assertSame(0, $xpath->query('//a[starts-with(@href,"javascript:") or contains(@href,"architecture") or contains(@href,"storage")]')->length);
        self::assertSame(1, $xpath->query('//table//strong')->length);
        self::assertStringContainsString('<script>This is a documented code example.</script>', $xpath->evaluate('string(//pre/code)'));
    }

    public function testCatalogNeverResolvesUnknownFilesOrSymlinksToPrivateFiles(): void
    {
        mkdir($this->fixtureDir.'/architecture');
        file_put_contents($this->fixtureDir.'/architecture/design.md', 'PRIVATE ARCHITECTURE');
        $documentation = new DocumentationService($this->fixtureDir);
        foreach (['architecture', 'architecture/design.md', '../README.md', 'README.md', '/etc/passwd', 'usage/../readme', 'readme%00'] as $slug) {
            self::assertNull($documentation->page($slug), $slug);
        }
        foreach (['../../architecture/design.md', 'design.md', 'architecture.png', '../review.png', 'review.png/../design.md'] as $image) {
            self::assertNull($documentation->imagePath($image), $image);
        }
        symlink($this->fixtureDir.'/architecture/design.md', $this->fixtureDir.'/README.md');
        self::assertNull($documentation->page('readme'));
        mkdir($this->fixtureDir.'/docs/screenshots', 0700, true);
        symlink($this->fixtureDir.'/architecture/design.md', $this->fixtureDir.'/docs/screenshots/review.png');
        self::assertNull($documentation->imagePath('review.png'));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }
}
