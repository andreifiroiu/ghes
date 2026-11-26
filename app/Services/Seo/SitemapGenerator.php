<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use XMLWriter;

/**
 * Every URL the site wants indexed, as a sitemap.
 *
 * Two sources: the static routes listed in config, then every upcoming, visible,
 * canonical event. Merged events are excluded by `canonical()` — advertising
 * both a duplicate and its survivor would submit the same page twice under
 * different URLs, which is exactly what the deduplicator exists to prevent.
 *
 * Past events are excluded because `upcoming()` excludes them, and that is the
 * right call even though the rows are kept for analytics: a sitemap is a list of
 * what is worth crawling now, and last month's concert is a page whose only
 * content is a date that has passed.
 *
 * Noindexed paths are filtered out as a belt-and-braces measure. Nothing today
 * can put one in the static list, but a sitemap that advertises a URL carrying
 * `noindex` is a contradiction Search Console reports as an error.
 *
 * Rendered with XMLWriter rather than a Blade view, so the document cannot start
 * with a stray newline before the XML declaration.
 */
class SitemapGenerator
{
    public const string CACHE_KEY = 'seo.sitemap.xml';

    public const string NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * The rendered document, cached for the configured window.
     *
     * Cached rather than regenerated per request because a crawler fetches this
     * far more often than the scrapers change its contents, and the window is
     * set to the busiest source's interval.
     */
    public function cached(): string
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('eventpulse.seo.sitemap.cache_seconds', 3600),
            fn (): string => $this->render(),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A plain list rather than a Collection: PHPStan's Collection template is
     * not covariant, so an array-shaped TValue cannot be returned from a method
     * that declares it.
     *
     * @return list<array{loc: string, lastmod: string|null, changefreq: string}>
     */
    public function urls(): array
    {
        $urls = [];

        foreach ([...$this->staticUrls(), ...$this->eventUrls()] as $url) {
            if ($this->isNoindexed($url['loc'])) {
                continue;
            }

            // Keyed by loc, so a URL that is both a static route and a database
            // row appears once and keeps the later entry's lastmod.
            $urls[$url['loc']] = $url;
        }

        return array_values($urls);
    }

    public function render(): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNs(null, 'urlset', self::NAMESPACE);

        foreach ($this->urls() as $url) {
            $writer->startElement('url');
            $writer->writeElement('loc', $url['loc']);

            if ($url['lastmod'] !== null) {
                $writer->writeElement('lastmod', $url['lastmod']);
            }

            $writer->writeElement('changefreq', $url['changefreq']);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @return list<array{loc: string, lastmod: string|null, changefreq: string}>
     */
    private function staticUrls(): array
    {
        $urls = [];

        /** @var array<string, string> $static */
        $static = config('eventpulse.seo.sitemap.static', []);

        foreach ($static as $path => $changefreq) {
            $urls[] = ['loc' => url($path), 'lastmod' => null, 'changefreq' => $changefreq];
        }

        return $urls;
    }

    /**
     * Event detail pages.
     *
     * `changefreq` is monthly rather than the weekly a listing site reaches for:
     * an event page changes when a scraper re-reads the listing and finds a
     * different price or venue, which is rare once the row has settled. Telling
     * a crawler it changes weekly when it does not is how a crawl budget gets
     * spent on unchanged pages.
     *
     * @return list<array{loc: string, lastmod: string|null, changefreq: string}>
     */
    private function eventUrls(): array
    {
        return Event::query()
            ->upcoming()
            ->visible()
            ->canonical()
            ->orderBy('starts_at')
            ->limit((int) config('eventpulse.seo.sitemap.max_events', 20000))
            ->get(['id', 'updated_at'])
            ->map(static fn (Event $event): array => [
                'loc' => route('events.show', $event),
                'lastmod' => $event->updated_at?->toAtomString(),
                'changefreq' => 'monthly',
            ])
            ->all();
    }

    private function isNoindexed(string $loc): bool
    {
        $path = trim((string) parse_url($loc, PHP_URL_PATH), '/');

        /** @var list<string> $patterns */
        $patterns = config('eventpulse.seo.noindex', []);

        return $path !== '' && Str::is($patterns, $path);
    }
}
