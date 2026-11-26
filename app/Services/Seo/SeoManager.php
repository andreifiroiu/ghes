<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\DTOs\SeoData;
use Illuminate\Support\Str;

/**
 * Collects the metadata for the current page and renders it into <head>.
 *
 * Ghes has no SSR, so React's <Head> reaches Googlebot's rendered pass and
 * nothing else — the AI answer engines that matter for AEO (GPTBot, ClaudeBot,
 * PerplexityBot) do not execute JavaScript. Inertia renders the root Blade view
 * on every full page load and skips it entirely on a client-side visit, which
 * is exactly the split we want: crawlers always arrive with a full load, so
 * server-rendered tags reach all of them for the price of no extra process.
 *
 * The lifecycle is one request. A controller calls the setters, Inertia renders
 * the root view *after* the controller returns, and `app.blade.php` calls
 * `render()`. `SeoDefaults` forgets the container instance at the top of every
 * request — not a theoretical tidiness: the Pest client serves many requests
 * from one container, and page two would otherwise inherit page one's title.
 *
 * The tags this writes are not marked with Inertia's `inertia` attribute, so
 * they survive a client-side navigation and go stale in the live DOM. That is
 * accepted: search and social scrapers refetch the URL server-side and never
 * inherit a navigated DOM, and React's <Head> keeps the visible title correct.
 */
class SeoManager
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $canonical = null;

    private ?string $image = null;

    private ?string $type = null;

    /** @var list<string> */
    private array $robots = [];

    /**
     * JSON-LD nodes keyed by name, so a second call for the same node replaces
     * it rather than emitting the page's entity twice.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $jsonLd = [];

    public function title(?string $title): self
    {
        $this->title = $this->clean($title);

        return $this;
    }

    public function description(?string $description): self
    {
        $description = $this->clean($description === null ? null : strip_tags($description));

        $this->description = $description === null ? null : Str::limit($description, 300);

        return $this;
    }

    public function canonical(?string $canonical): self
    {
        $this->canonical = $canonical;

        return $this;
    }

    public function image(?string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @param  list<string>  $directives
     */
    public function robots(array $directives): self
    {
        $this->robots = $directives;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function jsonLd(string $name, array $node): self
    {
        $this->jsonLd[$name] = $node;

        return $this;
    }

    /**
     * Everything the page asked for, layered over the config defaults.
     *
     * The title suffix is appended only to a page's own title: the default is
     * already a full brand string, and a page that has set a title equal to it
     * (the landing page does) must not end up "Ghes — evenimente · Ghes".
     */
    public function resolve(): SeoData
    {
        /** @var array<string, string> $defaults */
        $defaults = config('eventpulse.seo.defaults');

        $title = $this->title ?? $defaults['title'];

        if ($this->title !== null && $this->title !== $defaults['title']) {
            $title .= $defaults['title_suffix'];
        }

        return new SeoData(
            title: $title,
            description: $this->description ?? $defaults['description'],
            canonical: $this->canonical ?? url()->current(),
            image: $this->absoluteImage($this->image ?? $defaults['image']),
            robots: $this->robots,
            locale: $defaults['locale'],
            type: $this->type ?? 'website',
        );
    }

    /**
     * The whole <head> contribution, as escaped HTML.
     *
     * Returned as one string for the Blade view to echo unescaped; every value
     * inside it has been through `e()` first. JSON-LD is encoded with the
     * unescaped-slashes and unescaped-unicode flags so Romanian diacritics stay
     * readable and URLs do not come out full of backslashes, then run through
     * the `</` guard that stops a title containing a literal `</script>` from
     * closing the block early.
     */
    public function render(): string
    {
        $seo = $this->resolve();
        $lines = [];

        $lines[] = sprintf('<meta name="description" content="%s">', e((string) $seo->description));
        $lines[] = sprintf('<link rel="canonical" href="%s">', e((string) $seo->canonical));

        if ($seo->robots !== []) {
            $lines[] = sprintf('<meta name="robots" content="%s">', e(implode(', ', $seo->robots)));
        }

        $lines[] = sprintf('<meta property="og:type" content="%s">', e($seo->type));
        $lines[] = sprintf('<meta property="og:site_name" content="%s">', e((string) config('app.name')));
        $lines[] = sprintf('<meta property="og:title" content="%s">', e($seo->title));
        $lines[] = sprintf('<meta property="og:description" content="%s">', e((string) $seo->description));
        $lines[] = sprintf('<meta property="og:url" content="%s">', e((string) $seo->canonical));
        $lines[] = sprintf('<meta property="og:locale" content="%s">', e($seo->locale));

        if ($seo->image !== null) {
            $lines[] = sprintf('<meta property="og:image" content="%s">', e($seo->image));
        }

        /** @var array<string, string> $defaults */
        $defaults = config('eventpulse.seo.defaults');

        $lines[] = sprintf('<meta name="twitter:card" content="%s">', e($defaults['twitter_card']));
        $lines[] = sprintf('<meta name="twitter:title" content="%s">', e($seo->title));
        $lines[] = sprintf('<meta name="twitter:description" content="%s">', e((string) $seo->description));

        if ($seo->image !== null) {
            $lines[] = sprintf('<meta name="twitter:image" content="%s">', e($seo->image));
        }

        foreach ($this->jsonLd as $node) {
            $lines[] = sprintf(
                '<script type="application/ld+json">%s</script>',
                str_replace('</', '<\/', (string) json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            );
        }

        return implode("\n        ", $lines);
    }

    /**
     * The title as the <title> tag should carry it, already escaped.
     *
     * Separate from render() because it replaces the root view's own <title>
     * rather than being appended to the head.
     */
    public function titleTag(): string
    {
        return e($this->resolve()->title);
    }

    /**
     * An og:image must be absolute — a relative path is silently dropped by
     * every scraper that reads it.
     */
    private function absoluteImage(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }

        if (Str::startsWith($image, ['http://', 'https://'])) {
            return $image;
        }

        return url($image);
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::squish($value);

        return $value === '' ? null : $value;
    }
}
