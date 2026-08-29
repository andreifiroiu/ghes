<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reacție înregistrată — Ghes</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f4f4f5; }
        .card { background: #fff; border-radius: 12px; padding: 40px; text-align: center; max-width: 420px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .check { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 20px; color: #18181b; margin: 0 0 8px; }
        p { font-size: 14px; color: #71717a; margin: 0; }
        a { color: #FF5733; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <div class="check">&#x2705;</div>
        <h1>Reacție înregistrată</h1>
        <p>
            Ai marcat <strong>{{ $event->title }}</strong> ca
            <strong>{{ $label }}</strong>.
        </p>
        <p style="margin-top:16px;"><a href="{{ route('dashboard') }}">Deschide Ghes &rarr;</a></p>
    </div>
</body>
</html>
