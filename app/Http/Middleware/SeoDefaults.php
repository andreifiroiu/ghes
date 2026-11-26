<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Seo\SeoManager;
use App\Services\Seo\StructuredData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The metadata every web response gets regardless of controller: the
 * Organization node, and noindex on everything private.
 *
 * A pattern match rather than a route group, because the public and private
 * routes in routes/web.php are interleaved and a group would have to be
 * rearranged around this. The header is set as well as the meta tag: `go/{event}`
 * is a redirect and `calendar.ics` is a download, and neither has a <head> to
 * carry one.
 *
 * The manager is forgotten at the top of every request. Under PHP-FPM that is a
 * no-op, but the Pest client serves many requests from one container, and
 * without it the second page carries the first page's title and JSON-LD — which
 * makes every metadata assertion in the suite pass for the wrong reason.
 */
class SeoDefaults
{
    public function __construct(private readonly StructuredData $structuredData) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance(SeoManager::class);

        /** @var list<string> $patterns */
        $patterns = config('eventpulse.seo.noindex', []);

        $noindex = $patterns !== [] && $request->is(...$patterns);

        // Only a GET renders a document, so only a GET has a head to put the
        // organisation node in. A POST that redirects would build it, throw it
        // away, and pay for the config reads.
        if ($request->isMethod('GET')) {
            $seo = app(SeoManager::class);

            $seo->jsonLd('organization', $this->structuredData->organization());

            if ($noindex) {
                $seo->robots(['noindex', 'nofollow']);
            }
        }

        $response = $next($request);

        if ($noindex) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
