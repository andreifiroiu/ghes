<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsConsoleOutput;
use App\Services\Seo\RobotsTxt;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * Writes robots.txt into public/ so nginx serves it as a plain file.
 *
 * The /robots.txt route produces the same text, but behind the Forge and Valet
 * nginx templates a missing file is answered with a 404 status: `location =
 * /robots.txt` carries no try_files, the miss trips `error_page 404 /index.php`,
 * Laravel renders the body and nginx keeps the error code. Google reads a 404
 * robots.txt as "no rules", which silently drops both the Disallow lines and the
 * Sitemap pointer.
 *
 * The file is gitignored because its content depends on the host's .env. Every
 * deploy hook runs this after `composer install`, and `--check` gives the deploy
 * script or CI an exit code for the host where that line was forgotten.
 *
 * Because the file *is* served, the fail-closed policy has teeth: a production
 * host whose APP_URL is misspelt would be de-indexed by a file the deploy log
 * called a success. That case is reported as an error, not as "Wrote …".
 */
class PublishRobotsTxtCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'seo:publish-robots
        {--path= : Where to write the file (defaults to public/robots.txt)}
        {--check : Do not write; exit non-zero if the file is missing or stale}';

    protected $description = 'Write robots.txt into public/ so nginx serves it as a 200 without touching Laravel';

    public function handle(Filesystem $files): int
    {
        $path = $this->option('path') ?: public_path('robots.txt');
        $robots = RobotsTxt::fromConfig();
        $body = $robots->file();

        if ($this->option('check')) {
            return $this->check($files, (string) $path, $body);
        }

        if ($files->put($path, $body) !== strlen($body)) {
            $this->error("Could not write {$path}");

            return self::FAILURE;
        }

        if ($robots->isPublicHost()) {
            $this->info("Wrote {$path}: public policy for {$robots->host()}");

            return self::SUCCESS;
        }

        $message = "Wrote {$path}: Disallow: / — {$robots->host()} is not a public host";

        if (config('app.env') === 'production') {
            Log::error('robots.txt published with a closed policy on a production host', [
                'host' => $robots->host(),
                'app_url' => config('app.url'),
                'public_hosts' => config('eventpulse.seo.robots.public_hosts'),
            ]);

            $this->warn($message.'. If this host really does publish the site, APP_URL or GHES_PUBLIC_HOSTS is wrong and the site is now de-indexed.');

            return self::SUCCESS;
        }

        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * Fails when the file is missing or no longer matches what a write would
     * produce.
     *
     * Nothing in the repository can make Forge run the deploy line, and a host
     * where it was forgotten keeps answering /robots.txt with nginx's 404
     * exactly as before — the failure this command exists to end, with no other
     * signal. Drift is checked as well as presence, so a policy change deployed
     * without the hook is caught too.
     */
    private function check(Filesystem $files, string $path, string $expected): int
    {
        if (! $files->exists($path)) {
            $this->error("{$path} is missing — the deploy hook did not run `php artisan seo:publish-robots`, so nginx answers /robots.txt with a 404.");

            return self::FAILURE;
        }

        if ($files->get($path) !== $expected) {
            $this->error("{$path} differs from what `seo:publish-robots` would write — the policy changed and the deploy hook did not rewrite it.");

            return self::FAILURE;
        }

        $this->info("{$path} is up to date.");

        return self::SUCCESS;
    }
}
