{{-- An event's tags as a row of small pills under the meta line. Inline styles
     rather than a class so the partial reads the same in every mail that
     includes it, whatever that mail's <style> block holds. Capped: a long tag
     list is noise in an inbox, and the event page shows the rest. Scraped tags
     can arrive unnormalised, so blanks are dropped here. --}}
@php
    $tags = collect((array) $event->tags)
        ->filter(fn ($tag): bool => is_string($tag))
        ->map(fn (string $tag): string => trim($tag))
        ->reject(fn (string $tag): bool => $tag === '')
        ->take((int) config('eventpulse.notifications.max_tags_per_event', 5))
        ->all();
@endphp
@if(count($tags) > 0)
    <div class="event-tags" style="margin:0 0 10px;">
        @foreach($tags as $tag)
            <span style="display:inline-block;padding:2px 8px;margin:0 4px 4px 0;border-radius:9999px;font-size:11px;background:#f4f4f5;color:#52525b;">#{{ $tag }}</span>
        @endforeach
    </div>
@endif
