<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Renders an event as an iCalendar (RFC 5545) document, for the "add to
 * calendar" button on the event detail page.
 *
 * Hand-rolled rather than pulled from a package: one VEVENT with no recurrence,
 * no attendees and no alarms is a small enough surface that the escaping and
 * folding rules below are the whole of it.
 */
class IcsGenerator
{
    /**
     * Length an event with no explicit end is assumed to run for. Many scraper
     * adapters only publish a start time, and a zero-length calendar entry
     * renders as a bare marker in most clients.
     */
    private const DEFAULT_DURATION_HOURS = 2;

    /**
     * Render the event as a VCALENDAR document.
     *
     * An undated event cannot go in a calendar. Substituting "now" would hand
     * the user a confident-looking two-hour commitment for a date the platform
     * does not know, and nothing downstream could tell it apart from a real
     * one — so callers must refuse the download rather than pass one here.
     *
     * @throws InvalidArgumentException when the event has no start time
     */
    public function generate(Event $event): string
    {
        if ($event->starts_at === null) {
            throw new InvalidArgumentException("Event {$event->getKey()} has no start time.");
        }

        $starts = $event->starts_at;
        $ends = $this->endsAt($event, $starts);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.$this->escape((string) config('app.name')).'//Events//RO',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$event->getKey().'@'.$this->host(),
            'DTSTAMP:'.$this->timestamp(Carbon::now()),
            'DTSTART:'.$this->timestamp($starts),
            'DTEND:'.$this->timestamp($ends),
            'SUMMARY:'.$this->escape($event->title),
        ];

        if ($event->description !== null && $event->description !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape($event->description);
        }

        if (($location = $this->location($event)) !== null) {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        if ($event->latitude !== null && $event->longitude !== null) {
            $lines[] = sprintf('GEO:%.6F;%.6F', $event->latitude, $event->longitude);
        }

        // URL is a URI value, not TEXT (RFC 5545 §3.8.4.6), so it is emitted
        // verbatim. Text-escaping it would put literal backslashes into any
        // ticket link carrying a comma or semicolon — routine for the
        // aggregator sources — and the link would 404 from inside the user's
        // calendar days later, where nothing here could observe it.
        $lines[] = 'URL:'.$event->source_url;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545 mandates CRLF line endings; some clients reject LF-only files.
        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * The end time to publish.
     *
     * A stored `ends_at` that is not after the start is discarded rather than
     * emitted: RFC 5545 requires DTEND to follow DTSTART, and Google Calendar
     * rejects the whole file when it does not. Scrapers produce this routinely
     * for events listed as "22:00–02:00" whose end date was never rolled to the
     * next day, and only the admin edit form validates the ordering.
     */
    private function endsAt(Event $event, Carbon $starts): Carbon
    {
        if ($event->ends_at !== null && $event->ends_at->greaterThan($starts)) {
            return $event->ends_at;
        }

        if ($event->ends_at !== null) {
            Log::warning('Event has an end time at or before its start; falling back to the default duration.', [
                'event_id' => $event->getKey(),
                'source' => $event->source,
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
            ]);
        }

        return $starts->copy()->addHours(self::DEFAULT_DURATION_HOURS);
    }

    /**
     * The filename a client should save the download as.
     *
     * Romanian diacritics are transliterated rather than stripped: the product
     * is Romanian, so a naive ASCII filter turns "Concert în Piață" into
     * "concert-n-pia", and a title with no ASCII letters at all collapses to a
     * shared "event.ics" that overwrites the last one downloaded.
     */
    public function filename(Event $event): string
    {
        $ascii = strtr($event->title, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ]);

        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii) ?? '', '-');

        // Trim to length first, then re-trim the hyphens, or a cut that lands on
        // a separator leaves a trailing dash.
        return ($slug === '' ? 'event' : trim(strtolower(substr($slug, 0, 60)), '-')).'.ics';
    }

    private function location(Event $event): ?string
    {
        $parts = array_filter([$event->venue, $event->address, $event->city]);

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    /**
     * A UTC timestamp in iCalendar's basic format. Every value we emit is UTC,
     * so the trailing Z is unconditional and no VTIMEZONE block is needed.
     */
    private function timestamp(Carbon $moment): string
    {
        return $moment->copy()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape a text value: backslash first, or it would double-escape the
     * backslashes the later replacements introduce. Newlines become a literal
     * `\n`, since a raw newline would terminate the property.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $value,
        );
    }

    /**
     * Fold a content line to 75 octets, continuing with a leading space.
     *
     * Splitting is done on octets rather than characters because the limit is
     * expressed in octets — but a break must not land inside a UTF-8 sequence,
     * so each chunk is taken as whole characters that fit within the budget.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = [];
        $current = '';
        // Continuation lines carry a leading space, which counts toward the 75.
        $budget = 75;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $budget) {
                $folded[] = $current;
                $current = '';
                $budget = 74;
            }

            $current .= $character;
        }

        $folded[] = $current;

        return implode("\r\n ", $folded);
    }

    private function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'ghes.local';
    }
}
