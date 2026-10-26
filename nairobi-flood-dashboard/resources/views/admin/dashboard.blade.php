<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | FloodViz</title>

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
            --safe: #166534; --safe-soft: #dcfce7;
            --none: #334155; --none-soft: #e2e8f0;
            --shadow: 0 10px 30px -8px rgba(15, 23, 42, .28);
        }
        [data-theme="dark"] {
            --bg: #0b1220; --surface: #111b2e; --surface-2: #19253b;
            --line: #2d3b55; --line-strong: #8da2c0;
            --text: #f8fafc; --text-2: #e2e8f0; --text-3: #a8b8d0;
            --accent: #38bdf8; --accent-ink: #04111f; --accent-soft: #0c2a40;
            --high: #fca5a5; --high-soft: #4a1717;
            --safe: #86efac; --safe-soft: #0f3320;
            --none: #cbd5e1; --none-soft: #1f2c44;
            --shadow: 0 14px 40px -10px rgba(0, 0, 0, .7);
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 16px; line-height: 1.45; color: var(--text); background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        button, input { font-family: inherit; font-size: inherit; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .skip { position: fixed; left: 12px; top: -60px; z-index: 5000; padding: 12px 16px; background: var(--accent); color: var(--accent-ink); font-weight: 700; border-radius: 10px; text-decoration: none; }
        .skip:focus { top: 12px; }
        svg.i { width: 20px; height: 20px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Top bar */
        .topbar {
            position: sticky; top: 0; z-index: 100; display: flex; align-items: center; flex-wrap: wrap; gap: 12px 16px;
            padding: 12px 24px; background: var(--surface); border-bottom: 2px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 12px; margin-right: auto; min-width: 0; }
        .logo { width: 44px; height: 44px; display: grid; place-items: center; flex: none; background: var(--accent-soft); color: var(--accent); border-radius: 12px; }
        .brand h1 { margin: 0; font-size: 1.2rem; line-height: 1.2; }
        .brand small { display: block; font-size: 14px; color: var(--text-3); }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .btn {
            min-height: 44px; padding: 0 16px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--surface); color: var(--text); border: 2px solid var(--line-strong); border-radius: 12px;
            font-weight: 700; text-decoration: none; cursor: pointer; transition: background .15s;
        }
        .btn:hover { background: var(--surface-2); }
        .btn-primary { background: var(--accent); color: var(--accent-ink); border-color: transparent; }
        .btn-primary:hover { background: var(--accent); filter: brightness(1.08); }
        .icon-btn { width: 44px; padding: 0; }

        main { max-width: 1120px; margin: 0 auto; padding: 28px 24px 48px; }
        .page-title { margin: 0 0 4px; font-size: 1.6rem; line-height: 1.2; }
        .page-sub { margin: 0 0 24px; color: var(--text-3); }

        .flash { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 20px; padding: 12px 16px; border-radius: 12px; font-weight: 700; border: 2px solid; }
        .flash.ok { background: var(--safe-soft); color: var(--safe); border-color: var(--safe); }
        .flash.err { background: var(--high-soft); color: var(--high); border-color: var(--high); }
        .flash .i { margin-top: 2px; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 28px; }
        .stat { display: flex; align-items: center; gap: 16px; padding: 20px; background: var(--surface); border: 2px solid var(--line); border-radius: 16px; }
        .stat-icon { width: 52px; height: 52px; display: grid; place-items: center; flex: none; border-radius: 14px; }
        .stat-icon svg { width: 26px; height: 26px; }
        .stat-icon.users { background: var(--accent-soft); color: var(--accent); }
        .stat-icon.traffic { background: var(--safe-soft); color: var(--safe); }
        .stat-label { font-weight: 700; color: var(--text-2); }
        .stat-value { font-size: 2rem; font-weight: 700; line-height: 1.15; letter-spacing: -0.01em; }
        .stat-value small { font-size: 1rem; font-weight: 400; color: var(--text-3); letter-spacing: 0; }

        /* Users table */
        .panel { background: var(--surface); border: 2px solid var(--line); border-radius: 16px; overflow: hidden; }
        .panel-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 18px 20px; border-bottom: 2px solid var(--line); }
        .panel-head h2 { margin: 0; font-size: 1.25rem; }
        .count { color: var(--text-3); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 20px; font-size: 15px; font-weight: 700; color: var(--text-2); background: var(--surface-2); border-bottom: 2px solid var(--line); white-space: nowrap; }
        td { padding: 14px 20px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: var(--surface-2); }
        th.right, td.right { text-align: right; }

        .person { display: flex; align-items: center; gap: 12px; font-weight: 700; }
        .avatar { width: 40px; height: 40px; flex: none; border-radius: 50%; object-fit: cover; display: grid; place-items: center; font-weight: 700; background: var(--accent-soft); color: var(--accent); border: 2px solid var(--accent); }

        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px; border-radius: 999px; font-size: 14px; font-weight: 700; background: var(--none-soft); color: var(--none); }
        .chip.admin { background: var(--accent-soft); color: var(--accent); }

        .btn-danger { min-height: 44px; padding: 0 14px; display: inline-flex; align-items: center; gap: 8px; background: transparent; color: var(--high); border: 2px solid var(--high); border-radius: 12px; font-weight: 700; cursor: pointer; }
        .btn-danger:hover { background: var(--high-soft); }
        .protected { display: inline-flex; align-items: center; gap: 6px; color: var(--text-3); }

        .empty { padding: 40px 20px; text-align: center; color: var(--text-2); }
        .empty .i { width: 32px; height: 32px; color: var(--text-3); margin-bottom: 8px; }

        /* Table becomes a card list on small screens */
        @media (max-width: 760px) {
            .topbar { padding: 12px 16px; }
            main { padding: 20px 16px 40px; }
            .stats { grid-template-columns: 1fr; }
            thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
            table, tbody, tr, td { display: block; width: 100%; }
            tbody tr { padding: 14px 16px; border-bottom: 2px solid var(--line); }
            tbody tr:last-child { border-bottom: 0; }
            td { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 6px 0; border: 0; text-align: right; }
            td::before { content: attr(data-label); font-weight: 700; color: var(--text-3); text-align: left; flex: none; }
            td.person-cell { justify-content: flex-start; padding-bottom: 10px; }
            td.person-cell::before { display: none; }
            td.action-cell { padding-top: 10px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
        @media (forced-colors: active) { .chip, .stat, .panel { border: 2px solid CanvasText; } }
    </style>
</head>
<body>
    <a class="skip" href="#main">Skip to main content</a>

    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-wave" viewBox="0 0 24 24"><path d="M2 9c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5M2 16c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5"/></symbol>
        <symbol id="i-map" viewBox="0 0 24 24"><path d="M9 4L3 6.5v13L9 17l6 3 6-2.5v-13L15 7 9 4zM9 4v13M15 7v13"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M16 8l4 4-4 4M20 12H9"/></symbol>
        <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.01"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></symbol>
    </svg>

    <header class="topbar">
        <div class="brand">
            <div class="logo" aria-hidden="true"><svg class="i"><use href="#i-wave"/></svg></div>
            <div>
                <h1>FloodViz Management Console</h1>
                <small>Administrator</small>
            </div>
        </div>

        <nav class="actions" aria-label="Admin navigation">
            <a href="/" class="btn"><svg class="i" aria-hidden="true"><use href="#i-map"/></svg> View live flood map</a>
            <button type="button" class="btn icon-btn" id="theme-btn" onclick="toggleTheme()" aria-label="Switch to dark theme">
                <svg class="i" id="theme-icon"><use href="#i-moon"/></svg>
            </button>
            <form action="{{ route('admin.logout') }}" method="POST" style="margin:0;">
                @csrf
                <button type="submit" class="btn"><svg class="i" aria-hidden="true"><use href="#i-logout"/></svg> Sign out</button>
            </form>
        </nav>
    </header>

    <main id="main">
        <h2 class="page-title">Overview</h2>
        <p class="page-sub">Platform activity and user accounts.</p>

        @if (session('status'))
            <div class="flash ok" role="status">
                <svg class="i" aria-hidden="true"><use href="#i-check"/></svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert">
                <svg class="i" aria-hidden="true"><use href="#i-alert"/></svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Stats -->
        <section class="stats" aria-label="Platform statistics">
            <div class="stat">
                <div class="stat-icon users" aria-hidden="true"><svg class="i"><use href="#i-users"/></svg></div>
                <div>
                    <div class="stat-label">Registered users</div>
                    <div class="stat-value">{{ $totalUsers ?? 0 }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon traffic" aria-hidden="true"><svg class="i"><use href="#i-chart"/></svg></div>
                <div>
                    <div class="stat-label">Traffic, last 30 days</div>
                    <div class="stat-value">{{ $traffic ?? 0 }} <small>visits</small></div>
                </div>
            </div>
        </section>

        <!-- Users table -->
        <section class="panel" aria-labelledby="users-heading">
            <div class="panel-head">
                <h2 id="users-heading">User accounts</h2>
                @if(isset($users))
                    <span class="count">{{ $users->count() }} {{ $users->count() === 1 ? 'account' : 'accounts' }}</span>
                @endif
            </div>

            @if(isset($users) && $users->count() > 0)
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">User accounts with role, join date and actions</caption>
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Email</th>
                                <th scope="col">Role</th>
                                <th scope="col">Joined</th>
                                <th scope="col" class="right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td class="person-cell" data-label="User">
                                        <span class="person">
                                            @if($user->avatar)
                                                <img src="{{ $user->avatar }}" class="avatar" alt="">
                                            @else
                                                <span class="avatar" aria-hidden="true">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                                            @endif
                                            <span>{{ $user->name }}</span>
                                        </span>
                                    </td>
                                    <td data-label="Email">{{ $user->email }}</td>
                                    <td data-label="Role">
                                        <span class="chip {{ $user->role === 'admin' ? 'admin' : '' }}">{{ ucfirst($user->role) }}</span>
                                    </td>
                                    <td data-label="Joined"><time datetime="{{ $user->created_at->toDateString() }}">{{ $user->created_at->format('M d, Y') }}</time></td>
                                    <td class="right action-cell" data-label="Action">
                                        @if ($user->role !== 'admin')
                                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="delete-form" data-name="{{ $user->name }}" style="margin:0;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-danger" aria-label="Delete {{ $user->name }}">
                                                    <svg class="i" aria-hidden="true"><use href="#i-trash"/></svg> Delete
                                                </button>
                                            </form>
                                        @else
                                            <span class="protected"><svg class="i" aria-hidden="true"><use href="#i-lock"/></svg> Protected</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty">
                    <svg class="i" aria-hidden="true"><use href="#i-users"/></svg>
                    <div>No users found.</div>
                </div>
            @endif
        </section>
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

        // Confirm before deleting, naming the account so it's clear who is affected
        document.querySelectorAll('.delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var name = form.getAttribute('data-name') || 'this user';
                if (!confirm('Delete ' + name + "'s account? This cannot be undone.")) e.preventDefault();
            });
        });
    </script>
</body>
</html>