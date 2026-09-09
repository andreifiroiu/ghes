<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Oprește memento-urile — Ghes</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f4f4f5; }
        .card { background: #fff; border-radius: 12px; padding: 40px; text-align: center; max-width: 420px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        h1 { font-size: 20px; color: #18181b; margin: 0 0 8px; }
        p { font-size: 14px; color: #71717a; margin: 0; }
        button { margin-top: 20px; background: #FF5733; color: #fff; border: 0; border-radius: 8px; padding: 12px 24px; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Oprim memento-urile</h1>
        <p>
            Nu-ți mai trimitem memento-uri înainte de evenimentele salvate.
            Recomandările rămân neschimbate.
        </p>

        {{-- Submitted automatically where JS runs; the button covers everyone else. --}}
        {{-- No @csrf: the URL signature is the authority here, and the session
             cookie is unreliable in mail webviews. --}}
        <form id="confirm" method="POST" action="{{ $action }}">
            <button type="submit">Confirmă</button>
        </form>
    </div>

    <script>
        document.getElementById('confirm').submit();
    </script>
</body>
</html>
