<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Event;

/**
 * Where an outbound click should land, and which provider it credits.
 *
 * This is the open-redirect guarantee for both the public web redirector and
 * the authenticated API click, so it exists exactly once. The destination is
 * always one of the event's own stored URLs; a requested source only
 * *selects* among the event's `event_sources` rows and is ignored when it
 * names none of them. Nothing from the request is ever forwarded to.
 */
class ClickDestinationResolver
{
    /**
     * @return array{url: string, source: string}|null
     */
    public function resolve(Event $event, mixed $requestedSource): ?array
    {
        if (is_string($requestedSource) && $requestedSource !== '') {
            $match = $event->sources()
                ->where('source', $requestedSource)
                ->whereNotNull('source_url')
                ->first();

            if ($match !== null && $match->source_url !== '') {
                return ['url' => $match->source_url, 'source' => $match->source];
            }
        }

        return $event->source_url === ''
            ? null
            : ['url' => $event->source_url, 'source' => $event->source];
    }
}
