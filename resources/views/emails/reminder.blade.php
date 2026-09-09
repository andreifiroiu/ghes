{{-- One saved event, about to start. Same table-based shell as the digest so
     the two mails read as one product; deliberately a single card, with no
     recommendations attached — a reminder that also sells you three other
     things is an ad. --}}
<!doctype html>
<html lang="ro" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? 'Un eveniment salvat începe curând' }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: #0A1128; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; font-size: 24px; margin: 0; }
        .header p { color: #FF5733; font-size: 14px; margin: 8px 0 0; font-weight: 600; }
        .event-card { padding: 24px; }
        .event-title { font-size: 20px; font-weight: 600; color: #18181b; margin: 0 0 8px; }
        .event-title-link { color: #18181b; text-decoration: none; }
        .event-meta { font-size: 14px; color: #52525b; margin: 0 0 12px; }
        .event-description { font-size: 14px; color: #3f3f46; margin: 0 0 16px; line-height: 1.5; }
        .category-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 500; background: #ffe4dc; color: #9a2d12; }
        .cta { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none; background: #FF5733; color: #ffffff; }
        .secondary { margin-top: 16px; }
        .secondary a { font-size: 13px; color: #52525b; text-decoration: underline; margin-right: 12px; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #a1a1aa; }
        .footer a { color: #6366f1; text-decoration: none; }
    </style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
<tr><td align="center" style="padding:24px 16px;">
<table class="container" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">

    <tr>
        <td class="header">
            <h1>Ghes</h1>
            <p>{{ $leadPhrase }}</p>
        </td>
    </tr>

    <tr>
        <td class="event-card">
            @if($event->image_url)
                <img src="{{ $event->image_url }}" alt="" width="100%" style="border-radius:6px;margin-bottom:16px;max-height:220px;object-fit:cover;">
            @endif

            <div class="event-title">
                <a href="{{ $clickUrl }}" class="event-title-link">{{ $event->title }}</a>
            </div>

            <div class="event-meta">
                <span class="category-badge">{{ ucfirst($event->category->value) }}</span>
                @if($event->starts_at)
                    &middot; {{ $event->starts_at->format('d.m.Y, H:i') }}
                @endif
                @if($event->venue)
                    &middot; {{ $event->venue }}
                @endif
                @if($event->is_free)
                    &middot; Gratuit
                @elseif($event->price_min)
                    &middot; {{ $event->currency }} {{ number_format($event->price_min, 0) }}@if($event->price_max && $event->price_max != $event->price_min)–{{ number_format($event->price_max, 0) }}@endif
                @endif
            </div>

            @if($event->description)
                <div class="event-description">{{ Str::limit($event->description, 180) }}</div>
            @endif

            <a href="{{ $clickUrl }}" class="cta">Vezi detalii</a>

            <div class="secondary">
                @if($calendarUrl)
                    <a href="{{ $calendarUrl }}">Adaugă în calendar</a>
                @endif
                {{-- The only reaction offered. "Mă interesează" and "Salvează"
                     are both already true of anyone receiving this, so the one
                     useful thing left to say is that plans changed. --}}
                <a href="{{ $notInterestedUrl }}">Nu mai merg</a>
            </div>
        </td>
    </tr>

    <tr>
        <td class="footer">
            <p>Primești acest e-mail pentru că ai salvat acest eveniment sau ai spus că te interesează.</p>
            <p><a href="{{ $unsubscribeUrl }}">Nu-mi mai trimite memento-uri</a></p>
        </td>
    </tr>

</table>
</td></tr>
</table>
{{-- Open tracking. Last element in the body so a client that stops rendering
     early has already shown the whole reminder. --}}
<img src="{{ $openPixelUrl }}" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;">
</body>
</html>
