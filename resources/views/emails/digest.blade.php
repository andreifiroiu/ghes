{{-- Compiled from MJML source. Table-based layout for email client compatibility. --}}
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? 'Digestul tău Ghes' }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: #4f46e5; padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; font-size: 24px; margin: 0; }
        .header p { color: #c7d2fe; font-size: 14px; margin: 8px 0 0; }
        .section-title { font-size: 18px; font-weight: 600; color: #18181b; padding: 24px 24px 12px; margin: 0; }
        .event-card { padding: 16px 24px; border-bottom: 1px solid #e4e4e7; }
        .event-card:last-child { border-bottom: none; }
        .event-title { font-size: 16px; font-weight: 600; color: #18181b; margin: 0 0 4px; }
        .event-meta { font-size: 13px; color: #71717a; margin: 0 0 8px; }
        .event-description { font-size: 14px; color: #3f3f46; margin: 0 0 12px; line-height: 1.5; }
        .category-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 500; background: #e0e7ff; color: #4338ca; }
        .reactions { margin-top: 8px; }
        .reaction-btn { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; margin-right: 6px; margin-bottom: 4px; }
        .btn-interested { background: #dcfce7; color: #166534; }
        .btn-not-interested { background: #fee2e2; color: #991b1b; }
        .btn-saved { background: #fef3c7; color: #92400e; }
        .reaction-divider { color: #d4d4d8; margin: 0 4px; font-size: 13px; }
        .discovery-section { background: #fefce8; }
        .discovery-label { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 500; background: #fef08a; color: #854d0e; margin-bottom: 8px; }
        .footer { padding: 24px; text-align: center; font-size: 12px; color: #a1a1aa; }
        .footer a { color: #6366f1; text-decoration: none; }
    </style>
</head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
<tr><td align="center" style="padding:24px 16px;">
<table class="container" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">

    {{-- Header --}}
    <tr>
        <td class="header">
            <h1>Ghes</h1>
            <p>Salut {{ $user->name }}, uite ce te așteaptă diseară</p>
        </td>
    </tr>

    {{-- Recommended events --}}
    @if(count($recommendedEvents) > 0)
    <tr>
        <td class="section-title">Recomandate pentru tine</td>
    </tr>
    @foreach($recommendedEvents as $item)
        @php $event = $item['event']; $urls = $item['reaction_urls']; @endphp
        <tr>
            <td class="event-card">
                @if($event->image_url)
                    <img src="{{ $event->image_url }}" alt="" width="100%" style="border-radius:6px;margin-bottom:12px;max-height:180px;object-fit:cover;">
                @endif
                <div class="event-title">{{ $event->title }}</div>
                <div class="event-meta">
                    <span class="category-badge">{{ ucfirst($event->category->value) }}</span>
                    @if($event->starts_at)
                        &middot; {{ $event->starts_at->format('D, M j \a\t g:iA') }}
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
                    <div class="event-description">{{ Str::limit($event->description, 150) }}</div>
                @endif
                <div class="reactions">
                    <a href="{{ $urls['interested'] }}" class="reaction-btn btn-interested">&#x2764; Mă interesează</a>
                    <a href="{{ $urls['not_interested'] }}" class="reaction-btn btn-not-interested">&#x1f44e; Nu-i pentru mine</a>
                    <span class="reaction-divider">&middot;</span>
                    <a href="{{ $urls['saved'] }}" class="reaction-btn btn-saved">&#x1f516; Salvează</a>
                </div>
            </td>
        </tr>
    @endforeach
    @endif

    {{-- Discovery events --}}
    @if(count($discoveryEvents) > 0)
    <tr>
        <td class="section-title discovery-section" style="padding-top:20px;">Descoperă ceva nou</td>
    </tr>
    @foreach($discoveryEvents as $item)
        @php $event = $item['event']; $urls = $item['reaction_urls']; @endphp
        <tr>
            <td class="event-card discovery-section">
                <span class="discovery-label">Descoperire</span>
                <div class="event-title">{{ $event->title }}</div>
                <div class="event-meta">
                    <span class="category-badge">{{ ucfirst($event->category->value) }}</span>
                    @if($event->starts_at)
                        &middot; {{ $event->starts_at->format('D, M j \a\t g:iA') }}
                    @endif
                    @if($event->venue)
                        &middot; {{ $event->venue }}
                    @endif
                </div>
                @if($event->description)
                    <div class="event-description">{{ Str::limit($event->description, 120) }}</div>
                @endif
                <div class="reactions">
                    <a href="{{ $urls['interested'] }}" class="reaction-btn btn-interested">&#x2764; Mă interesează</a>
                    <a href="{{ $urls['not_interested'] }}" class="reaction-btn btn-not-interested">&#x1f44e; Nu-i pentru mine</a>
                    <span class="reaction-divider">&middot;</span>
                    <a href="{{ $urls['saved'] }}" class="reaction-btn btn-saved">&#x1f516; Salvează</a>
                </div>
            </td>
        </tr>
    @endforeach
    @endif

    {{-- Footer --}}
    <tr>
        <td class="footer">
            <p>Primești acest email deoarece ești abonat la digesturile Ghes.</p>
            <p><a href="{{ route('settings.notifications') }}">Gestionează preferințele de notificare</a></p>
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>
