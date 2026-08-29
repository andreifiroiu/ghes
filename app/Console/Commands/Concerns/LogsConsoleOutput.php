<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Mirrors everything a command writes to the terminal into the application log.
 *
 * Every output helper on a console command (info, warn, error, comment, alert,
 * ...) funnels through line(), so overriding that one method captures all of
 * them without touching the commands' own output calls. Scheduled and queued
 * runs write to a terminal nobody is watching, which is exactly when having the
 * same messages in the log channels matters.
 */
trait LogsConsoleOutput
{
    /**
     * Set once the log channel has thrown, so a broken logger costs one warning
     * rather than one failed write per output line.
     */
    private bool $consoleLoggingUnavailable = false;

    /**
     * @param  string  $string
     * @param  string|null  $style
     * @param  int|string|null  $verbosity
     */
    public function line($string, $style = null, $verbosity = null): void
    {
        parent::line($string, $style, $verbosity);

        $this->logConsoleLine((string) $string, $style);
    }

    /**
     * Context attached to every log entry this command writes. Commands may
     * override this to add identifying details of their own.
     *
     * @return array<string, mixed>
     */
    protected function consoleLogContext(): array
    {
        return ['command' => $this->getName()];
    }

    private function logConsoleLine(string $message, ?string $style): void
    {
        if ($this->consoleLoggingUnavailable) {
            return;
        }

        $message = trim($message);

        // Blank spacer lines (alert() emits them) carry nothing worth logging.
        if ($message === '') {
            return;
        }

        try {
            $this->consoleLogger()->log(
                $this->logLevelForStyle($style),
                $message,
                $this->consoleLogContext(),
            );
        } catch (Throwable $exception) {
            // The message is already on the terminal, so a misconfigured
            // channel or an unwritable log file must not take down a scheduled
            // scrape or notification run. Report it once and carry on.
            $this->consoleLoggingUnavailable = true;

            // writeln(), not line(): line() is what led here.
            $this->output->writeln(
                '<comment>Console output is not reaching the log: '.$exception->getMessage().'</comment>'
            );
        }
    }

    private function consoleLogger(): LoggerInterface
    {
        $channel = config('eventpulse.logging.console_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }

    private function logLevelForStyle(?string $style): string
    {
        return match ($style) {
            'error' => LogLevel::ERROR,
            'warning' => LogLevel::WARNING,
            default => LogLevel::INFO,
        };
    }
}
