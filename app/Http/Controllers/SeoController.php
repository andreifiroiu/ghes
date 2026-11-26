<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\RobotsTxt;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Http\Response;

/**
 * The two documents crawlers fetch directly, neither of which is a page.
 */
class SeoController extends Controller
{
    /**
     * The fallback for /robots.txt.
     *
     * On a deployed host nginx serves `public/robots.txt` and never reaches
     * Laravel — see PublishRobotsTxtCommand for why the file has to exist. This
     * route is what answers before the first deploy hook runs, on `artisan
     * serve`, and in the test suite, and a test pins that it produces exactly
     * the bytes the command writes.
     */
    public function robots(RobotsTxt $robots): Response
    {
        return response($robots->file(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function sitemap(SitemapGenerator $sitemap): Response
    {
        return response($sitemap->cached(), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
