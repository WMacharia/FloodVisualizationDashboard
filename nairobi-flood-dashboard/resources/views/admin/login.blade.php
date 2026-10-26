<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal Login | FloodViz</title>

    <!-- Apply saved/system theme before first paint (shared with the flood map) -->
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('floodDssTheme'); } catch (e) {}
            if (!t) t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">

    <style>
        /* =========================================================
           DESIGN TOKENS (same as the flood map; text pairs meet WCAG AA)
        ========================================================= */
        :root, [data-theme="light"] {
            --bg: #e9eef5; --surface: #ffffff; --surface-2: #f1f5f9;
            --line: #cbd5e1; --line-strong: #64748b;
            --text: #0f172a; --text-2: #1e293b; --text-3: #475569;
            --accent: #0369a1; --accent-ink: #ffffff; --accent-soft: #e0f2fe;
            --high: #b91c1c; --high-soft: #fee2e2;
            --shadow: 0 10px 30px -8px rgba(15, 23, 42, .28);
        }
        [data-theme="dark"] {
            --bg: #0b1220; --surface: #111b2e; --surface-2: #19253b;
            --line: #2d3b55; --line-strong: #8da2c0;
            --text: #f8fafc; --text-2: #e2e8f0; --text-3: #a8b8d0;
            --accent: #38bdf8; --accent-ink: #04111f; --accent-soft: #0c2a40;
            --high: #fca5a5; --high-soft: #4a1717;
            --shadow: 0 14px 40px -10px rgba(0, 0, 0, .7);
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 16px; line-height: 1.45; color: var(--text);
            background-color: var(--bg);
            background-image: radial-gradient(900px 420px at 50% -8%, var(--accent-soft), transparent 70%);
            -webkit-font-smoothing: antialiased;
        }
        button, input { font-family: inherit; font-size: inherit; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        svg.i { width: 20px; height: 20px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        .theme-btn {
            position: fixed; top: 16px; right: 16px; width: 44px; height: 44px; display: grid; place-items: center;
            background: var(--surface); border: 2px solid var(--line); border-radius: 12px; color: var(--text); cursor: pointer; box-shadow: var(--shadow);
        }
        .theme-btn:hover { border-color: var(--line-strong); }

        .card {
            width: 100%; max-width: 440px; padding: 32px;
            background: var(--surface); border: 2px solid var(--line); border-radius: 20px; box-shadow: var(--shadow);
        }
        .brand { text-align: center; margin-bottom: 28px; }
        .logo {
            width: 56px; height: 56px; margin: 0 auto 16px; display: grid; place-items: center;
            background: var(--accent-soft); color: var(--accent); border-radius: 16px;
        }
        .logo svg { width: 30px; height: 30px; }
        h1 { margin: 0; font-size: 1.5rem; line-height: 1.2; }
        .brand p { margin: 8px 0 0; color: var(--text-3); font-size: 15px; }

        .alert {
            display: flex; gap: 12px; margin-bottom: 20px; padding: 12px 14px;
            background: var(--high-soft); color: var(--high); border: 2px solid var(--high); border-radius: 12px; font-weight: 700;
        }
        .alert .i { margin-top: 2px; }

        .field + .field { margin-top: 18px; }
        label.lbl { display: block; font-weight: 700; margin-bottom: 6px; }
        .control { position: relative; }
        .control input {
            width: 100%; min-height: 50px; padding: 0 16px; color: var(--text); background: var(--surface);
            border: 2px solid var(--line-strong); border-radius: 12px;
        }
        .control input::placeholder { color: var(--text-3); opacity: 1; }
        .control input:focus { outline: 3px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .control input[aria-invalid="true"] { border-color: var(--high); }
        .control.has-toggle input { padding-right: 56px; }
        .reveal {
            position: absolute; right: 4px; top: 3px; width: 44px; height: 44px; display: grid; place-items: center;
            background: transparent; border: 0; border-radius: 10px; color: var(--text-2); cursor: pointer;
        }
        .reveal:hover { background: var(--surface-2); }

        .btn {
            width: 100%; min-height: 52px; margin-top: 24px; padding: 0 16px; border: 2px solid transparent; border-radius: 12px;
            background: var(--accent); color: var(--accent-ink); font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
            transition: transform .1s, filter .15s;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn:active { transform: scale(.985); }
        .btn.is-loading { opacity: .85; cursor: progress; }
        .btn.is-loading::after { content: ''; width: 16px; height: 16px; border-radius: 50%; border: 3px solid currentColor; border-right-color: transparent; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .back { display: flex; justify-content: center; margin-top: 18px; }
        .back a { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 8px; color: var(--accent); font-weight: 700; text-decoration: underline; text-underline-offset: 3px; }

        @media (max-width: 480px) { .card { padding: 24px 20px; } body { align-items: start; padding-top: 72px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-wave" viewBox="0 0 24 24"><path d="M2 9c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5M2 16c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.01"/></symbol>
        <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.3 7.6A17 17 0 0 0 2 12s3.5 7 10 7c1.7 0 3.2-.4 4.5-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></symbol>
        <symbol id="i-back" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
    </svg>

    <button class="theme-btn" id="theme-btn" type="button" onclick="toggleTheme()" aria-label="Switch to dark theme">
        <svg class="i" id="theme-icon"><use href="#i-moon"/></svg>
    </button>

    <main class="card">
        <div class="brand">
            <div class="logo" aria-hidden="true"><svg class="i"><use href="#i-wave"/></svg></div>
            <h1>FloodViz Admin Portal</h1>
            <p>Sign in with your system administrator account.</p>
        </div>

        @if ($errors->any())
            <div class="alert" role="alert" id="login-error">
                <svg class="i" aria-hidden="true"><use href="#i-alert"/></svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form action="{{ route('admin.login.submit') }}" method="POST" id="login-form">
            @csrf
            <div class="field">
                <label class="lbl" for="email">Email address</label>
                <div class="control">
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                           autocomplete="username" placeholder="admin@floodviz.com"
                           @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
                </div>
            </div>

            <div class="field">
                <label class="lbl" for="password">Password</label>
                <div class="control has-toggle">
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password" placeholder="Enter your password"
                           @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
                    <button type="button" class="reveal" id="reveal-btn" aria-label="Show password" aria-pressed="false" onclick="togglePassword()">
                        <svg class="i" id="reveal-icon"><use href="#i-eye"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn" id="submit-btn">Sign in to dashboard</button>
        </form>

        <!-- <div class="back">
            <a href="/"><svg class="i" aria-hidden="true"><use href="#i-back"/></svg> Back to flood map</a>
        </div> -->
    </main>

    <script>
        function applyTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            try { localStorage.setItem('floodDssTheme', t); } catch (e) {}
            var dark = t === 'dark';
            document.getElementById('theme-icon').innerHTML = '<use href="#' + (dark ? 'i-sun' : 'i-moon') + '"/>';
            document.getElementById('theme-btn').setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
        }
        function toggleTheme() {
            applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        }
        applyTheme(document.documentElement.getAttribute('data-theme'));

        function togglePassword() {
            var input = document.getElementById('password');
            var btn = document.getElementById('reveal-btn');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            document.getElementById('reveal-icon').innerHTML = '<use href="#' + (show ? 'i-eye-off' : 'i-eye') + '"/>';
        }

        // Show progress and prevent accidental double submits
        document.getElementById('login-form').addEventListener('submit', function (e) {
            var btn = document.getElementById('submit-btn');
            if (btn.classList.contains('is-loading')) { e.preventDefault(); return; }
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
        });
    </script>
</body>
</html>