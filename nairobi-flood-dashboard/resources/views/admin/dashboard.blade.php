<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>System Console | Nairobi Flood DSS</title>

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
            --med: #92400e; --med-soft: #fef3c7;
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
            --med: #fcd34d; --med-soft: #43300a;
            --safe: #86efac; --safe-soft: #0f3320;
            --none: #cbd5e1; --none-soft: #1f2c44;
            --shadow: 0 14px 40px -10px rgba(0, 0, 0, .7);
        }

        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px; line-height: 1.45; color: var(--text); background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        button, input, select { font-family: inherit; font-size: inherit; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .skip { position: fixed; left: 12px; top: -60px; z-index: 5000; padding: 12px 16px; background: var(--accent); color: var(--accent-ink); font-weight: 700; border-radius: 10px; text-decoration: none; }
        .skip:focus { top: 12px; }
        svg.i { width: 20px; height: 20px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Top bar */
        .topbar {
            position: sticky; top: 0; z-index: 100; display: flex; align-items: center; flex-wrap: wrap; gap: 12px 16px;
            padding: 10px 24px; background: var(--surface); border-bottom: 2px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 12px; margin-right: auto; min-width: 0; }
        .logo { width: 42px; height: 42px; display: grid; place-items: center; flex: none; background: var(--accent-soft); color: var(--accent); border-radius: 12px; }
        .brand h1 { margin: 0; font-size: 1.15rem; line-height: 1.2; }
        .brand small { display: block; font-size: 13px; color: var(--text-3); }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .btn {
            min-height: 40px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--surface); color: var(--text); border: 2px solid var(--line-strong); border-radius: 10px;
            font-weight: 700; text-decoration: none; cursor: pointer; transition: background .15s;
        }
        .btn:hover { background: var(--surface-2); }
        .btn-sm { min-height: 34px; padding: 0 10px; font-size: 13.5px; border-width: 1px; }
        .icon-btn { width: 40px; padding: 0; }
        .btn-danger { color: var(--high); border-color: var(--high); background: transparent; }
        .btn-danger:hover { background: var(--high-soft); }

        .tabs { display: flex; gap: 4px; flex-wrap: wrap; padding: 0 24px; background: var(--surface); border-bottom: 1px solid var(--line); position: sticky; top: 64px; z-index: 90; }
        .tabs a { padding: 10px 12px; color: var(--text-3); font-weight: 700; text-decoration: none; border-bottom: 3px solid transparent; }
        .tabs a:hover { color: var(--text); border-bottom-color: var(--line-strong); }

        main { max-width: 1180px; margin: 0 auto; padding: 24px 24px 48px; }
        section { scroll-margin-top: 130px; }
        section + section { margin-top: 28px; }
        .sec-title { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 0 0 12px; }
        .sec-title h2 { margin: 0; font-size: 1.2rem; }
        .muted { color: var(--text-3); font-size: 13.5px; }

        .flash { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 20px; padding: 12px 16px; border-radius: 12px; font-weight: 700; border: 2px solid; }
        .flash.ok { background: var(--safe-soft); color: var(--safe); border-color: var(--safe); }
        .flash.err { background: var(--high-soft); color: var(--high); border-color: var(--high); }
        .flash-btn { margin-left: auto; flex: none; text-decoration: none; }
        .secbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px 16px; margin-bottom: 20px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); font-size: 14.5px; }
        .secbar > span:first-child { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .secbar a { color: var(--accent); font-weight: 700; }

        /* Health cards */
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .card { padding: 16px; background: var(--surface); border: 2px solid var(--line); border-radius: 14px; min-width: 0; }
        .card h3 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); }
        .big { font-size: 1.6rem; font-weight: 700; line-height: 1.15; }
        .kv { display: grid; grid-template-columns: 1fr auto; gap: 3px 10px; margin-top: 8px; font-size: 13.5px; }
        .kv span { color: var(--text-3); }
        .kv b { text-align: right; font-variant-numeric: tabular-nums; }
        .status { display: inline-flex; align-items: center; gap: 8px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--line-strong); flex: none; }
        .dot.ok { background: #16a34a; box-shadow: 0 0 0 4px var(--safe-soft); }
        .dot.bad { background: #dc2626; box-shadow: 0 0 0 4px var(--high-soft); }

        /* Alerts */
        .alerts { display: grid; gap: 8px; }
        .al { --lc: #0284c7; display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; background: var(--surface); border: 1px solid var(--line); border-left: 5px solid var(--lc); border-radius: 10px; }
        .al[data-level="warning"] { --lc: #d97706; }
        .al[data-level="severe"] { --lc: #dc2626; }
        .al-main { flex: 1; min-width: 0; }
        .al b { display: block; }
        .al p { margin: 2px 0 0; font-size: 13.5px; color: var(--text-2); }
        .tag { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; background: var(--accent-soft); color: var(--accent); }
        .tag.warning { background: var(--med-soft); color: var(--med); }
        .tag.severe { background: var(--high-soft); color: var(--high); }
        .tag.grey { background: var(--none-soft); color: var(--none); }
        .tag.ok { background: var(--safe-soft); color: var(--safe); }
        .empty { padding: 16px; text-align: center; color: var(--text-2); background: var(--surface); border: 2px dashed var(--line); border-radius: 12px; }

        /* Tables */
        .panel { background: var(--surface); border: 2px solid var(--line); border-radius: 14px; overflow: hidden; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 14px; font-size: 13.5px; font-weight: 700; color: var(--text-2); background: var(--surface-2); border-bottom: 2px solid var(--line); white-space: nowrap; }
        td { padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 14px; }
        tbody tr:last-child td { border-bottom: 0; }
        th.right, td.right { text-align: right; }
        .person { display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .avatar { width: 34px; height: 34px; flex: none; border-radius: 50%; object-fit: cover; display: grid; place-items: center; font-weight: 700; background: var(--accent-soft); color: var(--accent); border: 2px solid var(--accent); }
        .inline { display: inline-flex; gap: 6px; align-items: center; margin: 0; }
        select { min-height: 34px; padding: 0 8px; border: 1px solid var(--line-strong); border-radius: 8px; background: var(--surface); color: var(--text); }
        .protected { display: inline-flex; align-items: center; gap: 6px; color: var(--text-3); }
        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        @media (max-width: 980px) { .cards { grid-template-columns: 1fr 1fr; } .split { grid-template-columns: 1fr; } }
        @media (max-width: 640px) {
            .topbar { padding: 10px 16px; } .tabs { padding: 0 10px; top: 0; position: static; }
            main { padding: 18px 16px 40px; } .cards { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
        @media (forced-colors: active) { .tag, .card, .panel, .dot { border: 1px solid CanvasText; } }
    </style>
</head>
<body>
    <a class="skip" href="#main">Skip to main content</a>

    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-wave" viewBox="0 0 24 24"><path d="M2 9c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5M2 16c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5"/></symbol>
        <symbol id="i-map" viewBox="0 0 24 24"><path d="M9 4L3 6.5v13L9 17l6 3 6-2.5v-13L15 7 9 4zM9 4v13M15 7v13"/></symbol>
        <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M16 8l4 4-4 4M20 12H9"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.01"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></symbol>
        <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.9-3.5M4 4v4h4M4 13a8 8 0 0 0 14.9 3.5M20 20v-4h-4"/></symbol>
    </svg>

    <header class="topbar">
        <div class="brand">
            <div class="logo" aria-hidden="true"><svg class="i"><use href="#i-wave"/></svg></div>
            <div>
                <h1>System Console</h1>
                <small>Nairobi Flood DSS · {{ Auth::user()->name }}, administrator</small>
            </div>
        </div>
        <nav class="actions" aria-label="Console actions">
            <a href="/" class="btn"><svg class="i" aria-hidden="true"><use href="#i-map"/></svg> Flood map</a>
            <button type="button" class="btn icon-btn" id="theme-btn" onclick="toggleTheme()" aria-label="Switch to dark theme">
                <svg class="i" id="theme-icon"><use href="#i-moon"/></svg>
            </button>
            <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                @csrf
                <button type="submit" class="btn"><svg class="i" aria-hidden="true"><use href="#i-logout"/></svg> Sign out</button>
            </form>
        </nav>
    </header>
    <nav class="tabs" aria-label="Console sections">
        <a href="#health">System health</a>
        <a href="#alerts">Alerts <span class="tag grey" id="alert-count-tab" hidden></span></a>
        <a href="#advisories">Advisories</a>
        <a href="#users">Users &amp; roles</a>
        <a href="#audit">Audit log</a>
    </nav>

    <main id="main">
        @if (session('status'))
            <div class="flash ok" role="status"><svg class="i" aria-hidden="true"><use href="#i-check"/></svg><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert"><svg class="i" aria-hidden="true"><use href="#i-alert"/></svg><span>{{ session('error') }}</span>
                @if (session('reconfirm_needed'))
                    <a class="btn btn-sm flash-btn" href="{{ route('auth.reconfirm', ['return' => '/admin/dashboard#users']) }}">Confirm with Google</a>
                @endif
            </div>
        @endif

        @php $confirmedUntil = \App\Http\Middleware\RequireRecentSignIn::confirmedUntil(request()); @endphp
        <div class="secbar" role="note" aria-label="Session security">
            <span><svg class="i" aria-hidden="true"><use href="#i-lock"/></svg>
                @if ($confirmedUntil)
                    You can change roles and delete accounts until <b>{{ \Illuminate\Support\Carbon::createFromTimestamp($confirmedUntil)->timezone('Africa/Nairobi')->format('H:i') }}</b>
                    <span class="muted">({{ config('auth.reconfirm_minutes') }} minutes after your last Google sign-in)</span>
                @else
                    Changing roles and deleting accounts is locked. <a href="{{ route('auth.reconfirm', ['return' => '/admin/dashboard#users']) }}">Confirm it's you with Google</a> to unlock it for {{ config('auth.reconfirm_minutes') }} minutes.
                @endif
            </span>
            <span class="muted">Signed in with Google · signs out after {{ config('auth.staff_idle_minutes') }} min idle</span>
        </div>

        @php
            $m = $health['model'] ?? [];
            $mm = $m['model'] ?? [];
            $rq = $health['requests'];
            $wx = $health['weather'];
            // Under a second in milliseconds ("40 ms"), otherwise seconds ("1.3 s"); same as secs() in the script
            $dur = fn ($ms) => $ms === null ? '—' : ($ms < 1000 ? $ms . ' ms' : number_format($ms / 1000, 1) . ' s');
        @endphp

        <!-- SYSTEM HEALTH -->
        <section id="health" aria-labelledby="health-h">
            <div class="sec-title">
                <h2 id="health-h">System health</h2>
                <span class="muted">Refreshes every 30 s · <span id="health-checked">checked {{ isset($m['checked_at']) ? \Illuminate\Support\Carbon::parse($m['checked_at'])->diffForHumans() : 'never' }}</span>
                    <button class="btn btn-sm" type="button" onclick="refreshHealth()" aria-label="Check now"><svg class="i"><use href="#i-refresh"/></svg></button></span>
            </div>
            <div class="cards">
                <div class="card">
                    <h3>Model server</h3>
                    <div class="big status"><span class="dot {{ !empty($m['online']) ? 'ok' : 'bad' }}" id="h-dot"></span><span id="h-status">{{ !empty($m['online']) ? 'Online' : 'Offline' }}</span></div>
                    <div class="kv">
                        <span>Health check</span><b id="h-latency">{{ isset($m['latency_ms']) ? $m['latency_ms'] . ' ms' : '—' }}</b>
                        <span>Problem</span><b id="h-error">{{ $m['error'] ?? 'None' }}</b>
                    </div>
                </div>
                <div class="card">
                    <h3>Model in service</h3>
                    <div class="big" id="h-iou">{{ isset($mm['test_iou']) ? number_format($mm['test_iou'] * 100, 1) . '%' : '—' }}</div>
                    <div class="muted">IoU on unseen areas</div>
                    <div class="kv">
                        <span>Unseen storms</span><b id="h-iou2">{{ isset($mm['test_iou_unseen_storms']) ? number_format($mm['test_iou_unseen_storms'] * 100, 1) . '%' : '—' }}</b>
                        <span>Flow speed output</span><b id="h-dv">{{ ($m['dv_available'] ?? null) ? 'Yes' : 'No' }}</b>
                        <span>Inputs</span><b>{{ isset($mm['inputs']) ? count($mm['inputs']) : '—' }}</b>
                    </div>
                </div>
                <div class="card">
                    <h3>Map requests</h3>
                    <div class="big" id="h-median">{{ $dur($rq['median_ms']) }}</div>
                    <div class="muted">median response, last <span id="h-count">{{ $rq['count'] }}</span></div>
                    <div class="kv">
                        <span>95th percentile</span><b id="h-p95">{{ $dur($rq['p95_ms']) }}</b>
                        <span>Failed</span><b id="h-fail">{{ $rq['failures'] }}</b>
                    </div>
                </div>
                <div class="card">
                    <h3>Rain, Nairobi CBD</h3>
                    <div class="big" id="h-rain">{{ $wx ? $wx['now_mmhr'] . ' mm/h' : '—' }}</div>
                    <div class="muted">last hour (Open-Meteo)</div>
                    <div class="kv">
                        <span>Next 24 h peak</span><b id="h-peak">{{ $wx ? $wx['peak_mmhr'] . ' mm/h' : '—' }}</b>
                        <span>Alert level</span><b>{{ \App\Services\SystemMonitor::RAIN_WARNING_MMHR }} mm/h</b>
                    </div>
                </div>
            </div>
        </section>

        <!-- ALERTS -->
        <section id="alerts" aria-labelledby="alerts-h">
            <div class="sec-title">
                <h2 id="alerts-h">Open alerts</h2>
                <span class="muted">System and weather alerts are raised and cleared automatically. Acknowledging records who has seen them.</span>
            </div>
            <div class="alerts" id="alert-list"><div class="empty">Loading…</div></div>
        </section>

        <!-- ADVISORIES -->
        <section id="advisories" aria-labelledby="adv-h">
            <div class="sec-title">
                <h2 id="adv-h">Public advisories</h2>
                <span class="muted">Issued by responders from the flood map. You can withdraw any advisory.</span>
            </div>
            <div class="panel">
                @if($advisories->count())
                    <div class="table-wrap"><table>
                        <caption class="sr-only">Recent public advisories</caption>
                        <thead><tr><th>Status</th><th>Level</th><th>Headline</th><th>Areas</th><th>Issued by</th><th>Issued</th><th>Until</th><th class="right">Action</th></tr></thead>
                        <tbody>
                        @foreach($advisories as $a)
                            @php
                                $state = $a->resolved_at ? 'Withdrawn' : (($a->expires_at && $a->expires_at->isPast()) ? 'Expired' : 'Active');
                            @endphp
                            <tr>
                                <td><span class="tag {{ $state === 'Active' ? 'ok' : 'grey' }}">{{ $state }}</span></td>
                                <td><span class="tag {{ $a->level }}">{{ $a->level }}</span></td>
                                <td><b>{{ $a->title }}</b></td>
                                <td>{{ implode(', ', $a->areas ?? []) }}</td>
                                <td>{{ $a->creator?->name ?? '—' }}</td>
                                <td><time datetime="{{ $a->created_at->toIso8601String() }}">{{ $a->created_at->timezone('Africa/Nairobi')->format('d M H:i') }}</time></td>
                                <td>{{ $a->expires_at?->timezone('Africa/Nairobi')->format('d M H:i') ?? '—' }}</td>
                                <td class="right">
                                    @if($state === 'Active')
                                        <form method="POST" action="{{ route('admin.advisories.withdraw', $a) }}" class="inline confirm-form" data-confirm="Withdraw the advisory &quot;{{ $a->title }}&quot;? It disappears from everyone's map.">
                                            @csrf
                                            <button class="btn btn-sm btn-danger">Withdraw</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @else
                    <div class="empty" style="border:0">No advisories have been issued yet.</div>
                @endif
            </div>
        </section>

        <!-- USERS -->
        <section id="users" aria-labelledby="users-h">
            <div class="sec-title">
                <h2 id="users-h">Users &amp; roles</h2>
                <span class="muted">
                    {{ $totalUsers }} accounts · {{ $roleCounts['responder'] ?? 0 }} responders · {{ $roleCounts['user'] ?? 0 }} residents · {{ number_format($traffic) }} visits in the last 30 days
                </span>
            </div>
            <p class="muted" style="margin:-4px 0 12px">Residents sign in with Google and can save places. Make county or Red Cross staff <b>responders</b> so they can issue advisories and use the response tools.</p>
            <div class="panel">
                <div class="table-wrap"><table>
                    <caption class="sr-only">User accounts with role and actions</caption>
                    <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Joined</th><th class="right">Action</th></tr></thead>
                    <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <span class="person">
                                    @if($user->avatar)
                                        <img src="{{ $user->avatar }}" class="avatar" alt="" referrerpolicy="no-referrer">
                                    @else
                                        <span class="avatar" aria-hidden="true">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                                    @endif
                                    <span>{{ $user->name }}</span>
                                </span>
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @if($user->isAdmin())
                                    <span class="tag">Admin</span>
                                @else
                                    <form method="POST" action="{{ route('admin.users.role', $user) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <label class="sr-only" for="role-{{ $user->id }}">Role for {{ $user->name }}</label>
                                        <select id="role-{{ $user->id }}" name="role" onchange="this.form.requestSubmit()">
                                            <option value="user" @selected($user->role === 'user')>Resident</option>
                                            <option value="responder" @selected($user->role === 'responder')>Responder</option>
                                        </select>
                                        <noscript><button class="btn btn-sm">Save</button></noscript>
                                    </form>
                                @endif
                            </td>
                            <td><time datetime="{{ $user->created_at->toDateString() }}">{{ $user->created_at->format('d M Y') }}</time></td>
                            <td class="right">
                                @if (!$user->isAdmin())
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="inline confirm-form" data-confirm="Delete {{ $user->name }}'s account? This cannot be undone.">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" aria-label="Delete {{ $user->name }}"><svg class="i" aria-hidden="true"><use href="#i-trash"/></svg> Delete</button>
                                    </form>
                                @else
                                    <span class="protected"><svg class="i" aria-hidden="true"><use href="#i-lock"/></svg> Protected</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        </section>

        <!-- AUDIT -->
        <section id="audit" aria-labelledby="audit-h">
            <div class="sec-title">
                <h2 id="audit-h">Audit log</h2>
                <span class="muted">Every action that changes what other people see, newest first.</span>
            </div>
            <div class="panel">
                @if($audit->count())
                    <div class="table-wrap"><table>
                        <caption class="sr-only">Audit log</caption>
                        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                        @foreach($audit as $log)
                            <tr>
                                <td style="white-space:nowrap"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->timezone('Africa/Nairobi')->format('d M H:i') }}</time></td>
                                <td>{{ $log->user?->name ?? 'System' }} @if($log->user)<span class="muted">({{ $log->user->roleLabel() }})</span>@endif</td>
                                <td><span class="tag grey">{{ $log->action }}</span></td>
                                <td>{{ $log->summary }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @else
                    <div class="empty" style="border:0">Nothing recorded yet.</div>
                @endif
            </div>
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
        function toggleTheme() { applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); }
        applyTheme(document.documentElement.getAttribute('data-theme'));

        document.querySelectorAll('.confirm-form').forEach(function (form) {
            form.addEventListener('submit', function (e) { if (!confirm(form.dataset.confirm)) e.preventDefault(); });
        });

        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
        function secs(ms) { return ms == null ? '—' : ms < 1000 ? Math.round(ms) + ' ms' : (ms / 1000).toFixed(1) + ' s'; }
        function set(id, text) { document.getElementById(id).textContent = text; }

        async function refreshHealth() {
            try {
                const res = await fetch('{{ route('admin.health') }}', { headers: { Accept: 'application/json' } });
                if (signedOut(res)) return;
                if (!res.ok) return;
                const h = await res.json(), m = h.model || {}, mm = m.model || {}, r = h.requests || {}, w = h.weather;
                document.getElementById('h-dot').className = 'dot ' + (m.online ? 'ok' : 'bad');
                set('h-status', m.online ? 'Online' : 'Offline');
                set('h-latency', m.latency_ms != null ? m.latency_ms + ' ms' : '—');
                set('h-error', m.error || 'None');
                set('h-iou', mm.test_iou != null ? (mm.test_iou * 100).toFixed(1) + '%' : '—');
                set('h-iou2', mm.test_iou_unseen_storms != null ? (mm.test_iou_unseen_storms * 100).toFixed(1) + '%' : '—');
                set('h-dv', m.dv_available ? 'Yes' : 'No');
                set('h-median', secs(r.median_ms)); set('h-p95', secs(r.p95_ms)); set('h-count', r.count); set('h-fail', r.failures);
                if (w) { set('h-rain', w.now_mmhr + ' mm/h'); set('h-peak', w.peak_mmhr + ' mm/h'); }
                set('health-checked', 'checked just now');
            } catch (e) {}
        }

        // Open alerts: same feed as the flood map's bell (admins see system, weather and advisories)
        async function loadAlerts() {
            const box = document.getElementById('alert-list');
            let data;
            try {
                const res = await fetch('/api/alerts', { headers: { Accept: 'application/json' } });
                if (signedOut(res)) return;
                data = await res.json();
            } catch (e) { box.innerHTML = '<div class="empty">Could not load alerts.</div>'; return; }
            const list = (data.alerts || []).filter(a => a.audience !== 'user')
                .sort((a, b) => (a.read - b.read) || ({ severe: 2, warning: 1, info: 0 }[b.level] - { severe: 2, warning: 1, info: 0 }[a.level]));
            const unread = list.filter(a => !a.read).length;
            const tab = document.getElementById('alert-count-tab');
            tab.hidden = !unread; tab.textContent = unread;
            box.innerHTML = list.length ? list.map(a => `
                <div class="al" data-level="${esc(a.level)}">
                    <div class="al-main">
                        <span class="tag ${esc(a.level)}">${esc(a.level)}</span> <span class="muted">${esc(a.kind)}${a.issued_by ? ' by ' + esc(a.issued_by) : ''} · ${new Date(a.created_at).toLocaleString('en-KE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                        <b>${esc(a.title)}</b><p>${esc(a.body)}</p>
                        ${a.areas && a.areas.length ? `<p class="muted">Areas: ${a.areas.map(esc).join(', ')}</p>` : ''}
                    </div>
                    ${a.read ? '<span class="tag ok">Acknowledged</span>' : `<button class="btn btn-sm" onclick="ack(${a.id})">Acknowledge</button>`}
                </div>`).join('') : '<div class="empty">No open alerts. Everything is running normally.</div>';
        }
        async function ack(id) {
            await fetch(`/api/alerts/${id}/ack`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } });
            loadAlerts();
        }

        // Signed out (idle timeout or elsewhere): go to the sign-in page, which explains why
        function signedOut(res) {
            if (res.status !== 401 && res.status !== 403 && !res.redirected) return false;
            location.href = '/';   // the map explains why (e.g. signed out after 30 min idle)
            return true;
        }
        // Real use (clicks, typing, scrolling) keeps the session alive; background polling does not
        let lastPing = Date.now();
        ['pointerdown', 'keydown', 'wheel', 'scroll'].forEach(ev => addEventListener(ev, () => {
            if (Date.now() - lastPing < 4 * 60 * 1000) return;
            lastPing = Date.now();
            fetch('/auth/activity', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } })
                .then(signedOut).catch(() => {});
        }, { passive: true }));

        loadAlerts();
        setInterval(() => { refreshHealth(); loadAlerts(); }, 30000);
    </script>
</body>
</html>
