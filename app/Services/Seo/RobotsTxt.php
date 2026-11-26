<?php

declare(strict_types=1);

namespace App\Services\Seo;

use Illuminate\Support\Str;

/**
 * Builds the body of /robots.txt from the environment and
 * `config/eventpulse.php`.
 *
 * Pure on purpose: the same text is served by the /robots.txt route and written
 * to public/robots.txt by `seo:publish-robots`, and a test pins that the two
 * never drift.
 *
 * Two outcomes. The real production host publishes the site; every other host —
 * production-env or not — disallows everything, because a staging box that is
 * reachable is a staging box that gets indexed. The host is parsed out of
 * APP_URL and matched against an allow-list rather than a prefix, so a lookalike
 * domain cannot open the crawl by accident.
 *
 * Fail-closed is deliberate, and it has teeth once the deploy writes a real
 * file: a misspelt APP_URL on production would de-index the site behind a green
 * deploy log, so the command that writes it shouts when that happens.
 */
final class RobotsTxt
{
    /**
     * @param  array{public_hosts: list<string>, disallow: list<string>, ai_crawlers: array{allow: list<string>, block: list<string>}}  $config
     */
    public function __construct(
        private readonly string $environment,
        private readonly string $appUrl,
        private readonly array $config,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{public_hosts: list<string>, disallow: list<string>, ai_crawlers: array{allow: list<string>, block: list<string>}} $robots */
        $robots = config('eventpulse.seo.robots');

        return new self(
            (string) config('app.env'),
            (string) config('app.url'),
            $robots,
        );
    }

    /**
     * Whether this host is the one that should be indexed at all.
     */
    public function isPublicHost(): bool
    {
        if ($this->environment !== 'production') {
            return false;
        }

        return in_array($this->host(), $this->config['public_hosts'], true);
    }

    public function host(): string
    {
        return Str::of($this->appUrl)
            ->after('://')
            ->before('/')
            ->lower()
            ->toString();
    }

    public function build(): string
    {
        if (! $this->isPublicHost()) {
            return implode("\n", ['User-agent: *', 'Disallow: /']);
        }

        $disallow = array_map(
            static fn (string $path): string => 'Disallow: '.$path,
            $this->config['disallow'],
        );

        $lines = [
            'User-agent: *',
            ...$disallow,
            '',
            // Answer and search engines that cite their sources and send readers
            // back. For a free event guide that is distribution, not leakage, so
            // they get the same access as Googlebot.
            ...$this->userAgentLines($this->config['ai_crawlers']['allow']),
            ...$disallow,
            '',
            // Training-only collectors, which return nothing.
            ...$this->userAgentLines($this->config['ai_crawlers']['block']),
            'Disallow: /',
            '',
            'Sitemap: '.rtrim($this->appUrl, '/').'/sitemap.xml',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $agents
     * @return list<string>
     */
    private function userAgentLines(array $agents): array
    {
        return array_map(
            static fn (string $agent): string => 'User-agent: '.$agent,
            $agents,
        );
    }

    /**
     * The exact bytes `seo:publish-robots` writes, so the command, the route and
     * the drift check cannot disagree about the trailing newline.
     */
    public function file(): string
    {
        return $this->build()."\n";
    }
}
