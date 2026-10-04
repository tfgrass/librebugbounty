<?php

namespace App\Service;

use App\AppInfo;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class DocumentationService
{
    private const PAGES = [
        'usage' => ['file' => 'USAGE.md', 'title' => 'Benutzerhandbuch'],
        'readme' => ['file' => 'README.md', 'title' => 'Installation & Upgrade'],
        'backup' => ['file' => 'BACKUP.md', 'title' => 'Sicherung & Wiederherstellung'],
        'changelog' => ['file' => 'CHANGELOG.md', 'title' => 'Changelog'],
        'roadmap' => ['file' => 'ROADMAP.md', 'title' => 'Roadmap'],
    ];

    private const IMAGES = [
        'review.png', 'inventory.png', 'finding-detail.png', 'statistics.png',
        'intake.png', 'export.png', 'about.png',
    ];

    private readonly MarkdownConverter $markdown;
    private readonly HtmlSanitizer $sanitizer;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $this->markdown = new MarkdownConverter($environment);

        // The README's image gallery uses HTML. Retain document markup without
        // permitting scripts, forms, embedded documents, or CSS from Markdown.
        $config = (new HtmlSanitizerConfig())
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes([])
            ->withMaxInputLength(1_000_000);
        foreach (['p', 'br', 'hr', 'strong', 'em', 'del', 'blockquote', 'ul', 'li', 'pre', 'table', 'thead', 'tbody', 'tr'] as $element) {
            $config = $config->allowElement($element);
        }
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $element) {
            $config = $config->allowElement($element, ['id']);
        }
        $config = $config
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('code', ['class'])
            ->allowElement('ol', ['start'])
            ->allowElement('th', ['align'])
            ->allowElement('td', ['align']);
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /** @return list<array{slug: string, title: string}> */
    public function pages(): array
    {
        $pages = [];
        foreach (self::PAGES as $slug => $page) {
            $pages[] = ['slug' => $slug, 'title' => $page['title']];
        }

        return $pages;
    }

    /** @return array{slug: string, title: string, html: string}|null */
    public function page(string $slug): ?array
    {
        $page = self::PAGES[$slug] ?? null;
        if ($page === null || ($path = $this->catalogFile($page['file'])) === null) {
            return null;
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException('The documentation file could not be read.');
        }

        return [
            'slug' => $slug,
            'title' => $page['title'],
            'html' => $this->localLinks($this->sanitizer->sanitize((string) $this->markdown->convert($source))),
        ];
    }

    public function imagePath(string $image): ?string
    {
        if (!in_array($image, self::IMAGES, true)) {
            return null;
        }

        return $this->catalogFile('docs/screenshots/'.$image);
    }

    private function catalogFile(string $relative): ?string
    {
        $root = realpath($this->projectDir);
        if ($root === false) {
            return null;
        }
        $path = $root.'/'.$relative;

        // Catalog entries are shipped files, never aliases to private files.
        return is_file($path) && realpath($path) === $path ? $path : null;
    }

    private function localLinks(string $html): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($document->getElementsByTagName('a') as $link) {
            $href = $this->linkTarget($link->getAttribute('href'));
            if ($href === null) {
                $link->removeAttribute('href');
                continue;
            }
            $link->setAttribute('href', $href);
            if (preg_match('~^https?://~i', $href)) {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer');
            }
        }
        // Even future documentation cannot cause requests for remote images.
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            $src = $this->imageTarget($image->getAttribute('src'));
            if ($src === null) {
                $image->parentNode?->removeChild($image);
                continue;
            }
            $image->setAttribute('src', $src);
            $image->setAttribute('loading', 'lazy');
            $path = $this->imagePath(substr($src, strlen('/docs/images/')));
            $size = $path === null ? false : getimagesize($path);
            if ($size !== false) {
                // Reserve the image's aspect ratio before lazy loading so that
                // following a heading fragment remains stable as images arrive.
                if ($image->getAttribute('width') === '32%') {
                    $image->setAttribute('class', 'studio-doc-image-thumbnail');
                }
                $image->setAttribute('width', (string) $size[0]);
                $image->setAttribute('height', (string) $size[1]);
            }
        }
        $html = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $html .= $document->saveHTML($node);
        }

        return $html;
    }

    private function linkTarget(string $href): ?string
    {
        if (str_starts_with($href, '#')) {
            return $href;
        }
        if (preg_match('~^(?:https?://|mailto:)~i', $href)) {
            return $href;
        }
        [$path, $fragment] = array_pad(explode('#', $href, 2), 2, null);
        $suffix = $fragment === null ? '' : '#'.$fragment;
        foreach (self::PAGES as $slug => $page) {
            if (in_array($path, [$page['file'], './'.$page['file'], '/docs/'.$slug], true)) {
                return '/docs/'.$slug.$suffix;
            }
        }
        if (in_array($path, ['LICENSE', './LICENSE'], true)) {
            return AppInfo::REPOSITORY.'/blob/HEAD/LICENSE'.$suffix;
        }
        $image = $this->imageTarget($path);

        return $image === null ? null : $image.$suffix;
    }

    private function imageTarget(string $path): ?string
    {
        foreach (self::IMAGES as $image) {
            if (in_array($path, ['docs/screenshots/'.$image, './docs/screenshots/'.$image, '/docs/images/'.$image], true)) {
                return '/docs/images/'.$image;
            }
        }

        return null;
    }
}
