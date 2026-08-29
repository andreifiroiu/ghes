<?php

declare(strict_types=1);

use App\Console\Commands\Concerns\LogsConsoleOutput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

/**
 * Exercises every output style in one run, which no real command does.
 */
class ConsoleLoggingProbeCommand extends Command
{
    use LogsConsoleOutput;

    protected $signature = 'test:console-logging-probe {--message= : Write only this line, as info}';

    protected $description = 'Writes one line in each output style';

    public function handle(): int
    {
        if (is_string($message = $this->option('message'))) {
            $this->info($message);

            return self::SUCCESS;
        }

        $this->info('an info line');
        $this->comment('a comment line');
        $this->warn('a warning line');
        $this->error('an error line');
        $this->newLine();

        return self::SUCCESS;
    }
}

/**
 * @return MockInterface&LoggerInterface
 */
function fakeConsoleLogger(): MockInterface
{
    $logger = Mockery::spy(LoggerInterface::class);

    Log::shouldReceive('channel')->andReturn($logger);

    return $logger;
}

it('mirrors command output into the application log', function (): void {
    $logger = fakeConsoleLogger();

    $this->artisan('eventpulse:decay-profiles')->assertSuccessful();

    $logger->shouldHaveReceived('log')
        ->with('info', 'Applying decay to user interest profiles...', ['command' => 'eventpulse:decay-profiles'])
        ->once();
});

it('logs each output style at its matching level', function (): void {
    Artisan::registerCommand(new ConsoleLoggingProbeCommand);

    $logger = fakeConsoleLogger();

    $this->artisan('test:console-logging-probe')->assertSuccessful();

    $context = ['command' => 'test:console-logging-probe'];

    $logger->shouldHaveReceived('log')->with('info', 'an info line', $context)->once();
    $logger->shouldHaveReceived('log')->with('info', 'a comment line', $context)->once();
    $logger->shouldHaveReceived('log')->with('warning', 'a warning line', $context)->once();
    $logger->shouldHaveReceived('log')->with('error', 'an error line', $context)->once();
    $logger->shouldHaveReceived('log')->times(4);
});

/**
 * Scraped event titles reach the log through DedupeEventsCommand, and Romanian
 * event names really do contain '<'. Stripping markup here ate everything after
 * it, destroying the diagnostic the log entry exists for.
 */
it('logs a message containing angle brackets verbatim', function (): void {
    Artisan::registerCommand(new ConsoleLoggingProbeCommand);

    $logger = fakeConsoleLogger();

    $this->artisan('test:console-logging-probe', ['--message' => 'Concert <3 Rock'])->assertSuccessful();

    $logger->shouldHaveReceived('log')
        ->with('info', 'Concert <3 Rock', ['command' => 'test:console-logging-probe'])
        ->once();
});

/**
 * A write that throws — an unwritable log file, a full disk — must not take down
 * a scheduled scrape or notification run over a message already on the terminal.
 * Laravel absorbs an *undefined* channel on its own, so the failure is simulated
 * at the write.
 */
it('keeps the command running when logging throws, and says so once', function (): void {
    Artisan::registerCommand(new ConsoleLoggingProbeCommand);

    Log::shouldReceive('channel')->andThrow(new RuntimeException('log disk is full'));

    $exitCode = Artisan::call('test:console-logging-probe');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(substr_count($output, 'Console output is not reaching the log'))->toBe(1)
        ->and($output)->toContain('an info line')
        ->and($output)->toContain('an error line');
});

it('logs the warnings a dry run prints', function (): void {
    $logger = fakeConsoleLogger();

    $this->artisan('eventpulse:dedupe-events', ['--dry-run' => true])->assertSuccessful();

    $logger->shouldHaveReceived('log')
        ->with('warning', 'Dry run — every change below will be rolled back.', ['command' => 'eventpulse:dedupe-events'])
        ->once();
});

it('applies the logging trait to every artisan command', function (): void {
    $classes = collect(glob(app_path('Console/Commands/*.php')))
        ->map(fn (string $path): string => 'App\\Console\\Commands\\'.basename($path, '.php'));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(class_uses_recursive($class))->toContain(LogsConsoleOutput::class);
    }
});
