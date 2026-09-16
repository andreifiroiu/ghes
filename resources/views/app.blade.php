@php
    /**
     * Ghes has no SSR, so this is the only head a non-JavaScript crawler ever
     * sees — and the AI answer engines that matter for AEO do not run
     * JavaScript. Inertia renders this view on a full page load and skips it
     * entirely on a client-side visit, which is exactly the split we want: a
     * crawler always arrives with a full load.
     *
     * Resolved here rather than shared by a composer because this view is the
     * only consumer. By the time Blade runs, the controller has already set
     * whatever it wanted; see App\Services\Seo\SeoManager.
     */
    $seo = app(\App\Services\Seo\SeoManager::class);
@endphp
<!DOCTYPE html>
<html lang="{{ config('eventpulse.seo.defaults.language') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- The `inertia` attribute hands this over to React's <Head> after
             hydration, so an in-app navigation still retitles the tab. --}}
        <title inertia>{!! $seo->titleTag() !!}</title>
        {!! $seo->render() !!}
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
