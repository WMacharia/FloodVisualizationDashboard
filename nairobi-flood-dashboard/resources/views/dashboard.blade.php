<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Nairobi Flood Decision Support System</title>

    <!-- Apply saved/system theme before first paint to avoid a flash -->
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
    <!-- Atkinson Hyperlegible: designed by the Braille Institute for maximum character legibility -->
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        /* =========================================================
           DESIGN TOKENS  (text/background pairs meet WCAG AA 4.5:1)
        ========================================================= */
        :root, [data-theme="light"] {
            --bg: #eef2f7;
            --surface: #ffffff;
            --surface-2: #f3f6fa;
            --surface-3: #e8edf4;
            --line: #d5dde8;
            --line-strong: #64748b;
            --text: #0f172a;
            --text-2: #1e293b;
            --text-3: #52617a;
            --accent: #0369a1;
            --accent-ink: #ffffff;
            --accent-soft: #e0f2fe;
            --bar: #0b2540;
            --bar-ink: #f1f5f9;
            --bar-ink-2: #a9bfd6;

            --high: #b91c1c;  --high-soft: #fee2e2;
            --med:  #92400e;  --med-soft:  #fef3c7;
            --low:  #075985;  --low-soft:  #e0f2fe;
            --safe: #166534;  --safe-soft: #dcfce7;
            --none: #334155;  --none-soft: #e2e8f0;

            --shadow: 0 8px 24px -8px rgba(15, 23, 42, .25);
            --zone-stroke: #ffffff;
        }
        [data-theme="dark"] {
            --bg: #0b1220;
            --surface: #111b2e;
            --surface-2: #17233a;
            --surface-3: #1d2b45;
            --line: #2a3a56;
            --line-strong: #8da2c0;
            --text: #f8fafc;
            --text-2: #e2e8f0;
            --text-3: #a8b8d0;
            --accent: #38bdf8;
            --accent-ink: #04111f;
            --accent-soft: #0c2a40;
            --bar: #060d19;
            --bar-ink: #f1f5f9;
            --bar-ink-2: #9fb3cc;

            --high: #fca5a5;  --high-soft: #4a1717;
            --med:  #fcd34d;  --med-soft:  #43300a;
            --low:  #7dd3fc;  --low-soft:  #0c2a40;
            --safe: #86efac;  --safe-soft: #0f3320;
            --none: #cbd5e1;  --none-soft: #1f2c44;

            --shadow: 0 12px 32px -10px rgba(0, 0, 0, .7);
            --zone-stroke: #0b1220;
        }
        :root {
            --top: 60px;
            --rail: 76px;
            --panel-w: 380px;
            --radius: 12px;
            --ease: cubic-bezier(.2, .8, .2, 1);
            /* Depth ramp, identical to model_api.py RAMP_RGB: 0.15 / 0.3 / 0.6 / 1.2 / 2 m */
            --d1: #9ecae1; --d2: #ffed6f; --d3: #fd8d3c; --d4: #d7191c; --d5: #67000d;
            --ramp: linear-gradient(to right, var(--d1), var(--d2), var(--d3), var(--d4), var(--d5));
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            line-height: 1.45;
            background: var(--bg);
            color: var(--text);
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        button, input, select { font-family: inherit; font-size: inherit; color: inherit; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        [hidden] { display: none !important; }
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .skip { position: fixed; left: 12px; top: -60px; z-index: 5000; padding: 12px 16px; background: var(--accent); color: var(--accent-ink); font-weight: 700; border-radius: 10px; text-decoration: none; }
        .skip:focus { top: 12px; }
        svg.i { width: 20px; height: 20px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* =========================================================
           APP SHELL: top bar / rail / panel / map
        ========================================================= */
        .app {
            height: 100vh; height: 100dvh;
            display: grid;
            grid-template-columns: var(--rail) var(--panel-w) 1fr;
            grid-template-rows: var(--top) 1fr;
            grid-template-areas: "top top top" "rail panel map";
            transition: grid-template-columns .3s var(--ease);
        }
        .app.panel-closed { grid-template-columns: var(--rail) 0 1fr; }

        /* ---------- Top bar ---------- */
        .topbar {
            grid-area: top; z-index: 1200; display: flex; align-items: center; gap: 14px; padding: 0 14px 0 0;
            background: var(--bar); color: var(--bar-ink); position: relative;
        }
        .brand { width: var(--rail); display: grid; place-items: center; flex: none; color: #7dd3fc; }
        .brand svg { width: 30px; height: 30px; }
        .brand-text { margin-left: -6px; min-width: 0; flex: none; }
        .brand-text b { display: block; font-size: 16px; line-height: 1.15; }
        .brand-text span { font-size: 12px; color: var(--bar-ink-2); }
        .divider { width: 1px; height: 32px; background: rgba(255,255,255,.14); flex: none; }

        .scenario { display: flex; align-items: center; gap: 8px; min-width: 0; position: relative; }
        .sc-label { font-size: 12px; color: var(--bar-ink-2); text-transform: uppercase; letter-spacing: .06em; font-weight: 700; white-space: nowrap; }
        .presets { display: flex; gap: 4px; padding: 3px; background: rgba(255,255,255,.07); border-radius: 10px; }
        .preset {
            border: 0; background: transparent; color: var(--bar-ink); border-radius: 8px; padding: 5px 10px; cursor: pointer;
            display: flex; flex-direction: column; align-items: flex-start; line-height: 1.15; min-height: 40px; justify-content: center; white-space: nowrap;
        }
        .preset b { font-size: 13px; }
        .preset small { font-size: 11px; color: var(--bar-ink-2); }
        .preset:hover { background: rgba(255,255,255,.1); }
        .preset[aria-pressed="true"] { background: #f1f5f9; color: #0b2540; }
        .preset[aria-pressed="true"] small { color: #3b5576; }
        .custom-btn { gap: 2px; }
        .custom-btn b::after { content: ' ▾'; font-size: 10px; }

        .popover {
            position: absolute; top: calc(100% + 10px); left: 0; z-index: 1500; width: 340px;
            background: var(--surface); color: var(--text); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); padding: 16px;
        }
        .popover h3 { margin: 0 0 2px; font-size: 15px; }
        .popover .muted { margin: 0 0 12px; }

        .weather {
            display: flex; align-items: center; gap: 10px; padding: 4px 6px 4px 12px; min-height: 44px;
            background: rgba(255,255,255,.07); border-radius: 10px; white-space: nowrap; flex: none;
        }
        .weather .i { color: #7dd3fc; }
        .weather b { font-size: 13px; display: block; line-height: 1.15; }
        .weather small { font-size: 11px; color: var(--bar-ink-2); display: block; }
        .weather .bar-btn { margin-left: 4px; }
        .spacer { flex: 1; }
        .bar-btn {
            min-height: 36px; padding: 0 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,.25);
            background: transparent; color: var(--bar-ink); font-weight: 700; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; text-decoration: none;
        }
        .bar-btn:hover { background: rgba(255,255,255,.1); }
        .bar-icon { width: 40px; padding: 0; justify-content: center; }
        .account { display: flex; align-items: center; gap: 8px; }
        .avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; display: grid; place-items: center; font-weight: 700; background: #0c2a40; color: #7dd3fc; border: 2px solid #7dd3fc; flex: none; }
        .acct-name { font-size: 13px; font-weight: 700; line-height: 1.15; }
        .acct-role { font-size: 11px; color: var(--bar-ink-2); }
        .signin svg { width: 18px; height: 18px; background: #fff; border-radius: 50%; padding: 2px; }

        .progress { position: absolute; left: 0; right: 0; bottom: -3px; height: 3px; overflow: hidden; opacity: 0; transition: opacity .2s; }
        .progress.on { opacity: 1; }
        .progress::after { content: ''; position: absolute; top: 0; bottom: 0; width: 35%; background: #38bdf8; animation: slide 1.2s ease-in-out infinite; }
        @keyframes slide { from { left: -35%; } to { left: 100%; } }

        /* ---------- Rail ---------- */
        .rail {
            grid-area: rail; z-index: 1100; display: flex; flex-direction: column; gap: 4px; padding: 10px 8px;
            background: var(--surface); border-right: 1px solid var(--line);
        }
        .rail-btn {
            width: 100%; min-height: 60px; border: 0; border-radius: 10px; background: transparent; cursor: pointer; color: var(--text-3);
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-size: 11.5px; font-weight: 700; position: relative;
        }
        .rail-btn .i { width: 22px; height: 22px; }
        .rail-btn:hover { background: var(--surface-2); color: var(--text); }
        .rail-btn[aria-selected="true"] { background: var(--accent-soft); color: var(--accent); }
        .rail-btn[aria-selected="true"]::before { content: ''; position: absolute; left: -8px; top: 12px; bottom: 12px; width: 4px; border-radius: 0 4px 4px 0; background: var(--accent); }
        .rail-btn .dot { position: absolute; top: 9px; right: 14px; width: 9px; height: 9px; border-radius: 50%; background: #dc2626; border: 2px solid var(--surface); }
        .rail-foot { margin-top: auto; }

        /* ---------- Panel ---------- */
        .panel {
            grid-area: panel; z-index: 1000; min-width: 0; overflow: hidden;
            background: var(--surface); border-right: 1px solid var(--line);
            display: flex; flex-direction: column;
        }
        .panel-inner { width: var(--panel-w); height: 100%; display: flex; flex-direction: column; }
        .panel-head { display: flex; align-items: center; gap: 10px; padding: 14px 12px 10px 18px; border-bottom: 1px solid var(--line); }
        .panel-head h1 { flex: 1; margin: 0; font-size: 17px; line-height: 1.25; }
        .panel-head .sub { display: block; font-size: 12.5px; font-weight: 400; color: var(--text-3); }
        .icon-btn {
            flex: none; width: 36px; height: 36px; display: grid; place-items: center;
            background: transparent; border: 1px solid var(--line); border-radius: 9px; cursor: pointer; color: var(--text-2);
        }
        .icon-btn:hover { background: var(--surface-2); border-color: var(--line-strong); }
        .panel-body { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 14px 16px 18px; scrollbar-width: thin; }
        .module[hidden] { display: none; }

        /* ---------- Map ---------- */
        .map-wrap { grid-area: map; position: relative; min-width: 0; }
        #map { position: absolute; inset: 0; background: var(--bg); }
        .leaflet-container { font-family: inherit; background: var(--bg); }
        .leaflet-container img { max-width: none !important; max-height: none !important; }
        [data-theme="dark"] .bright-map-labels { filter: brightness(2.2) contrast(1.3) drop-shadow(0 0 3px rgba(0,0,0,.9)); }
        .leaflet-bar { border: 1px solid var(--line) !important; border-radius: 10px !important; overflow: hidden; box-shadow: var(--shadow) !important; }
        .leaflet-bar a, .leaflet-bar a:hover { width: 40px; height: 40px; line-height: 38px; font-size: 20px; background: var(--surface); color: var(--text); border-bottom: 1px solid var(--line); }
        .leaflet-bar a:hover { background: var(--surface-2); }
        .leaflet-control-attribution { background: var(--surface) !important; color: var(--text-3) !important; font-size: 10.5px; }
        .leaflet-control-attribution a { color: var(--accent) !important; }
        .leaflet-tooltip.zone-tip { background: var(--surface); color: var(--text); border: 1px solid var(--line); border-radius: 10px; box-shadow: var(--shadow); padding: 8px 10px; font-size: 13px; }
        .leaflet-tooltip.zone-tip::before { display: none; }

        .map-pin { width: 30px; height: 30px; border-radius: 50%; border: 3px solid #fff; display: grid; place-items: center; font-weight: 700; font-size: 14px; color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,.5); }
        .map-pin.origin { background: #15803d; }
        .map-pin.dest { background: #b91c1c; }
        .spot-pin { width: 22px; height: 22px; border-radius: 50%; background: var(--accent); border: 4px solid #fff; box-shadow: 0 0 0 3px var(--accent), 0 4px 12px rgba(0,0,0,.4); }
        .hs-pin { width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; background: #0b2540; color: #fff; font-weight: 700; font-size: 13px; border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.45); transform: rotate(45deg); }
        .hs-pin span { transform: rotate(-45deg); }

        /* Floating map cards */
        .map-card { position: absolute; z-index: 900; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); }
        .layers-btn { top: 12px; right: 12px; display: flex; align-items: center; gap: 8px; min-height: 42px; padding: 0 14px; font-weight: 700; cursor: pointer; }
        .layers-btn:hover { background: var(--surface-2); }
        .layers-card { top: 62px; right: 12px; width: 300px; padding: 14px 16px; max-height: calc(100% - 140px); overflow-y: auto; }
        .layers-card h3 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
        .layers-card section + section { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line); }
        .opt { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; cursor: pointer; }
        .opt input { width: 17px; height: 17px; margin: 2px 0 0; accent-color: var(--accent); flex: none; }
        .opt b { display: block; font-size: 14px; }
        .opt small { display: block; font-size: 12px; color: var(--text-3); line-height: 1.3; }
        .seg { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 3px; padding: 3px; background: var(--surface-3); border-radius: 9px; }
        .seg button { border: 0; background: transparent; border-radius: 7px; min-height: 32px; font-weight: 700; font-size: 13px; cursor: pointer; color: var(--text-3); }
        .seg button[aria-pressed="true"] { background: var(--surface); color: var(--text); box-shadow: 0 1px 3px rgba(0,0,0,.15); }

        .legend { left: 12px; bottom: 26px; width: 280px; padding: 12px 14px; font-size: 13px; }
        .legend summary { font-weight: 700; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .legend summary::-webkit-details-marker { display: none; }
        .legend summary::after { content: '▾'; color: var(--text-3); }
        .legend:not([open]) summary::after { content: '▸'; }
        .legend[open] summary { margin-bottom: 8px; }
        .lg-row { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: center; padding: 2px 0; color: var(--text-2); }
        .sw { width: 22px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.15); }
        .lg-row em { font-style: normal; color: var(--text-3); font-size: 12px; }
        .ramp { height: 12px; border-radius: 3px; background: var(--ramp); border: 1px solid rgba(0,0,0,.15); }
        .ramp-labels { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-3); margin-top: 3px; }
        .lg-note { font-size: 12px; color: var(--text-3); margin: 8px 0 0; line-height: 1.35; }
        .ln { width: 22px; height: 0; border-top: 5px solid #16a34a; border-radius: 3px; }
        .ln.direct { border-top: 4px dashed #8b5cf6; }
        .ln.hazard { border-top: 6px solid #dc2626; }

        .map-hint {
            top: 12px; left: 50%; transform: translateX(-50%); padding: 8px 14px; font-size: 13px; font-weight: 700;
            display: flex; align-items: center; gap: 8px; white-space: nowrap;
        }
        .toast {
            left: 50%; bottom: 28px; transform: translateX(-50%); padding: 10px 14px; max-width: min(520px, calc(100% - 40px));
            display: flex; gap: 10px; align-items: flex-start; font-size: 14px; z-index: 1000;
        }
        .toast[data-tone="error"] { border-color: var(--high); }
        .toast[data-tone="error"] .i { color: var(--high); }
        .toast .icon-btn { width: 28px; height: 28px; border: 0; margin: -3px -6px -3px 0; }

        /* =========================================================
           PANEL CONTENT
        ========================================================= */
        .muted { color: var(--text-3); font-size: 13px; }
        .sec { margin-top: 18px; }
        .sec:first-child { margin-top: 0; }
        .sec-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 8px; }
        .sec-head h2 { margin: 0; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
        .link { background: none; border: 0; padding: 0; color: var(--accent); font-weight: 700; font-size: 13px; cursor: pointer; }
        .link:hover { text-decoration: underline; }

        .scenario-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: var(--radius); background: var(--surface-2); border: 1px solid var(--line); }
        .scenario-card .i { color: var(--accent); width: 26px; height: 26px; }
        .scenario-card b { display: block; font-size: 15px; }
        .scenario-card span { font-size: 12.5px; color: var(--text-3); }

        .kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .kpi { padding: 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--surface); }
        .kpi-label { font-size: 12px; color: var(--text-3); font-weight: 700; }
        .kpi-value { font-size: 24px; font-weight: 700; line-height: 1.15; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .kpi-value small { font-size: 13px; font-weight: 700; color: var(--text-3); }
        .kpi-sub { font-size: 12px; color: var(--text-3); margin-top: 2px; line-height: 1.3; }

        .stack { display: flex; height: 14px; border-radius: 4px; overflow: hidden; background: var(--surface-3); }
        .stack i { display: block; height: 100%; }
        .stack-legend { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; margin-top: 8px; font-size: 12.5px; color: var(--text-2); }
        .stack-legend span { display: flex; align-items: center; gap: 6px; }
        .stack-legend .sw { width: 12px; height: 12px; }
        .stack-legend b { margin-left: auto; font-variant-numeric: tabular-nums; }

        .rows { display: flex; flex-direction: column; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
        .row {
            --c: var(--line-strong);
            display: grid; grid-template-columns: 26px 1fr auto; gap: 0 10px; align-items: center; width: 100%; text-align: left;
            padding: 9px 12px; min-height: 52px; background: var(--surface); border: 0; border-bottom: 1px solid var(--line); cursor: pointer;
        }
        .row:last-child { border-bottom: 0; }
        .row:hover { background: var(--surface-2); }
        .row[aria-pressed="true"] { background: var(--accent-soft); }
        .rank { width: 24px; height: 24px; border-radius: 6px; display: grid; place-items: center; font-size: 12px; font-weight: 700; background: var(--c); color: #0f172a; }
        .row-main { min-width: 0; }
        .row-name { font-weight: 700; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row-sub { font-size: 12px; color: var(--text-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row-val { text-align: right; font-weight: 700; font-size: 14px; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .row-val small { display: block; font-size: 11px; font-weight: 400; color: var(--text-3); }
        .row .bar { grid-column: 2 / -1; height: 4px; background: var(--surface-3); border-radius: 4px; overflow: hidden; margin-top: 5px; }
        .row .bar i { display: block; height: 100%; background: var(--c); }
        .empty { padding: 14px; background: var(--surface-2); border-radius: var(--radius); color: var(--text-2); font-size: 14px; }
        .skeleton { height: 64px; border-radius: var(--radius); background: linear-gradient(90deg, var(--surface-2), var(--surface-3), var(--surface-2)); background-size: 200% 100%; animation: shimmer 1.2s linear infinite; }
        @keyframes shimmer { to { background-position: -200% 0; } }

        .search { width: 100%; min-height: 38px; padding: 0 12px; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); margin: 10px 0; }
        .search:focus { outline: 2px solid var(--accent); border-color: var(--accent); }

        .note { margin: 10px 0 0; font-size: 13px; color: var(--med); background: var(--med-soft); border-radius: 9px; padding: 8px 12px; }

        /* Buttons / forms */
        .btn {
            min-height: 42px; padding: 0 16px; border: 1px solid transparent; border-radius: 9px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; white-space: nowrap;
        }
        .btn-block { width: 100%; }
        .btn-primary { background: var(--accent); color: var(--accent-ink); }
        .btn-primary:hover { filter: brightness(1.08); }
        .btn-safe { background: #15803d; color: #fff; }
        [data-theme="dark"] .btn-safe { background: #4ade80; color: #04170f; }
        .btn-outline { background: var(--surface); border-color: var(--line); color: var(--text); }
        .btn-outline:hover { background: var(--surface-2); border-color: var(--line-strong); }
        .btn.is-loading, .bar-btn.is-loading { pointer-events: none; opacity: .8; }
        .btn.is-loading::after, .bar-btn.is-loading::after { content: ''; width: 14px; height: 14px; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-row { display: flex; gap: 8px; }
        .btn-row .btn { flex: 1; }

        .control + .control { margin-top: 14px; }
        .control label { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; font-size: 14px; }
        .control strong { font-variant-numeric: tabular-nums; }
        input[type="range"] {
            -webkit-appearance: none; appearance: none; width: 100%; height: 8px; border-radius: 8px; cursor: pointer;
            background: linear-gradient(to right, var(--accent) var(--p, 50%), var(--line) var(--p, 50%));
        }
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; width: 22px; height: 22px; border-radius: 50%; background: var(--surface); border: 4px solid var(--accent); box-shadow: 0 2px 6px rgba(0,0,0,.3); }
        input[type="range"]::-moz-range-thumb { width: 16px; height: 16px; border-radius: 50%; background: var(--surface); border: 4px solid var(--accent); }

        .field { position: relative; }
        .field label { display: block; font-size: 12px; font-weight: 700; color: var(--text-3); margin-bottom: 4px; }
        .field .badge-ab { position: absolute; left: 10px; bottom: 9px; width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px; color: #fff; pointer-events: none; }
        .badge-ab.a { background: #15803d; } .badge-ab.b { background: #b91c1c; }
        .field input[type="text"] { width: 100%; min-height: 42px; padding: 0 12px 0 42px; background: var(--surface); border: 1px solid var(--line-strong); border-radius: 9px; }
        .field input::placeholder { color: var(--text-3); opacity: 1; }
        .field input:focus { outline: 2px solid var(--accent); border-color: var(--accent); }
        .route-fields { display: grid; grid-template-columns: 1fr 36px; gap: 8px 8px; align-items: end; }
        .route-fields .swap { grid-column: 2; grid-row: 1 / span 2; align-self: center; }
        .suggestions { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 2000; display: none; max-height: 220px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line-strong); border-radius: 10px; box-shadow: var(--shadow); }
        .suggestion-item { padding: 10px 12px; border-bottom: 1px solid var(--line); cursor: pointer; font-size: 14px; }
        .suggestion-item:last-child { border-bottom: 0; }
        .suggestion-item:hover, .suggestion-item:focus { background: var(--accent-soft); }
        .text-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 0; margin-top: 4px; background: none; border: 0; color: var(--accent); font-weight: 700; font-size: 13px; cursor: pointer; }
        .text-btn .i { width: 16px; height: 16px; }

        .veh-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; border: 0; padding: 0; margin: 0; }
        .veh-grid legend { padding: 0; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: var(--text-3); }
        .veh { position: relative; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 2px 8px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; text-align: center; font-size: 11.5px; font-weight: 700; color: var(--text-2); line-height: 1.15; }
        .veh input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
        .veh .i { width: 26px; height: 26px; }
        .veh:hover { border-color: var(--line-strong); }
        .veh:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
        .veh:has(input:focus-visible) { outline: 3px solid var(--accent); outline-offset: 2px; }
        .veh-limit { margin: 8px 0 0; font-size: 12.5px; color: var(--text-3); }

        .result { --c: var(--none); --cs: var(--none-soft); padding: 12px 14px; border-radius: var(--radius); background: var(--cs); border: 1px solid var(--c); }
        .result[data-tone="safe"] { --c: var(--safe); --cs: var(--safe-soft); }
        .result[data-tone="med"]  { --c: var(--med);  --cs: var(--med-soft); }
        .result[data-tone="high"] { --c: var(--high); --cs: var(--high-soft); }
        .result-head { display: flex; gap: 8px; align-items: center; color: var(--c); font-weight: 700; font-size: 15px; }
        .result p { margin: 6px 0 0; font-size: 13.5px; color: var(--text); }
        .metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 10px; }
        .metric { background: var(--surface); border-radius: 8px; padding: 8px; }
        .metric b { display: block; font-size: 16px; font-variant-numeric: tabular-nums; }
        .metric span { font-size: 11.5px; color: var(--text-3); }
        details.fine { margin-top: 10px; font-size: 12.5px; color: var(--text-3); }
        details.fine summary { cursor: pointer; font-weight: 700; color: var(--text-2); }
        details.fine p { margin: 6px 0 0; }

        /* Inspect */
        .spot-head { display: flex; gap: 12px; align-items: flex-start; }
        .depth-badge { flex: none; min-width: 84px; padding: 8px 10px; border-radius: 10px; text-align: center; color: #0f172a; font-weight: 700; }
        .depth-badge b { display: block; font-size: 20px; line-height: 1.1; }
        .depth-badge span { font-size: 11px; }
        .spot-title { font-size: 17px; font-weight: 700; line-height: 1.25; }
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 12px; }
        .stat { background: var(--surface-2); border-radius: 9px; padding: 9px 10px; }
        .stat b { display: block; font-size: 16px; font-variant-numeric: tabular-nums; }
        .stat span { font-size: 11.5px; color: var(--text-3); }
        .zone-card { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); margin-top: 10px; font-size: 13px; }
        .zone-card .sw { width: 18px; height: 18px; margin-top: 2px; flex: none; clip-path: polygon(25% 3%, 75% 3%, 100% 50%, 75% 97%, 25% 97%, 0 50%); border: 0; }
        .veh-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .veh-table td { padding: 7px 4px; border-bottom: 1px solid var(--line); }
        .veh-table tr:last-child td { border-bottom: 0; }
        .veh-table td:first-child { width: 30px; color: var(--text-3); }
        .veh-table .i { width: 20px; height: 20px; }
        .verdict { font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
        .verdict.safe { background: var(--safe-soft); color: var(--safe); }
        .verdict.caution { background: var(--med-soft); color: var(--med); }
        .verdict.unsafe { background: var(--high-soft); color: var(--high); }

        .why-row + .why-row { margin-top: 10px; }
        .why-label { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 4px; font-size: 13px; }
        .why-track { height: 8px; background: var(--surface-3); border-radius: 8px; overflow: hidden; }
        .why-fill { height: 100%; border-radius: 8px; }

        .kv { display: grid; grid-template-columns: 1fr auto; gap: 7px 12px; font-size: 13.5px; }
        .kv span { color: var(--text-3); }
        .kv b { font-variant-numeric: tabular-nums; }
        .bullets { margin: 0; padding-left: 18px; font-size: 13.5px; color: var(--text-2); }
        .bullets li + li { margin-top: 5px; }
        .prose { font-size: 13.5px; color: var(--text-2); margin: 0; }
        .prose + .prose { margin-top: 8px; }

        /* =========================================================
           ALERTS, PLACES, RESPONSE
        ========================================================= */
        .bell { position: relative; }
        .bell .count {
            position: absolute; top: -5px; right: -5px; min-width: 19px; height: 19px; padding: 0 5px; border-radius: 10px;
            background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--bar);
        }
        .alert-pop {
            position: fixed; top: calc(var(--top, 60px) + 6px); right: 12px; z-index: 2000; width: min(420px, calc(100vw - 24px));
            max-height: min(70vh, 640px); overflow: auto; padding: 12px; background: var(--surface); color: var(--text);
            border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow);
        }
        .alert-pop h3 { margin: 0 0 8px; font-size: 15px; display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
        .al { --lc: var(--low); padding: 10px 12px; border: 1px solid var(--line); border-left: 4px solid var(--lc); border-radius: 9px; background: var(--surface); }
        .al + .al { margin-top: 8px; }
        .al[data-level="warning"] { --lc: var(--med); }
        .al[data-level="severe"] { --lc: var(--high); }
        .al[data-unread="true"] { background: var(--surface-2); }
        .al-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .al b { display: block; font-size: 14px; }
        .al p { margin: 4px 0 0; font-size: 13px; color: var(--text-2); }
        .al .btn-row { margin-top: 8px; flex-wrap: wrap; }
        .al .btn { min-height: 34px; padding: 0 10px; font-size: 13px; flex: 0 0 auto; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; background: var(--low-soft); color: var(--low); }
        .tag.warning { background: var(--med-soft); color: var(--med); }
        .tag.severe { background: var(--high-soft); color: var(--high); }
        .adv-banner {
            --lc: var(--med); top: 12px; left: 12px; right: 130px; z-index: 1000; padding: 10px 12px; border-left: 5px solid var(--lc);
            display: flex; align-items: flex-start; gap: 10px; font-size: 13.5px;
        }
        .adv-banner[data-level="severe"] { --lc: var(--high); }
        .adv-banner .i { color: var(--lc); margin-top: 1px; }
        .adv-banner b { display: block; font-size: 14px; }
        .adv-banner .btns { display: flex; gap: 10px; margin-top: 4px; flex-wrap: wrap; }
        /* The full message is in the alerts list; keep the banner short */
        .adv-banner .msg { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; }
        .adv-banner .icon-btn { width: 28px; height: 28px; border: 0; flex: none; margin: -4px -6px 0 auto; }
        .app.has-banner .map-hint { top: 84px; }
        .fld { display: block; margin-top: 10px; }
        .fld > span { display: block; font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        .fld input, .fld select, .fld textarea {
            width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 9px;
            background: var(--surface); color: var(--text); font: inherit; font-size: 14px;
        }
        .fld textarea { min-height: 76px; resize: vertical; }
        .fld input:focus, .fld select:focus, .fld textarea:focus { outline: 2px solid var(--accent); border-color: var(--accent); }
        .chk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 10px; font-size: 13px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 9px; max-height: 168px; overflow: auto; }
        .chk-grid label { display: flex; align-items: center; gap: 6px; min-height: 28px; cursor: pointer; line-height: 1.2; }
        .chk-grid input { width: 16px; height: 16px; min-height: 0; padding: 0; margin: 0; flex: none; accent-color: var(--accent); }
        .wx-chart { display: block; width: 100%; height: 90px; }
        .row .tag { margin-left: 4px; vertical-align: 1px; }
        .row-actions { grid-column: 2 / -1; display: flex; gap: 12px; margin-top: 4px; }

        /* =========================================================
           SMALL SCREENS: bottom navigation + bottom sheet
        ========================================================= */
        @media (max-width: 1520px) {
            .acct-name, .acct-role { display: none; }
        }
        @media (max-width: 1180px) {
            .weather .wx-text, .sc-label { display: none; }
        }
        @media (max-width: 980px) {
            .brand-text span, .acct-name, .acct-role { display: none; }
            .presets .preset:not(.custom-btn) small { display: none; }
        }
        @media (max-width: 820px) {
            :root { --top: 56px; --rail: 0px; }
            .app, .app.panel-closed { grid-template-columns: 1fr; grid-template-rows: var(--top) 1fr; grid-template-areas: "top" "map"; }
            .brand { width: 52px; }
            .brand-text, .divider, .weather, .presets .preset:not(.custom-btn) { display: none; }
            .rail { position: fixed; left: 0; right: 0; bottom: 0; flex-direction: row; padding: 4px 6px calc(4px + env(safe-area-inset-bottom, 0px)); border-right: 0; border-top: 1px solid var(--line); }
            .rail-btn { min-height: 54px; }
            .rail-btn[aria-selected="true"]::before { left: 18px; right: 18px; top: -4px; bottom: auto; width: auto; height: 3px; border-radius: 0 0 3px 3px; }
            .rail-foot { margin-top: 0; }
            .panel { position: fixed; left: 0; right: 0; bottom: calc(63px + env(safe-area-inset-bottom, 0px)); height: 58vh; border-right: 0; border-top: 1px solid var(--line); border-radius: 16px 16px 0 0; box-shadow: var(--shadow); }
            .app.panel-closed .panel { display: none; }
            .panel-inner { width: 100%; }
            .map-wrap { margin-bottom: calc(63px + env(safe-area-inset-bottom, 0px)); }
            .legend { width: 220px; bottom: 10px; }
            .app:not(.panel-closed) .legend, .app:not(.panel-closed) .toast { display: none; }
            .app:not(.panel-closed) .leaflet-bottom { display: none; }
            #collapse-btn .i { transform: rotate(-90deg); }
            .popover { position: fixed; left: 10px; right: 10px; width: auto; top: 64px; }
            .layers-card { width: min(300px, calc(100% - 24px)); }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
        @media (forced-colors: active) { .verdict, .result, .rank { border: 1px solid CanvasText; } }
    </style>
</head>
<body>
    <a class="skip" href="#panel">Skip to flood tools</a>

    <!-- Icon sprite -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-wave" viewBox="0 0 24 24"><path d="M2 9c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5M2 16c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5"/></symbol>
        <symbol id="i-rain" viewBox="0 0 24 24"><path d="M7 16a4 4 0 0 1-.5-7.97A5.5 5.5 0 0 1 17 9a3.5 3.5 0 0 1 0 7"/><path d="M8 19l-1 2M12 19l-1 2M16 19l-1 2"/></symbol>
        <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></symbol>
        <symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6h12M9 12h12M9 18h12"/><circle cx="4" cy="6" r="1.2"/><circle cx="4" cy="12" r="1.2"/><circle cx="4" cy="18" r="1.2"/></symbol>
        <symbol id="i-route" viewBox="0 0 24 24"><circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7"/></symbol>
        <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.01"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></symbol>
        <symbol id="i-layers" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 12.5l9 5 9-5"/><path d="M3 16.5l9 5 9-5"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
        <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></symbol>
        <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/></symbol>
        <symbol id="i-siren" viewBox="0 0 24 24"><path d="M7 18v-6a5 5 0 0 1 10 0v6"/><path d="M5 21h14v-3H5z"/><path d="M12 2v2M4.2 5.2l1.4 1.4M19.8 5.2l-1.4 1.4M2 12h2M20 12h2"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></symbol>
        <symbol id="i-print" viewBox="0 0 24 24"><path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/></symbol>
        <symbol id="i-chev-left" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
        <symbol id="i-locate" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="8"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></symbol>
        <symbol id="i-swap" viewBox="0 0 24 24"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></symbol>
        <symbol id="i-moto" viewBox="0 0 24 24"><circle cx="5" cy="16.5" r="3"/><circle cx="19" cy="16.5" r="3"/><path d="M5 16.5l4.5-6h5l4.5 6M9.5 10.5L8 7H5.5M14.5 10.5l1.5-4h2.5"/></symbol>
        <symbol id="i-car" viewBox="0 0 24 24"><path d="M5 17H3v-4l2.5-5h13l2.5 5v4h-2"/><path d="M3 13h18"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M9.5 17h5"/></symbol>
        <symbol id="i-van" viewBox="0 0 24 24"><path d="M5 17H3V6h12l5 5v6h-1"/><path d="M3 11h17M9 6v5"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M9 17h6"/></symbol>
        <symbol id="i-suv" viewBox="0 0 24 24"><path d="M4.5 17H2v-5l3-5h11l3.5 5H22v5h-2.5"/><path d="M2 12h20"/><circle cx="7" cy="17" r="2.5"/><circle cx="17" cy="17" r="2.5"/><path d="M9.5 17h5"/></symbol>
        <symbol id="i-truck" viewBox="0 0 24 24"><path d="M4 17.5H2V5h12v12.5"/><path d="M14 9h4l4 4v4.5h-2"/><circle cx="6" cy="17.5" r="2"/><circle cx="17" cy="17.5" r="2"/><path d="M8 17.5h7"/></symbol>
    </svg>

    <div class="app" id="app">
        <!-- ================= TOP BAR ================= -->
        <header class="topbar">
            <div class="brand" aria-hidden="true"><svg class="i"><use href="#i-wave"/></svg></div>
            <div class="brand-text">
                <b>Nairobi Flood DSS</b>
                <span>Flood scenario decision support</span>
            </div>
            <div class="divider"></div>

            <div class="scenario" role="group" aria-label="Storm scenario">
                <span class="sc-label">Scenario</span>
                <div class="presets" id="presets">
                    <button class="preset" data-i="20" data-d="2" aria-pressed="false"><b>Heavy</b><small>20 mm/h · 2 h</small></button>
                    <button class="preset" data-i="35" data-d="2" aria-pressed="false"><b>Intense</b><small>35 mm/h · 2 h</small></button>
                    <button class="preset" data-i="50" data-d="3" aria-pressed="false"><b>Violent</b><small>50 mm/h · 3 h</small></button>
                    <button class="preset" data-i="90" data-d="3" aria-pressed="false"><b>Extreme</b><small>90 mm/h · 3 h</small></button>
                    <button class="preset custom-btn" id="custom-btn" aria-pressed="false" aria-expanded="false" aria-controls="custom-pop">
                        <b>Custom</b><small id="sc-summary">30 mm/h · 2 h</small>
                    </button>
                </div>

                <div class="popover" id="custom-pop" role="dialog" aria-label="Custom storm" hidden>
                    <h3>Custom storm</h3>
                    <p class="muted">The model was trained on storms of 10–120 mm/h lasting 1–6 hours.</p>
                    <div class="control">
                        <label for="intensity-slider"><span>Rainfall intensity</span><strong id="intensity-val">30 mm/hr</strong></label>
                        <input type="range" id="intensity-slider" min="0" max="120" value="30" oninput="onSlide(this, 'intensity-val', ' mm/hr')">
                    </div>
                    <div class="control">
                        <label for="duration-slider"><span>Storm duration</span><strong id="duration-val">2 hrs</strong></label>
                        <input type="range" id="duration-slider" min="1" max="6" step="0.5" value="2" oninput="onSlide(this, 'duration-val', ' hrs')">
                    </div>
                    <p class="muted" id="rain-total" style="margin:12px 0">Total rainfall: 60 mm.</p>
                    <button class="btn btn-primary btn-block" id="sim-btn" onclick="closeCustom(); runFloodSimulation()">Run scenario</button>
                </div>
            </div>

            <div class="divider"></div>
            <div class="weather" role="status" aria-live="polite">
                <svg class="i"><use href="#i-rain"/></svg>
                <div class="wx-text">
                    <b id="live-rain-level">Checking…</b>
                    <small><span id="live-rain">-- mm</span> · <span id="live-temp">-- °C</span> · <span id="live-hum">-- %</span> humidity</small>
                </div>
                <button class="bar-btn" id="live-btn" onclick="applyLiveWeatherToModel()" title="Run the model with the rain falling in Nairobi now">Use live rain</button>
            </div>

            <div class="spacer"></div>
            <button class="bar-btn bar-icon bell" id="bell-btn" onclick="toggleAlerts()" aria-label="Alerts" aria-expanded="false" aria-controls="alert-pop">
                <svg class="i"><use href="#i-bell"/></svg><span class="count" id="bell-count" hidden>0</span>
            </button>
            <button class="bar-btn bar-icon" id="theme-btn" onclick="toggleTheme()" aria-label="Switch to dark theme">
                <svg class="i" id="theme-icon"><use href="#i-moon"/></svg>
            </button>
            <div class="account">
                @auth
                    @if(Auth::user()->avatar)
                        <img src="{{ Auth::user()->avatar }}" class="avatar" alt="" referrerpolicy="no-referrer" title="{{ Auth::user()->name }} · {{ Auth::user()->roleLabel() }}">
                    @else
                        <div class="avatar" aria-hidden="true" title="{{ Auth::user()->name }} · {{ Auth::user()->roleLabel() }}">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                    @endif
                    <div>
                        <div class="acct-name">{{ Auth::user()->name }}</div>
                        <div class="acct-role">{{ Auth::user()->roleLabel() }}</div>
                    </div>
                    @if(Auth::user()->role === 'admin')
                        <a href="{{ route('admin.index') }}" class="bar-btn">Admin</a>
                    @endif
                    <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                        @csrf
                        <button type="submit" class="bar-btn">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('login.google') }}" class="bar-btn signin">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.11-6.72-4.96H1.29v3.13C3.26 21.3 7.31 24 12 24z"/>
                            <path fill="#FBBC05" d="M5.28 14.24c-.25-.72-.38-1.49-.38-2.24s.13-1.52.38-2.24V6.63H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.37l3.99-3.13z"/>
                            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.63l3.99 3.13c.95-2.85 3.6-4.96 6.72-4.96z"/>
                        </svg>
                        <span>Sign in</span>
                    </a>
                @endauth
            </div>
            <div class="progress" id="progress" aria-hidden="true"></div>
        </header>
        <div class="alert-pop" id="alert-pop" role="dialog" aria-label="Alerts" hidden></div>

        <!-- ================= RAIL ================= -->
        <nav class="rail" role="tablist" aria-orientation="vertical" aria-label="Tools" id="rail">
            <button class="rail-btn" role="tab" id="tab-overview" data-module="overview" aria-controls="panel" aria-selected="true">
                <svg class="i"><use href="#i-grid"/></svg>Overview
            </button>
            <button class="rail-btn" role="tab" id="tab-areas" data-module="areas" aria-controls="panel" aria-selected="false" tabindex="-1">
                <svg class="i"><use href="#i-list"/></svg>Areas
            </button>
            <button class="rail-btn" role="tab" id="tab-route" data-module="route" aria-controls="panel" aria-selected="false" tabindex="-1">
                <svg class="i"><use href="#i-route"/></svg>Route
            </button>
            <button class="rail-btn" role="tab" id="tab-inspect" data-module="inspect" aria-controls="panel" aria-selected="false" tabindex="-1">
                <svg class="i"><use href="#i-pin"/></svg>Inspect
            </button>
            @auth
                <button class="rail-btn" role="tab" id="tab-places" data-module="places" aria-controls="panel" aria-selected="false" tabindex="-1">
                    <svg class="i"><use href="#i-home"/></svg>Places
                </button>
                @if(Auth::user()->isStaff())
                    <button class="rail-btn" role="tab" id="tab-response" data-module="response" aria-controls="panel" aria-selected="false" tabindex="-1">
                        <svg class="i"><use href="#i-siren"/></svg>Response
                    </button>
                @endif
            @endauth
            <button class="rail-btn rail-foot" role="tab" id="tab-model" data-module="model" aria-controls="panel" aria-selected="false" tabindex="-1">
                <svg class="i"><use href="#i-info"/></svg>Model
            </button>
        </nav>

        <!-- ================= PANEL ================= -->
        <aside class="panel" id="panel" aria-labelledby="panel-title" tabindex="-1">
            <div class="panel-inner">
                <div class="panel-head">
                    <h1 id="panel-title">Overview<span class="sub" id="panel-sub">Impact of the selected storm</span></h1>
                    <button class="icon-btn" id="collapse-btn" onclick="togglePanel()" aria-label="Hide panel" aria-expanded="true">
                        <svg class="i"><use href="#i-chev-left"/></svg>
                    </button>
                </div>
                <div class="panel-body" id="panel-body">

                    <!-- OVERVIEW -->
                    <section class="module" id="mod-overview">
                        <div class="sec">
                            <div class="scenario-card">
                                <svg class="i"><use href="#i-rain"/></svg>
                                <div><b id="ov-storm">Loading scenario…</b><span id="ov-storm-sub">Running the flood model</span></div>
                            </div>
                            <p class="note" id="sim-notes" hidden></p>
                        </div>
                        <div class="sec">
                            <div class="kpis" id="kpis">
                                <div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>
                            </div>
                        </div>
                        <div class="sec">
                            <div class="sec-head"><h2>Hazard zones</h2><span class="muted" id="zone-total"></span></div>
                            <div class="stack" id="zone-stack" role="img" aria-label="Hazard zones by level"></div>
                            <div class="stack-legend" id="zone-legend"></div>
                        </div>
                        <div class="sec">
                            <div class="sec-head"><h2>Most affected sub-counties</h2><button class="link" onclick="openAreas('subcounties')">View all</button></div>
                            <div id="ov-subcounties"><div class="skeleton"></div></div>
                        </div>
                        <div class="sec">
                            <div class="sec-head"><h2>Priority hotspots</h2><button class="link" onclick="openAreas('hotspots')">View all</button></div>
                            <div id="ov-hotspots"><div class="skeleton"></div></div>
                        </div>
                    </section>

                    <!-- AREAS -->
                    <section class="module" id="mod-areas" hidden>
                        <div class="seg" role="group" aria-label="Area list">
                            <button aria-pressed="true" data-list="subcounties">Sub-counties</button>
                            <button aria-pressed="false" data-list="hotspots">Hotspots</button>
                            <button aria-pressed="false" data-list="facilities">Facilities</button>
                        </div>
                        <input class="search" id="area-search" type="search" placeholder="Filter by name…" aria-label="Filter the list">
                        <p class="muted" id="area-help" style="margin:0 0 8px"></p>
                        <div id="area-list"><div class="empty">Results appear once the scenario has run.</div></div>
                    </section>

                    <!-- ROUTE -->
                    <section class="module" id="mod-route" hidden>
                        <div class="sec">
                            <div class="route-fields">
                                <div class="field">
                                    <label for="origin-input">From</label>
                                    <span class="badge-ab a" aria-hidden="true">A</span>
                                    <input type="text" id="origin-input" placeholder="Search or click the map" autocomplete="off">
                                    <div class="suggestions" id="origin-suggestions"></div>
                                </div>
                                <div class="field">
                                    <label for="dest-input">To</label>
                                    <span class="badge-ab b" aria-hidden="true">B</span>
                                    <input type="text" id="dest-input" placeholder="Search or click the map" autocomplete="off">
                                    <div class="suggestions" id="dest-suggestions"></div>
                                </div>
                                <button class="icon-btn swap" onclick="swapPoints()" aria-label="Swap start and destination"><svg class="i"><use href="#i-swap"/></svg></button>
                            </div>
                            <button class="text-btn" id="locate-btn" onclick="useMyLocation()"><svg class="i"><use href="#i-locate"/></svg> Use my location as start</button>
                        </div>
                        <div class="sec">
                            <fieldset class="veh-grid" id="vehicle-options"><legend>Vehicle</legend></fieldset>
                            <p class="veh-limit" id="veh-limit"></p>
                        </div>
                        <div class="sec btn-row">
                            <button class="btn btn-safe" id="route-btn" onclick="handleFindRouteClick()">Find safe route</button>
                            <button class="btn btn-outline" onclick="clearRouting()" style="flex:0 0 90px">Clear</button>
                        </div>
                        <div class="sec" id="route-status" role="status" aria-live="polite">
                            <p class="muted">Routes use the scenario selected at the top. Click the map to place A, then B.</p>
                        </div>
                    </section>

                    <!-- INSPECT -->
                    <section class="module" id="mod-inspect" hidden>
                        <div id="spot-box"><div class="empty">Click anywhere on the map to see the predicted depth, which vehicles can pass, and why the model predicts flooding there.</div></div>
                        <div class="sec" id="why-sec" hidden>
                            <div class="sec-head"><h2>Why here?</h2><span class="muted" id="why-pred"></span></div>
                            <div id="why-bars"></div>
                            <details class="fine"><summary>How this is calculated</summary><p id="why-method"></p></details>
                        </div>
                    </section>

                    @auth
                    <!-- PLACES (signed-in residents) -->
                    <section class="module" id="mod-places" hidden>
                        <div class="sec">
                            <p class="muted" style="margin:0">Save up to 5 places you care about. Each shows its flood hazard for the selected scenario, and you get an alert here when responders issue an advisory for its sub-county.</p>
                        </div>
                        <div class="sec" id="place-form" hidden></div>
                        <div class="sec">
                            <div class="sec-head"><h2>Your places</h2><span class="muted" id="places-count"></span></div>
                            <div id="places-list"><div class="skeleton"></div></div>
                        </div>
                        <div class="sec">
                            <button class="btn btn-outline btn-block" id="add-place-btn" onclick="startAddPlace()"><svg class="i"><use href="#i-pin"/></svg>Add a place</button>
                        </div>
                    </section>

                    @if(Auth::user()->isStaff())
                    <!-- RESPONSE (responders and admins) -->
                    <section class="module" id="mod-response" hidden>
                        <div class="seg" role="group" aria-label="Response tools">
                            <button aria-pressed="true" data-resp="advisories">Advisories</button>
                            <button aria-pressed="false" data-resp="facilities">Facilities</button>
                            <button aria-pressed="false" data-resp="report">Report</button>
                        </div>

                        <div data-resp-pane="advisories">
                            <div class="sec">
                                <div class="sec-head"><h2>Issue an advisory</h2><button class="link" onclick="prefillAdvisory()">Fill from scenario</button></div>
                                <form id="adv-form" onsubmit="publishAdvisory(event)">
                                    <label class="fld"><span>Level</span>
                                        <select name="level"><option value="warning">Warning</option><option value="severe">Severe</option><option value="info">Information</option></select>
                                    </label>
                                    <label class="fld"><span>Headline</span><input name="title" maxlength="120" required placeholder="e.g. Flooding expected in Mathare and Kasarani"></label>
                                    <label class="fld"><span>Message to the public</span><textarea name="body" maxlength="600" required placeholder="What is expected, and what people should do"></textarea></label>
                                    <div class="fld"><span>Sub-counties</span><div class="chk-grid" id="adv-areas"><span class="muted">Loading…</span></div></div>
                                    <label class="fld"><span>Show for</span>
                                        <select name="hours"><option value="3">3 hours</option><option value="6" selected>6 hours</option><option value="12">12 hours</option><option value="24">24 hours</option><option value="48">48 hours</option></select>
                                    </label>
                                    <button class="btn btn-primary btn-block" id="adv-submit" style="margin-top:12px">Publish advisory</button>
                                    <p class="muted" style="margin:8px 0 0">Published advisories appear on everyone's map straight away and are recorded in the audit log. They add to, and do not replace, official Kenya Meteorological Department warnings.</p>
                                </form>
                            </div>
                            <div class="sec">
                                <div class="sec-head"><h2>Active advisories</h2></div>
                                <div id="resp-advisories"><div class="empty">None.</div></div>
                            </div>
                        </div>

                        <div data-resp-pane="facilities" hidden>
                            <div class="sec">
                                <p class="muted" style="margin:0" id="resp-fac-sub">Results appear once the scenario has run.</p>
                            </div>
                            <div class="sec"><div id="resp-facilities"></div></div>
                        </div>

                        <div data-resp-pane="report" hidden>
                            <div class="sec">
                                <div class="sec-head"><h2>Rain forecast, next 24 h</h2></div>
                                <div id="wx-forecast"><div class="skeleton"></div></div>
                            </div>
                            <div class="sec">
                                <div class="sec-head"><h2>Situation report</h2></div>
                                <p class="muted" style="margin:0 0 8px" id="sitrep-sub">Sub-county ranking, hotspots, facilities at risk and active advisories for the selected scenario.</p>
                                <div class="btn-row">
                                    <button class="btn btn-outline" onclick="downloadSitrepCsv()"><svg class="i"><use href="#i-download"/></svg>CSV</button>
                                    <button class="btn btn-outline" onclick="printSitrep()"><svg class="i"><use href="#i-print"/></svg>Print / PDF</button>
                                </div>
                            </div>
                        </div>
                    </section>
                    @endif
                    @endauth

                    <!-- MODEL -->
                    <section class="module" id="mod-model" hidden>
                        <div id="model-info"><div class="skeleton"></div></div>
                    </section>
                </div>
            </div>
        </aside>

        <!-- ================= MAP ================= -->
        <main class="map-wrap" id="map-wrap">
            <div id="map" role="region" aria-label="Flood map of Nairobi"></div>

            <button class="map-card layers-btn" id="layers-btn" aria-expanded="false" aria-controls="layers-card" onclick="toggleLayers()">
                <svg class="i"><use href="#i-layers"/></svg> Layers
            </button>
            <div class="map-card layers-card" id="layers-card" hidden>
                <section>
                    <h3>Flood display</h3>
                    <label class="opt"><input type="radio" name="display" value="auto" checked><span><b>Automatic</b><small>Hazard zones when zoomed out, flood outlines when zoomed in</small></span></label>
                    <label class="opt"><input type="radio" name="display" value="zones"><span><b>Hazard zones</b><small>10-hectare hexagons coloured by depth</small></span></label>
                    <label class="opt"><input type="radio" name="display" value="extent"><span><b>Flood outlines</b><small>Predicted water area, banded by depth</small></span></label>
                    <label class="opt"><input type="radio" name="display" value="cells"><span><b>30 m model cells</b><small>Raw model output, shaded by certainty</small></span></label>
                    <div class="control" style="margin-top:8px">
                        <label for="opacity-slider"><span>Opacity</span><strong id="opacity-val">80%</strong></label>
                        <input type="range" id="opacity-slider" min="20" max="100" value="80">
                    </div>
                </section>
                <section>
                    <h3>Overlays</h3>
                    <label class="opt"><input type="checkbox" id="lyr-bounds" checked><span><b>Sub-county boundaries</b></span></label>
                    <label class="opt"><input type="checkbox" id="lyr-hotspots" checked><span><b>Hotspots</b><small>Numbered by priority</small></span></label>
                    <label class="opt"><input type="checkbox" id="lyr-facilities"><span><b>Facilities in floodwater</b><small>Schools, health and emergency</small></span></label>
                </section>
                <section>
                    <h3>Base map</h3>
                    <div class="seg" role="group" aria-label="Base map">
                        <button aria-pressed="true" data-base="streets">Streets</button>
                        <button aria-pressed="false" data-base="satellite">Satellite</button>
                    </div>
                </section>
            </div>

            <details class="map-card legend" id="legend" open>
                <summary id="legend-title">Flood hazard</summary>
                <div id="legend-body"></div>
                <div id="legend-route" hidden>
                    <div class="lg-row" style="margin-top:6px"><span class="ln"></span><span>Safe route</span><em></em></div>
                    <div class="lg-row"><span class="ln direct"></span><span>Direct route</span><em></em></div>
                    <div class="lg-row"><span class="ln hazard"></span><span>Unsafe for your vehicle</span><em></em></div>
                </div>
            </details>

            <div class="map-card adv-banner" id="adv-banner" role="alert" hidden></div>
            <div class="map-card map-hint" id="map-hint" hidden></div>
            <div class="map-card toast" id="toast" role="status" aria-live="polite" hidden></div>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // =========================================================
        // CONFIG
        // =========================================================
        const TILE_KEY = '?key=cb1_4giv_1_8d1b08af50b833befe3b1884';
        const VEHICLE_ICON = { boda_boda: 'i-moto', saloon: 'i-car', large_car: 'i-van', suv_4x4: 'i-suv', lorry_bus: 'i-truck' };
        const VEHICLE_SHORT = { boda_boda: 'Boda boda', saloon: 'Saloon', large_car: 'Matatu', suv_4x4: 'SUV / 4x4', lorry_bus: 'Lorry / bus' };
        const VERDICT = { safe: 'Passable', caution: 'Slow – shallow water', unsafe: 'Unsafe' };
        // Hazard levels (model_api.py ZONE_LEVELS): deepest water covering at least 10% of a zone
        const LEVELS = [
            { key: 'moderate',  label: 'Moderate',  depth: '15–30 cm', meaning: 'can knock an adult over', color: '#9ecae1' },
            { key: 'high',      label: 'High',      depth: '30–60 cm', meaning: 'can float many cars',     color: '#ffed6f' },
            { key: 'very_high', label: 'Very high', depth: '0.6–1.2 m', meaning: 'sweeps away most vehicles', color: '#fd8d3c' },
            { key: 'severe',    label: 'Severe',    depth: '> 1.2 m',  meaning: 'impassable',              color: '#d7191c' },
        ];
        const LEVEL = Object.fromEntries(LEVELS.map(l => [l.key, l]));
        const BAND_COLORS = ['#9ecae1', '#ffed6f', '#fd8d3c', '#d7191c'];
        const AUTO_EXTENT_ZOOM = 14;
        const MODULE_INFO = {
            overview: ['Overview', 'Impact of the selected storm'],
            areas:    ['Areas', 'Where the flooding hits hardest'],
            route:    ['Safe route', 'Routing that avoids water unsafe for your vehicle'],
            inspect:  ['Inspect a location', 'Depth, passability and explanation'],
            model:    ['About the model', 'How the predictions are made'],
            places:   ['My places', 'Flood status of places you saved'],
            response: ['Response', 'Advisories, facilities at risk and reports'],
        };
        // Signed-in user (null for guests); the server enforces every permission again
        const ME = @json(auth()->check() ? ['id' => auth()->id(), 'role' => auth()->user()->role] : null);
        const IS_STAFF = !!ME && ['responder', 'admin'].includes(ME.role);

        // =========================================================
        // MAP
        // =========================================================
        const map = L.map('map', { zoomControl: false, preferCanvas: false }).setView([-1.2880, 36.8320], 12);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.control.scale({ position: 'bottomright', imperial: false }).addTo(map);
        [['flood', 410], ['bounds', 420], ['routes', 450], ['labels', 650]].forEach(([name, z]) => {
            map.createPane(name);
            map.getPane(name).style.zIndex = z;
        });
        map.getPane('labels').style.pointerEvents = 'none';
        map.getPane('bounds').style.pointerEvents = 'none';
        const floodRenderer = L.canvas({ pane: 'flood', padding: 0.5 });

        let baseLayer = null, labelLayer = null, baseKind = 'streets';
        function setBasemap() {
            if (baseLayer) map.removeLayer(baseLayer);
            if (labelLayer) map.removeLayer(labelLayer);
            const dark = currentTheme() === 'dark';
            const carto = { attribution: '&copy; OpenStreetMap contributors, &copy; CARTO', subdomains: 'abcd', maxZoom: 19 };
            if (baseKind === 'satellite') {
                baseLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    { attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics', maxZoom: 19 }).addTo(map);
            } else {
                baseLayer = L.tileLayer(`https://{s}.basemaps.cartocdn.com/${dark ? 'dark' : 'light'}_nolabels/{z}/{x}/{y}@2x.png${TILE_KEY}`, carto).addTo(map);
            }
            const labelStyle = (dark || baseKind === 'satellite') ? 'dark' : 'light';
            labelLayer = L.tileLayer(`https://{s}.basemaps.cartocdn.com/${labelStyle}_only_labels/{z}/{x}/{y}@2x.png${TILE_KEY}`,
                Object.assign({ pane: 'labels', className: dark || baseKind === 'satellite' ? 'bright-map-labels' : '' }, carto)).addTo(map);
        }

        // State
        let zonesLayer = null, extentLayer = null, cellsLayer = null;
        let subcountyLayer = null, hotspotLayer = null, facilityLayer = null, selectedSubcounty = null;
        let startMarker = null, endMarker = null, routeLayer = null, spotMarker = null;
        let startCoords = null, endCoords = null;
        let currentLiveRain = 0;
        let simStorm = null;          // storm of the last completed simulation
        let lastData = null;          // /predict response for simStorm
        let vehicles = {};            // from the model server: limits per vehicle
        let modelInfo = null;
        let lastSpot = null;          // location shown in Inspect
        let runSeq = 0;
        let displayMode = 'auto';
        let areaList = 'subcounties';
        let alerts = [];              // active alerts visible to this viewer (/api/alerts)
        let weatherInfo = null;       // Open-Meteo hourly forecast (staff only)
        let advisoryAreas = {};       // sub-county name -> most severe active advisory level
        let places = [];              // signed-in user's saved places, with status for simStorm
        let addingPlace = false;

        // =========================================================
        // API (all requests go through Laravel)
        // =========================================================
        async function api(path, params) {
            const qs = params ? '?' + new URLSearchParams(params).toString() : '';
            const res = await fetch(path + qs, { headers: { 'Accept': 'application/json' } });
            let body = null;
            try { body = await res.json(); } catch (e) {}
            if (!res.ok) {
                const msg = (body && (body.error || body.message)) || `Request failed (HTTP ${res.status})`;
                throw new Error(msg);
            }
            return body;
        }
        function currentStorm() {
            return {
                intensity: Number(document.getElementById('intensity-slider').value),
                duration: Number(document.getElementById('duration-slider').value),
            };
        }
        // Routing and spot checks use the simulated storm if one has been run, otherwise the sliders
        function activeStorm() { return simStorm || currentStorm(); }

        // =========================================================
        // UI HELPERS
        // =========================================================
        function currentTheme() { return document.documentElement.getAttribute('data-theme'); }
        function applyTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            try { localStorage.setItem('floodDssTheme', t); } catch (e) {}
            const dark = t === 'dark';
            document.getElementById('theme-icon').innerHTML = `<use href="#${dark ? 'i-sun' : 'i-moon'}"/>`;
            document.getElementById('theme-btn').setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
            setBasemap();
            if (subcountyLayer) subcountyLayer.setStyle(subcountyStyle);
            if (zonesLayer) zonesLayer.setStyle(zoneStyle);
        }
        function toggleTheme() { applyTheme(currentTheme() === 'dark' ? 'light' : 'dark'); }

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        function fmt(n) { return Number(n).toLocaleString('en-KE'); }
        function depthText(m) { return m > 2 ? '> 2 m' : m >= 1 ? `${m.toFixed(1)} m` : `${Math.round(m * 100)} cm`; }
        function setBusy(id, busy) { const b = document.getElementById(id); if (b) b.classList.toggle('is-loading', busy); }
        // Colour for a depth in metres, same ramp as the map layer
        const RAMP = [[0.15, [158, 202, 225]], [0.3, [255, 237, 111]], [0.6, [253, 141, 60]], [1.2, [215, 25, 28]], [2.0, [103, 0, 13]]];
        function depthColor(d) {
            if (d <= RAMP[0][0]) return `rgb(${RAMP[0][1]})`;
            for (let k = 1; k < RAMP.length; k++) {
                if (d <= RAMP[k][0]) {
                    const t = (d - RAMP[k - 1][0]) / (RAMP[k][0] - RAMP[k - 1][0]);
                    const c = RAMP[k - 1][1].map((v, j) => Math.round(v + t * (RAMP[k][1][j] - v)));
                    return `rgb(${c})`;
                }
            }
            return `rgb(${RAMP[RAMP.length - 1][1]})`;
        }
        function inkOn(d) { return d > 1.0 ? '#ffffff' : '#0f172a'; }

        let toastTimer = null;
        function toast(msg, tone = 'info', ms = 6000) {
            const el = document.getElementById('toast');
            el.dataset.tone = tone;
            el.innerHTML = `<svg class="i"><use href="#${tone === 'error' ? 'i-alert' : 'i-info'}"/></svg><span style="flex:1">${escapeHtml(msg)}</span>
                <button class="icon-btn" aria-label="Dismiss" onclick="this.parentElement.hidden=true"><svg class="i"><use href="#i-x"/></svg></button>`;
            el.hidden = false;
            clearTimeout(toastTimer);
            if (ms) toastTimer = setTimeout(() => { el.hidden = true; }, ms);
        }
        function hint(html) {
            const el = document.getElementById('map-hint');
            el.innerHTML = html || '';
            el.hidden = !html;
        }

        // =========================================================
        // SHELL: modules, panel, layers card
        // =========================================================
        const MODULES = [...document.querySelectorAll('#rail .rail-btn')].map(b => b.dataset.module);
        let activeModule = 'overview';
        function showModule(name, { open = true } = {}) {
            activeModule = name;
            MODULES.forEach(n => {
                const on = n === name;
                const t = document.getElementById('tab-' + n);
                t.setAttribute('aria-selected', String(on));
                t.tabIndex = on ? 0 : -1;
                document.getElementById('mod-' + n).hidden = !on;
            });
            const [title, sub] = MODULE_INFO[name];
            document.getElementById('panel-title').innerHTML = `${escapeHtml(title)}<span class="sub">${escapeHtml(sub)}</span>`;
            document.getElementById('panel-body').scrollTop = 0;
            if (open) setPanel(true);
            if (name !== 'places') addingPlace = false;
            hint(name === 'route' && !(startCoords && endCoords)
                ? `<svg class="i"><use href="#i-pin"/></svg> Click the map to set ${startCoords ? 'the destination (B)' : 'the start (A)'}` : '');
            map.getContainer().style.cursor = name === 'route' ? 'crosshair' : '';
            if (name === 'places') renderPlaces();
            if (name === 'response') renderResponse();
        }
        document.getElementById('rail').addEventListener('click', (e) => {
            const b = e.target.closest('.rail-btn');
            if (!b) return;
            const name = b.dataset.module;
            const app = document.getElementById('app');
            if (name === activeModule && !app.classList.contains('panel-closed')) setPanel(false);
            else showModule(name);
        });
        document.getElementById('rail').addEventListener('keydown', (e) => {
            const i = MODULES.indexOf(activeModule);
            const vertical = window.innerWidth > 820;
            const next = { [vertical ? 'ArrowDown' : 'ArrowRight']: 1, [vertical ? 'ArrowUp' : 'ArrowLeft']: -1 }[e.key];
            if (next) {
                e.preventDefault();
                const n = MODULES[(i + next + MODULES.length) % MODULES.length];
                showModule(n);
                document.getElementById('tab-' + n).focus();
            }
        });
        function setPanel(open) {
            const app = document.getElementById('app');
            app.classList.toggle('panel-closed', !open);
            const btn = document.getElementById('collapse-btn');
            btn.setAttribute('aria-expanded', String(open));
        }
        function togglePanel() { setPanel(document.getElementById('app').classList.contains('panel-closed')); }
        new ResizeObserver(() => map.invalidateSize({ pan: false })).observe(document.getElementById('map-wrap'));

        function toggleLayers(force) {
            const card = document.getElementById('layers-card');
            const open = force !== undefined ? force : card.hidden;
            card.hidden = !open;
            document.getElementById('layers-btn').setAttribute('aria-expanded', String(open));
        }
        document.querySelectorAll('input[name="display"]').forEach(r => r.addEventListener('change', () => {
            displayMode = r.value;
            try { localStorage.setItem('floodDssDisplay', displayMode); } catch (e) {}
            updateFloodDisplay();
        }));
        document.getElementById('opacity-slider').addEventListener('input', (e) => {
            document.getElementById('opacity-val').innerText = e.target.value + '%';
            syncSlider(e.target);
            applyOpacity();
        });
        document.getElementById('lyr-bounds').addEventListener('change', e => toggleOverlay(subcountyLayer, e.target.checked));
        document.getElementById('lyr-hotspots').addEventListener('change', e => toggleOverlay(hotspotLayer, e.target.checked));
        document.getElementById('lyr-facilities').addEventListener('change', e => toggleOverlay(facilityLayer, e.target.checked));
        function toggleOverlay(layer, on) { if (layer) { if (on) layer.addTo(map); else map.removeLayer(layer); } }
        document.querySelectorAll('[data-base]').forEach(b => b.addEventListener('click', () => {
            baseKind = b.dataset.base;
            document.querySelectorAll('[data-base]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
            setBasemap();
        }));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') { toggleLayers(false); closeCustom(); toggleAlerts(false); } });
        document.addEventListener('click', e => {
            const ap = document.getElementById('alert-pop');
            // isConnected: buttons inside the list re-render it, which detaches the clicked element
            if (!ap.hidden && e.target.isConnected && !ap.contains(e.target) && !e.target.closest('#bell-btn, #adv-banner')) toggleAlerts(false);
            const card = document.getElementById('layers-card');
            if (!card.hidden && !card.contains(e.target) && !e.target.closest('#layers-btn')) toggleLayers(false);
            const pop = document.getElementById('custom-pop');
            if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('#custom-btn')) closeCustom();
        });

        // =========================================================
        // SCENARIO CONTROLS
        // =========================================================
        function syncSlider(el) { el.style.setProperty('--p', ((el.value - el.min) / (el.max - el.min)) * 100 + '%'); }
        function onSlide(el, valueId, unit) {
            document.getElementById(valueId).innerText = el.value + unit;
            syncSlider(el);
            updateRainTotal();
        }
        function updateRainTotal() {
            const s = currentStorm();
            document.getElementById('rain-total').innerText = `Total rainfall: ${Math.round(s.intensity * s.duration)} mm.`;
        }
        function setSliders(i, d) {
            const a = document.getElementById('intensity-slider'), b = document.getElementById('duration-slider');
            a.value = i; b.value = d;
            document.getElementById('intensity-val').innerText = a.value + ' mm/hr';
            document.getElementById('duration-val').innerText = b.value + ' hrs';
            syncSlider(a); syncSlider(b); updateRainTotal();
        }
        function markPreset() {
            const s = simStorm || currentStorm();
            let matched = false;
            document.querySelectorAll('.preset[data-i]').forEach(b => {
                const on = Number(b.dataset.i) === s.intensity && Number(b.dataset.d) === s.duration;
                b.setAttribute('aria-pressed', String(on));
                matched = matched || on;
            });
            document.getElementById('custom-btn').setAttribute('aria-pressed', String(!matched));
            document.getElementById('sc-summary').innerText = `${s.intensity} mm/h · ${s.duration} h`;
        }
        document.querySelectorAll('.preset[data-i]').forEach(b => b.addEventListener('click', () => {
            setSliders(Number(b.dataset.i), Number(b.dataset.d));
            closeCustom();
            runFloodSimulation();
        }));
        document.getElementById('custom-btn').addEventListener('click', () => {
            const pop = document.getElementById('custom-pop');
            pop.hidden = !pop.hidden;
            document.getElementById('custom-btn').setAttribute('aria-expanded', String(!pop.hidden));
            if (!pop.hidden) document.getElementById('intensity-slider').focus();
        });
        function closeCustom() {
            document.getElementById('custom-pop').hidden = true;
            document.getElementById('custom-btn').setAttribute('aria-expanded', 'false');
        }
        ['intensity-slider', 'duration-slider', 'opacity-slider'].forEach(id => syncSlider(document.getElementById(id)));

        // =========================================================
        // LIVE WEATHER
        // =========================================================
        function rainLevel(mm) {
            if (mm <= 0) return 'No rain now';
            if (mm < 2.5) return 'Light rain now';
            if (mm < 7.6) return 'Moderate rain now';
            if (mm < 50) return 'Heavy rain now';
            return 'Violent rain now';
        }
        async function fetchLiveWeather() {
            try {
                const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=-1.2921&longitude=36.8219&current=temperature_2m,relative_humidity_2m,precipitation,rain');
                const data = await res.json();
                if (data.current) {
                    currentLiveRain = data.current.precipitation || 0;
                    document.getElementById('live-rain').innerText = `${currentLiveRain} mm`;
                    document.getElementById('live-temp').innerText = `${data.current.temperature_2m} °C`;
                    document.getElementById('live-hum').innerText = `${data.current.relative_humidity_2m} %`;
                    document.getElementById('live-rain-level').innerText = rainLevel(currentLiveRain);
                }
            } catch (e) {
                document.getElementById('live-rain-level').innerText = 'Weather unavailable';
                document.getElementById('live-rain').innerText = '--';
            }
        }
        fetchLiveWeather();

        async function applyLiveWeatherToModel() {
            const liveBtn = document.getElementById('live-btn');
            liveBtn.classList.add('is-loading');

            // Fetch the latest live weather from Open-Meteo
            await fetchLiveWeather();
            liveBtn.classList.remove('is-loading');

            // Extract the true live rain value (default to 0 if it's not raining)
            const rawRain = parseFloat(currentLiveRain) || 0;

            // Map it to your slider's range (min 0, max 120)
            const intensityToSet = Math.min(120, Math.max(0, Math.round(rawRain)));

            // Update the slider position
            setSliders(intensityToSet, 2);

            // Automatically run the prediction model
            runFloodSimulation();
        }

        // =========================================================
        // 1. FLOOD SCENARIO
        // =========================================================
        async function runFloodSimulation() {
            const storm = currentStorm();
            const seq = ++runSeq;
            document.getElementById('progress').classList.add('on');
            setBusy('sim-btn', true);
            setBusy('live-btn', true);
            document.getElementById('ov-storm').innerText = `${storm.intensity} mm/h for ${storm.duration} h`;
            document.getElementById('ov-storm-sub').innerText = 'Running the flood model… a new storm takes up to 30 seconds';

            try {
                const data = await api('/api/flood/predict', storm);
                if (seq !== runSeq) return;
                const s = { intensity: data.storm.intensity_mmhr, duration: data.storm.duration_hr };
                const [zones, extent] = await Promise.all([api('/api/flood/zones', s), api('/api/flood/extent', s)]);
                if (seq !== runSeq) return;
                simStorm = s;
                lastData = data;
                markPreset();
                buildFloodLayers(data, zones, extent);
                renderOverview(data);
                renderAreaList();
                renderHotspotMarkers(data);
                renderFacilityMarkers(data);
                const notes = document.getElementById('sim-notes');
                notes.hidden = !(data.notes && data.notes.length);
                notes.innerText = (data.notes || []).join(' ');
                if (lastSpot) inspect(L.latLng(lastSpot.lat, lastSpot.lng), { keepModule: true });
                if (startCoords && endCoords) fetchRoute();
                if (ME) refreshPlaceStatus({ announce: true });
                if (IS_STAFF && activeModule === 'response') renderResponse();
            } catch (err) {
                if (seq !== runSeq) return;
                console.error('Simulation error:', err);
                document.getElementById('ov-storm-sub').innerText = "Couldn't run the flood model";
                toast(err.message, 'error', 0);
            } finally {
                if (seq === runSeq) {
                    document.getElementById('progress').classList.remove('on');
                    setBusy('sim-btn', false);
                    setBusy('live-btn', false);
                }
            }
        }

        // ---------- Flood layers ----------
        function opacity() { return Number(document.getElementById('opacity-slider').value) / 100; }
        function zoneStyle(f) {
            const l = LEVEL[f.properties.level];
            return { fillColor: l ? l.color : '#cbd5e1', fillOpacity: 0.75 * opacity(),
                     color: getComputedStyle(document.documentElement).getPropertyValue('--zone-stroke').trim() || '#fff',
                     weight: map.getZoom() >= 14 ? 1 : map.getZoom() >= 13 ? 0.6 : 0.25, opacity: 0.7 * opacity() };
        }
        function extentStyle(f) {
            const c = BAND_COLORS[f.properties.band];
            return { fillColor: c, fillOpacity: 0.85 * opacity(), color: f.properties.band >= 3 ? '#7f1d1d' : '#3b6e8f',
                     weight: f.properties.band === 0 ? 0.8 : 0, opacity: 0.8 * opacity() };
        }
        function zoneTooltip(p) {
            const l = LEVEL[p.level];
            return `<b>${escapeHtml(l ? l.label : 'Minor')} hazard</b><br>${p.flooded_pct}% of this 10 ha zone flooded<br>
                Deepest: ${depthText(p.max_depth_m)} · ${fmt(p.buildings_exposed)} buildings in water &gt; 30 cm`;
        }
        function buildFloodLayers(data, zones, extent) {
            [zonesLayer, extentLayer, cellsLayer].forEach(l => { if (l) map.removeLayer(l); });
            zonesLayer = L.geoJSON(zones, {
                renderer: floodRenderer, style: zoneStyle,
                onEachFeature: (f, layer) => layer.bindTooltip(() => zoneTooltip(f.properties), { sticky: true, className: 'zone-tip', direction: 'top', offset: [0, -8] }),
            });
            const feats = extent.features.slice().sort((a, b) => a.properties.band - b.properties.band).map(smoothFeature);
            extentLayer = L.geoJSON({ type: 'FeatureCollection', features: feats }, { renderer: floodRenderer, style: extentStyle, interactive: false });
            cellsLayer = L.imageOverlay('/api/flood/overlay.png?' + new URLSearchParams(simStorm).toString(), data.overlay.bounds,
                { pane: 'flood', opacity: Math.min(1, opacity() / 0.85), interactive: false, alt: 'Predicted flood depth, 30 m cells' });
            updateFloodDisplay();
        }
        // Chaikin corner cutting: each pass replaces every corner with two points at 1/4 and 3/4 of its edges,
        // turning the server's 15 m polyline into a smooth curve that stays inside the original outline.
        const SMOOTH_PASSES = 2;
        function chaikin(ring) {
            let p = ring.slice(0, -1);
            for (let it = 0; it < SMOOTH_PASSES && p.length >= 3; it++) {
                const q = [];
                for (let i = 0; i < p.length; i++) {
                    const a = p[i], b = p[(i + 1) % p.length];
                    q.push([0.75 * a[0] + 0.25 * b[0], 0.75 * a[1] + 0.25 * b[1]], [0.25 * a[0] + 0.75 * b[0], 0.25 * a[1] + 0.75 * b[1]]);
                }
                p = q;
            }
            p.push(p[0]);
            return p;
        }
        function smoothFeature(f) {
            return { ...f, geometry: { type: 'MultiPolygon', coordinates: f.geometry.coordinates.map(poly => poly.map(chaikin)) } };
        }
        function effectiveDisplay() {
            if (displayMode !== 'auto') return displayMode;
            return map.getZoom() >= AUTO_EXTENT_ZOOM ? 'extent' : 'zones';
        }
        function updateFloodDisplay() {
            const mode = effectiveDisplay();
            [[zonesLayer, 'zones'], [extentLayer, 'extent'], [cellsLayer, 'cells']].forEach(([layer, m]) => {
                if (!layer) return;
                if (m === mode && !map.hasLayer(layer)) layer.addTo(map);
                if (m !== mode && map.hasLayer(layer)) map.removeLayer(layer);
            });
            renderLegend(mode);
        }
        function applyOpacity() {
            if (zonesLayer) zonesLayer.setStyle(zoneStyle);
            if (extentLayer) extentLayer.setStyle(extentStyle);
            if (cellsLayer) cellsLayer.setOpacity(Math.min(1, opacity() / 0.85));   // the image itself is drawn at 85%
        }
        map.on('zoomend', () => {
            if (displayMode === 'auto') updateFloodDisplay();
            if (zonesLayer && map.hasLayer(zonesLayer)) zonesLayer.setStyle(zoneStyle);   // hexagon borders thin out when zoomed out
        });

        function renderLegend(mode) {
            const body = document.getElementById('legend-body');
            const title = document.getElementById('legend-title');
            if (mode === 'cells') {
                title.innerText = 'Predicted water depth';
                body.innerHTML = `<div class="ramp" role="img" aria-label="Colour scale from light blue (15 cm) to dark red (2 m or more)"></div>
                    <div class="ramp-labels"><span>0.15</span><span>0.3</span><span>0.6</span><span>1.2</span><span>2 m+</span></div>
                    <p class="lg-note">Each 30 m cell as predicted by the model. Faded = less certain.</p>`;
                return;
            }
            title.innerText = mode === 'zones' ? 'Flood hazard zones' : 'Flood outlines by depth';
            body.innerHTML = LEVELS.map(l => `<div class="lg-row"><span class="sw" style="background:${l.color}"></span><span>${escapeHtml(l.depth)}</span><em>${escapeHtml(l.meaning)}</em></div>`).join('') +
                `<p class="lg-note">${mode === 'zones'
                    ? 'Each hexagon (10 ha) shows the deepest water covering at least 10% of it. Zoom in for exact outlines.'
                    : 'Outlines of predicted water, smoothed from 30 m cells. Click any spot for its exact depth.'}</p>`;
        }

        // ---------- Overview ----------
        function renderOverview(data) {
            const s = data.summary;
            document.getElementById('ov-storm').innerText = `${data.storm.intensity_mmhr} mm/h for ${data.storm.duration_hr} h`;
            document.getElementById('ov-storm-sub').innerText = `${Math.round(data.storm.rain_total_mm)} mm of rain in total · Nairobi County`;
            const share = 100 * s.flooded_km2 / s.county_km2;
            const z = data.zones || {};
            const hiZones = (z.very_high || 0) + (z.severe || 0);
            document.getElementById('kpis').innerHTML = `
                <div class="kpi"><div class="kpi-label">Area flooded</div><div class="kpi-value">${s.flooded_km2} <small>km²</small></div>
                    <div class="kpi-sub">${share.toFixed(1)}% of the county · ${s.deep_km2} km² deeper than 60 cm</div></div>
                <div class="kpi"><div class="kpi-label">Buildings exposed</div><div class="kpi-value">${fmt(s.buildings_exposed)}</div>
                    <div class="kpi-sub">in water deeper than ${Math.round(s.exposure_depth_m * 100)} cm</div></div>
                <div class="kpi"><div class="kpi-label">Facilities exposed</div><div class="kpi-value">${fmt(s.facilities_exposed)}</div>
                    <div class="kpi-sub">schools, health &amp; emergency services</div></div>
                <div class="kpi"><div class="kpi-label">High-hazard zones</div><div class="kpi-value">${fmt(hiZones)}</div>
                    <div class="kpi-sub">10 ha zones at very high or severe level</div></div>`;

            const total = LEVELS.reduce((a, l) => a + (z[l.key] || 0), 0);
            document.getElementById('zone-total').innerText = total ? `${fmt(total)} zones · ${fmt(Math.round(total * 10.4))} ha` : '';
            document.getElementById('zone-stack').innerHTML = total
                ? LEVELS.map(l => `<i style="width:${100 * (z[l.key] || 0) / total}%;background:${l.color}" title="${l.label}: ${z[l.key] || 0}"></i>`).join('') : '';
            document.getElementById('zone-legend').innerHTML = LEVELS.map(l =>
                `<span><i class="sw" style="background:${l.color}"></i>${escapeHtml(l.label)}<b>${fmt(z[l.key] || 0)}</b></span>`).join('');

            const subs = data.subcounties.slice(0, 3);
            const maxB = Math.max(1, ...data.subcounties.map(x => x.buildings_exposed));
            document.getElementById('ov-subcounties').innerHTML = subs.length ? `<div class="rows">${subs.map((sc, k) => subcountyRow(sc, k, maxB)).join('')}</div>` : '';
            document.getElementById('ov-hotspots').innerHTML = data.hotspots.length
                ? `<div class="rows">${data.hotspots.slice(0, 3).map((h, k) => hotspotRow(h, k)).join('')}</div>`
                : '<div class="empty">No areas with buildings in water deeper than 30 cm for this storm.</div>';
        }
        function subcountyRow(sc, k, maxB) {
            return `<button type="button" class="row" style="--c:${depthColor(sc.p90_depth_m)}" data-sc="${sc.id}" aria-pressed="${selectedSubcounty === sc.id}" onclick="selectSubcounty(${sc.id})">
                <span class="rank" style="color:${inkOn(sc.p90_depth_m)}">${k + 1}</span>
                <span class="row-main"><div class="row-name">${escapeHtml(sc.name)}</div><div class="row-sub">${sc.flooded_km2} km² flooded (${sc.flooded_pct}%)</div></span>
                <span class="row-val">${fmt(sc.buildings_exposed)}<small>buildings</small></span>
                <span class="bar"><i style="width:${(100 * sc.buildings_exposed / maxB).toFixed(1)}%"></i></span></button>`;
        }
        function hotspotRow(h, k) {
            return `<button type="button" class="row" style="--c:${depthColor(h.p90_depth_m)}" onclick="goHotspot(${k})">
                <span class="rank" style="color:${inkOn(h.p90_depth_m)}">${k + 1}</span>
                <span class="row-main"><div class="row-name">${escapeHtml(h.name)}</div><div class="row-sub">${escapeHtml(h.subcounty || '')} · ${h.area_ha} ha in deep water</div></span>
                <span class="row-val">${fmt(h.buildings_exposed)}<small>buildings</small></span></button>`;
        }
        function goHotspot(k) {
            const h = lastData.hotspots[k];
            map.flyTo([h.lat, h.lng], 15);
            inspect(L.latLng(h.lat, h.lng), { title: h.name });
        }

        // ---------- Areas ----------
        function openAreas(list) { setAreaList(list); showModule('areas'); }
        function setAreaList(list) {
            areaList = list;
            document.querySelectorAll('[data-list]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.list === list)));
            document.getElementById('area-search').value = '';
            renderAreaList();
        }
        document.querySelectorAll('[data-list]').forEach(b => b.addEventListener('click', () => setAreaList(b.dataset.list)));
        document.getElementById('area-search').addEventListener('input', renderAreaList);
        function renderAreaList() {
            const box = document.getElementById('area-list');
            const help = document.getElementById('area-help');
            if (!lastData) return;
            const q = document.getElementById('area-search').value.trim().toLowerCase();
            const match = (...names) => !q || names.some(n => String(n || '').toLowerCase().includes(q));
            if (areaList === 'subcounties') {
                help.innerText = 'Ranked by buildings in water deeper than 30 cm. Click to outline on the map.';
                const maxB = Math.max(1, ...lastData.subcounties.map(x => x.buildings_exposed));
                const rows = lastData.subcounties.map((sc, k) => [sc, k]).filter(([sc]) => match(sc.name));
                box.innerHTML = rows.length ? `<div class="rows">${rows.map(([sc, k]) => subcountyRow(sc, k, maxB)).join('')}</div>` : '<div class="empty">No match.</div>';
            } else if (areaList === 'hotspots') {
                help.innerText = '1 km squares with the most buildings in water deeper than 30 cm. Click to inspect.';
                const rows = lastData.hotspots.map((h, k) => [h, k]).filter(([h]) => match(h.name, h.subcounty));
                box.innerHTML = rows.length ? `<div class="rows">${rows.map(([h, k]) => hotspotRow(h, k)).join('')}</div>`
                    : `<div class="empty">${lastData.hotspots.length ? 'No match.' : 'No hotspots for this storm.'}</div>`;
            } else {
                const n = lastData.summary.facilities_exposed;
                help.innerText = n > lastData.facilities.length ? `The ${lastData.facilities.length} deepest of ${n} facilities in water deeper than 30 cm.` : `${n} facilities in water deeper than 30 cm.`;
                const rows = lastData.facilities.map((f, k) => [f, k]).filter(([f]) => match(f.name, f.type));
                box.innerHTML = rows.length ? `<div class="rows">${rows.map(([f, k]) => `
                    <button type="button" class="row" style="--c:${depthColor(f.depth_m)}" onclick="goFacility(${k})">
                        <span class="rank" style="color:${inkOn(f.depth_m)}">${k + 1}</span>
                        <span class="row-main"><div class="row-name">${escapeHtml(f.name)}</div><div class="row-sub">${escapeHtml(String(f.type).replace(/_/g, ' '))}</div></span>
                        <span class="row-val">${escapeHtml(f.depth_label)}<small>depth</small></span></button>`).join('')}</div>`
                    : `<div class="empty">${n ? 'No match.' : 'No facilities in floodwater for this storm.'}</div>`;
            }
        }
        function goFacility(k) {
            const f = lastData.facilities[k];
            map.flyTo([f.lat, f.lng], 16);
            inspect(L.latLng(f.lat, f.lng), { title: f.name });
        }

        // ---------- Map overlays ----------
        function renderHotspotMarkers(data) {
            if (hotspotLayer) map.removeLayer(hotspotLayer);
            hotspotLayer = L.layerGroup(data.hotspots.map((h, k) =>
                L.marker([h.lat, h.lng], {
                    icon: L.divIcon({ className: '', html: `<div class="hs-pin"><span>${k + 1}</span></div>`, iconSize: [26, 26], iconAnchor: [13, 13] }),
                    title: `Hotspot ${k + 1}: ${h.name}`, keyboard: true,
                }).on('click', () => goHotspot(k))));
            if (document.getElementById('lyr-hotspots').checked) hotspotLayer.addTo(map);
        }
        const FAC_COLORS = { hospital: '#be123c', clinic: '#be123c', doctors: '#be123c', school: '#1d4ed8', kindergarten: '#1d4ed8', college: '#1d4ed8', university: '#1d4ed8', police: '#334155', fire_station: '#c2410c' };
        function renderFacilityMarkers(data) {
            if (facilityLayer) map.removeLayer(facilityLayer);
            facilityLayer = L.layerGroup(data.facilities.map((f, k) =>
                L.circleMarker([f.lat, f.lng], { bubblingMouseEvents: false, radius: 6, color: '#fff', weight: 2, fillColor: FAC_COLORS[f.type] || '#7c3aed', fillOpacity: 1 })
                    .bindTooltip(`${escapeHtml(f.name)} · ${escapeHtml(f.depth_label)}`, { className: 'zone-tip' })
                    .on('click', () => goFacility(k))));
            if (document.getElementById('lyr-facilities').checked) facilityLayer.addTo(map);
        }

        function subcountyStyle(f) {
            const sel = selectedSubcounty === f.properties.id;
            const dark = currentTheme() === 'dark' || baseKind === 'satellite';
            const adv = advisoryAreas[f.properties.name];
            if (adv) {
                const c = adv === 'severe' ? '#dc2626' : adv === 'warning' ? '#d97706' : '#0284c7';
                return { color: c, weight: sel ? 4 : 3, opacity: 1, fill: true, fillColor: c, fillOpacity: 0.07, dashArray: null };
            }
            return { color: dark ? '#e2e8f0' : '#1e293b', weight: sel ? 3.5 : 1.2, opacity: sel ? 1 : 0.6,
                     fill: false, dashArray: sel ? null : '5 4' };
        }
        async function loadSubcounties() {
            try {
                const gj = await api('/api/subcounties');
                subcountyLayer = L.geoJSON(gj, { pane: 'bounds', style: subcountyStyle, interactive: false });
                if (document.getElementById('lyr-bounds').checked) subcountyLayer.addTo(map);
                showAdvisoryOutlines();
                if (IS_STAFF) renderAdvisoryAreas();
            } catch (e) { console.warn('Sub-county outlines unavailable:', e.message); }
        }
        function selectSubcounty(id) {
            selectedSubcounty = selectedSubcounty === id ? null : id;
            document.querySelectorAll('.row[data-sc]').forEach(b => b.setAttribute('aria-pressed', String(Number(b.dataset.sc) === selectedSubcounty)));
            if (!subcountyLayer) return;
            if (!map.hasLayer(subcountyLayer)) { subcountyLayer.addTo(map); document.getElementById('lyr-bounds').checked = true; }
            subcountyLayer.setStyle(subcountyStyle);
            subcountyLayer.eachLayer(l => {
                if (l.feature.properties.id === selectedSubcounty) { l.bringToFront(); map.fitBounds(l.getBounds(), { padding: [40, 40] }); }
            });
        }

        // =========================================================
        // 2. INSPECT + EXPLANATION
        // =========================================================
        async function inspect(latlng, { title = null, keepModule = false } = {}) {
            if (!keepModule) showModule('inspect');
            if (spotMarker) map.removeLayer(spotMarker);
            spotMarker = L.marker(latlng, { icon: L.divIcon({ className: '', html: '<div class="spot-pin"></div>', iconSize: [22, 22], iconAnchor: [11, 11] }), interactive: false }).addTo(map);
            lastSpot = { lat: latlng.lat, lng: latlng.lng, title };
            document.getElementById('why-sec').hidden = true;
            const box = document.getElementById('spot-box');
            box.innerHTML = '<div class="skeleton"></div><div class="skeleton" style="margin-top:8px;height:120px"></div>';
            try {
                const p = await api('/api/flood/point', Object.assign({ lat: latlng.lat.toFixed(5), lng: latlng.lng.toFixed(5) }, activeStorm()));
                if (!lastSpot || lastSpot.lat !== latlng.lat || lastSpot.lng !== latlng.lng) return;
                const name = title || p.place || p.subcounty || 'Selected location';
                lastSpot.title = name;
                const where = `${p.subcounty ? p.subcounty + ' sub-county' : 'Outside Nairobi County'} · ${latlng.lat.toFixed(4)}, ${latlng.lng.toFixed(4)}`;
                if (p.permanent_water) {
                    box.innerHTML = `<div class="spot-title">${escapeHtml(name)}</div><div class="muted">${escapeHtml(where)}</div>
                        <div class="empty" style="margin-top:12px">Permanent water body (dam, lake or river). Not counted as flooding.</div>`;
                    return;
                }
                const bg = p.flooded ? depthColor(p.depth_m) : 'var(--safe-soft)';
                const ink = p.flooded ? inkOn(p.depth_m) : 'var(--safe)';
                const z = p.zone, zl = LEVEL[z.level];
                const zoneText = z.level === 'none' ? 'No flooding predicted in the surrounding 10 ha zone.'
                    : `${zl ? zl.label : 'Minor'} hazard zone: ${z.flooded_pct}% of the surrounding 10 ha flooded, deepest ${depthText(z.max_depth_m)}, ${fmt(z.buildings_exposed)} buildings in water deeper than 30 cm.`;
                const rows = Object.entries(p.vehicles).map(([k, v]) => `<tr>
                    <td><svg class="i"><use href="#${VEHICLE_ICON[k] || 'i-car'}"/></svg></td>
                    <td>${escapeHtml((vehicles[k] && vehicles[k].label) || VEHICLE_SHORT[k] || k)}</td>
                    <td style="text-align:right"><span class="verdict ${v}">${VERDICT[v]}</span></td></tr>`).join('');
                box.innerHTML = `
                    <div class="spot-head">
                        <div class="depth-badge" style="background:${bg};color:${ink}"><b>${p.flooded ? escapeHtml(depthText(p.depth_m)) : 'Dry'}</b><span>${p.flooded ? 'water depth' : 'no flooding'}</span></div>
                        <div style="min-width:0"><div class="spot-title">${escapeHtml(name)}</div><div class="muted">${escapeHtml(where)}</div></div>
                    </div>
                    <div class="stat-grid">
                        <div class="stat"><b>${Math.round(p.flood_probability * 100)}%</b><span>flood probability</span></div>
                        <div class="stat"><b>${p.dv_m2s == null ? '—' : p.dv_m2s}</b><span>depth × speed (m²/s)</span></div>
                        <div class="stat"><b>${fmt(p.buildings_in_cell)}</b><span>buildings in 30 m cell</span></div>
                    </div>
                    <div class="zone-card"><span class="sw" style="background:${zl ? zl.color : 'var(--surface-3)'}"></span><span>${escapeHtml(zoneText)}</span></div>
                    <div class="sec"><div class="sec-head"><h2>Can I drive through?</h2></div><table class="veh-table">${rows}</table></div>
                    <div class="sec btn-row">
                        <button class="btn btn-primary" id="why-btn" onclick="explainPoint()"><svg class="i"><use href="#i-chart"/></svg>Why here?</button>
                    </div>
                    <div class="btn-row" style="margin-top:8px">
                        <button class="btn btn-outline" onclick="routeFromSpot('start')">Route from here</button>
                        <button class="btn btn-outline" onclick="routeFromSpot('end')">Route to here</button>
                    </div>
                    ${ME && p.subcounty ? `<button class="btn btn-outline btn-block" style="margin-top:8px" onclick="showPlaceForm(${latlng.lat}, ${latlng.lng}, true)"><svg class="i"><use href="#i-home"/></svg>Save as one of my places</button>` : ''}`;
            } catch (e) {
                box.innerHTML = `<div class="empty">${escapeHtml(e.message)}</div>`;
            }
        }

        async function explainPoint() {
            if (!lastSpot) return;
            const sec = document.getElementById('why-sec');
            sec.hidden = false;
            setBusy('why-btn', true);
            document.getElementById('why-pred').innerText = '';
            const box = document.getElementById('why-bars');
            box.innerHTML = '<div class="muted">Re-running the model with each input group replaced…</div>';
            sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try {
                const d = await api('/api/flood/explain', Object.assign({ lat: lastSpot.lat.toFixed(5), lng: lastSpot.lng.toFixed(5) }, activeStorm()));
                document.getElementById('why-pred').innerText = `probability ${Math.round(d.flood_probability * 100)}%`;
                document.getElementById('why-method').innerText = d.method;
                const maxAbs = Math.max(0.01, ...d.contributions.map(c => Math.abs(c.delta_probability)));
                box.innerHTML = d.contributions.map(c => {
                    const up = c.delta_probability >= 0;
                    const pts = Math.round(c.delta_probability * 100);
                    return `<div class="why-row">
                        <div class="why-label"><span>${escapeHtml(c.group)}</span><b style="color:${up ? 'var(--high)' : 'var(--safe)'}">${up ? '+' : ''}${pts} pts</b></div>
                        <div class="why-track"><div class="why-fill" style="width:${Math.max(3, 100 * Math.abs(c.delta_probability) / maxAbs)}%;background:${up ? '#dc2626' : '#16a34a'}"></div></div></div>`;
                }).join('') + '<p class="muted" style="margin:10px 0 0">Red raises the flood probability here, green lowers it (percentage points).</p>';
            } catch (e) {
                box.innerHTML = `<div class="empty">${escapeHtml(e.message)}</div>`;
            } finally {
                setBusy('why-btn', false);
            }
        }

        function routeFromSpot(which) {
            if (!lastSpot) return;
            showModule('route');
            if (which === 'start') setOrigin(lastSpot.lat, lastSpot.lng, lastSpot.title);
            else setDestination(lastSpot.lat, lastSpot.lng, lastSpot.title);
            if (startCoords && endCoords) fetchRoute(); else showModule('route');
        }

        // =========================================================
        // MODEL INFO
        // =========================================================
        async function loadModelInfo() {
            const box = document.getElementById('model-info');
            try {
                modelInfo = await api('/api/model');
                vehicles = modelInfo.vehicles;
                renderVehicleOptions();
                const m = modelInfo.model;
                const pct = v => v == null ? '--' : (v * 100).toFixed(1) + '%';
                const hand = m.baselines && m.baselines['HAND threshold'] ? m.baselines['HAND threshold'].IoU : null;
                box.innerHTML = `
                    <div class="sec">
                        <p class="prose">An Attention U-Net trained to reproduce a physics-based flood simulation (2D shallow-water flow with
                        SCS curve-number runoff and buildings) for 28 design storms, so a city-wide map takes seconds instead of minutes.</p>
                    </div>
                    <div class="sec">
                        <div class="sec-head"><h2>Performance</h2></div>
                        <div class="kv">
                            <span>Accuracy on unseen areas (IoU)</span><b>${pct(m.test_iou)}</b>
                            <span>…and on unseen storms</span><b>${pct(m.test_iou_unseen_storms)}</b>
                            <span>Terrain-only baseline (HAND)</span><b>${pct(hand)}</b>
                            <span>Calibration error after scaling</span><b>${m.calibration ? (m.calibration.ECE_after * 100).toFixed(1) + '%' : '--'}</b>
                            <span>Resolution</span><b>${m.resolution_m} m</b>
                            <span>Storms covered</span><b>${escapeHtml(m.training_storms)}</b>
                            <span>Flow speed output</span><b>${modelInfo.dv_available ? 'Yes' : 'Not in this version'}</b>
                        </div>
                    </div>
                    ${m.vehicle_metrics ? `<div class="sec">
                        <div class="sec-head"><h2>Vehicle safety calls</h2></div>
                        <p class="prose">How often the model agrees with the simulator that a cell is unsafe for each vehicle, on unseen areas.</p>
                        <div class="kv">
                            ${Object.entries(m.vehicle_metrics).map(([k, v]) => `<span>${escapeHtml(vehicles[k] ? vehicles[k].label : k)}</span>`
                                + `<b>${pct(v.Recall)} caught · ${pct(v.Precision)} correct</b>`).join('')}
                        </div>
                    </div>` : ''}
                    <div class="sec">
                        <div class="sec-head"><h2>How the map is drawn</h2></div>
                        <ul class="bullets">
                            <li><b>Hazard zones</b>: the county is divided into 10-hectare hexagons. Each is coloured by the deepest water band that covers at least 10% of it; zones with less flooding are not drawn.</li>
                            <li><b>Flood outlines</b>: the predicted water area traced as polygons per depth band. Each band is lightly blurred, traced on a 10 m grid and its corners rounded, so edges can differ from the 30 m cells by about half a cell.</li>
                            <li><b>30 m cells</b>: the raw model output. All statistics, spot checks and routes use these cells.</li>
                            <li>Water collects along river channels, so at this resolution many outlines are long and narrow.</li>
                        </ul>
                    </div>
                    <div class="sec">
                        <div class="sec-head"><h2>Limitations</h2></div>
                        <ul class="bullets">
                            <li>Storm drains and culverts are not modelled, so blocked or overflowing drains are not represented.</li>
                            <li>Rivers bringing water from outside the map area (e.g. the Athi River) are not modelled.</li>
                            <li>30 m cells cannot resolve narrow roads, road embankments or single buildings.</li>
                            <li>Soil is assumed to be hydrologic group C everywhere.</li>
                            <li>Closed hollows such as quarries fill up and can show depths over 2 m.</li>
                            <li>Scenario names (Heavy, Intense, Violent, Extreme) describe rainfall rate only; no return period is implied.</li>
                        </ul>
                    </div>`;
            } catch (e) {
                box.innerHTML = `<div class="empty">${escapeHtml(e.message)}</div>`;
                renderVehicleOptions();
            }
        }

        // =========================================================
        // 3. ROUTING
        // =========================================================
        function pinIcon(kind, letter) {
            return L.divIcon({ className: '', html: `<div class="map-pin ${kind}">${letter}</div>`, iconSize: [30, 30], iconAnchor: [15, 15] });
        }
        function selectedVehicle() {
            const el = document.querySelector('input[name="vehicle"]:checked');
            return el ? el.value : 'saloon';
        }
        function limitText(v) {
            if (!v) return '';
            if (v.dv_m2s == null) return 'Avoids any predicted flooding';
            return `Avoids water deeper than ${Math.round(v.depth_m * 100)} cm` + (modelInfo && modelInfo.dv_available ? ` or flowing faster than ${v.dv_m2s} m²/s (depth × speed)` : '');
        }
        function updateVehicleLimit() {
            const k = selectedVehicle();
            const v = vehicles[k];
            document.getElementById('veh-limit').innerText = v ? `${v.label}: ${limitText(v)}.` : '';
        }
        function renderVehicleOptions() {
            const box = document.getElementById('vehicle-options');
            let saved = 'saloon';
            try { saved = localStorage.getItem('floodDssVehicle') || 'saloon'; } catch (e) {}
            const keys = Object.keys(vehicles).length ? Object.keys(vehicles) : ['saloon'];
            box.innerHTML = '<legend>Vehicle</legend>' + keys.map(k => {
                const v = vehicles[k] || { label: 'Saloon car' };
                return `<label class="veh" title="${escapeHtml(v.label)}">
                    <input type="radio" name="vehicle" value="${k}" ${k === saved ? 'checked' : ''} aria-label="${escapeHtml(v.label)}">
                    <svg class="i"><use href="#${VEHICLE_ICON[k] || 'i-car'}"/></svg>${escapeHtml(VEHICLE_SHORT[k] || v.label)}
                </label>`;
            }).join('');
            if (!box.querySelector('input:checked')) box.querySelector('input').checked = true;
            box.querySelectorAll('input').forEach(i => i.addEventListener('change', () => {
                try { localStorage.setItem('floodDssVehicle', i.value); } catch (e) {}
                updateVehicleLimit();
                if (startCoords && endCoords) fetchRoute();
            }));
            updateVehicleLimit();
        }

        function setOrigin(lat, lng, labelText = null) {
            startCoords = { lat: parseFloat(lat), lng: parseFloat(lng) };
            if (startMarker) map.removeLayer(startMarker);
            startMarker = L.marker([startCoords.lat, startCoords.lng], { icon: pinIcon('origin', 'A'), draggable: true, keyboard: true, title: 'Start (drag to move)' }).addTo(map);
            startMarker.on('dragend', e => { const pos = e.target.getLatLng(); setOrigin(pos.lat, pos.lng); if (endCoords) fetchRoute(); });
            if (labelText) document.getElementById('origin-input').value = labelText;
            else reverseGeocode(startCoords.lat, startCoords.lng, 'origin-input');
        }
        function setDestination(lat, lng, labelText = null) {
            endCoords = { lat: parseFloat(lat), lng: parseFloat(lng) };
            if (endMarker) map.removeLayer(endMarker);
            endMarker = L.marker([endCoords.lat, endCoords.lng], { icon: pinIcon('dest', 'B'), draggable: true, keyboard: true, title: 'Destination (drag to move)' }).addTo(map);
            endMarker.on('dragend', e => { const pos = e.target.getLatLng(); setDestination(pos.lat, pos.lng); if (startCoords) fetchRoute(); });
            if (labelText) document.getElementById('dest-input').value = labelText;
            else reverseGeocode(endCoords.lat, endCoords.lng, 'dest-input');
        }

        // Map clicks: place route points in the Route module, otherwise inspect the spot
        map.on('click', function(e) {
            if (addingPlace) { showPlaceForm(e.latlng.lat, e.latlng.lng); return; }
            if (activeModule !== 'route') { inspect(e.latlng); return; }
            if (!startCoords) { setOrigin(e.latlng.lat, e.latlng.lng); showModule('route'); }
            else if (!endCoords) { setDestination(e.latlng.lat, e.latlng.lng); hint(''); fetchRoute(); }
            else { clearRouting(); setOrigin(e.latlng.lat, e.latlng.lng); showModule('route'); }
        });

        async function reverseGeocode(lat, lng, inputId) {
            const inputEl = document.getElementById(inputId);
            inputEl.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
                const data = await res.json();
                if (data && data.display_name) inputEl.value = data.display_name.split(',').slice(0, 2).join(', ');
            } catch (err) {}
        }

        function setupAutocomplete(inputId, suggestionsId, isOrigin) {
            const inputEl = document.getElementById(inputId);
            const suggBox = document.getElementById(suggestionsId);
            let debounceTimer = null;

            inputEl.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                // Typing changes the place, so the old coordinates no longer match
                if (isOrigin) startCoords = null; else endCoords = null;
                const query = this.value.trim();
                if (query.length < 3) { suggBox.style.display = 'none'; return; }

                debounceTimer = setTimeout(async () => {
                    try {
                        const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&q=${encodeURIComponent(query)}&countrycodes=ke&viewbox=36.65,-1.15,37.10,-1.45&bounded=1&limit=5`;
                        const results = await (await fetch(url)).json();
                        suggBox.innerHTML = '';
                        if (results.length === 0) {
                            suggBox.innerHTML = '<div class="suggestion-item" style="cursor:default">No matches in Nairobi. Try another name or click the map.</div>';
                            suggBox.style.display = 'block';
                            return;
                        }
                        results.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'suggestion-item';
                            div.tabIndex = 0;
                            div.setAttribute('role', 'option');
                            const shortLabel = item.display_name.split(',').slice(0, 3).join(', ');
                            div.innerText = shortLabel;
                            const choose = () => {
                                suggBox.style.display = 'none';
                                if (isOrigin) setOrigin(item.lat, item.lon, shortLabel);
                                else setDestination(item.lat, item.lon, shortLabel);
                                map.panTo([parseFloat(item.lat), parseFloat(item.lon)]);
                                if (startCoords && endCoords) fetchRoute(); else showModule('route');
                            };
                            div.onclick = choose;
                            div.onkeydown = (ev) => { if (ev.key === 'Enter') choose(); };
                            suggBox.appendChild(div);
                        });
                        suggBox.style.display = 'block';
                    } catch (e) {}
                }, 350);
            });

            inputEl.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') suggBox.style.display = 'none';
                if (e.key === 'Enter') { const first = suggBox.querySelector('.suggestion-item[tabindex]'); if (first && suggBox.style.display === 'block') { e.preventDefault(); first.click(); } }
                if (e.key === 'ArrowDown') { const first = suggBox.querySelector('.suggestion-item[tabindex]'); if (first) { e.preventDefault(); first.focus(); } }
            });
            document.addEventListener('click', (e) => { if (e.target !== inputEl && !suggBox.contains(e.target)) suggBox.style.display = 'none'; });
        }
        setupAutocomplete('origin-input', 'origin-suggestions', true);
        setupAutocomplete('dest-input', 'dest-suggestions', false);

        function setRouteStatus(tone, title, detail = '', extraHtml = '') {
            const icon = { safe: 'i-check', med: 'i-alert', high: 'i-alert', info: 'i-info' }[tone] || 'i-info';
            const t = tone === 'info' ? 'none' : tone;
            document.getElementById('route-status').innerHTML = `
                <div class="result" data-tone="${t}">
                    <div class="result-head"><svg class="i"><use href="#${icon}"/></svg>${escapeHtml(title)}</div>
                    ${detail ? `<p>${escapeHtml(detail)}</p>` : ''}${extraHtml}
                </div>`;
        }

        function useMyLocation() {
            if (!navigator.geolocation) { setRouteStatus('med', 'Location is not available', 'Type your starting place instead.'); return; }
            setBusy('locate-btn', true);
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    setBusy('locate-btn', false);
                    setOrigin(pos.coords.latitude, pos.coords.longitude, 'My location');
                    map.setView([pos.coords.latitude, pos.coords.longitude], 14);
                    if (endCoords) fetchRoute(); else showModule('route');
                },
                () => {
                    setBusy('locate-btn', false);
                    setRouteStatus('med', "Couldn't get your location", 'Allow location access in your browser, or type a starting place.');
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        function swapPoints() {
            const oText = document.getElementById('origin-input').value;
            const dText = document.getElementById('dest-input').value;
            const s = startCoords, e = endCoords;
            if (startMarker) map.removeLayer(startMarker);
            if (endMarker) map.removeLayer(endMarker);
            startMarker = endMarker = null; startCoords = endCoords = null;
            if (e) setOrigin(e.lat, e.lng, dText); else document.getElementById('origin-input').value = dText;
            if (s) setDestination(s.lat, s.lng, oText); else document.getElementById('dest-input').value = oText;
            if (startCoords && endCoords) fetchRoute();
        }

        function handleFindRouteClick() {
            const o = document.getElementById('origin-input').value.trim();
            const d = document.getElementById('dest-input').value.trim();
            if (!o && !d) { setRouteStatus('med', 'Add a start and a destination', 'Search for a place, click the map, or use your location.'); return; }
            if (!o) { setRouteStatus('med', 'Where are you starting from?', 'Add a start, or use your location.'); return; }
            if (!d) { setRouteStatus('med', 'Where are you going?', 'Add a destination or click it on the map.'); return; }
            if (!startCoords || !endCoords) { setRouteStatus('med', 'Choose a place from the list', 'Pick a suggestion under the field, or click the point on the map.'); return; }
            fetchRoute();
        }

        function clearRouting() {
            [startMarker, endMarker, routeLayer].forEach(l => { if (l) map.removeLayer(l); });
            startMarker = endMarker = routeLayer = null;
            startCoords = endCoords = null;
            document.getElementById('origin-input').value = '';
            document.getElementById('dest-input').value = '';
            document.getElementById('legend-route').hidden = true;
            document.getElementById('route-status').innerHTML = '<p class="muted">Route cleared. Click the map to place A, then B.</p>';
            if (activeModule === 'route') showModule('route');
        }

        const ROUTE_STYLE = {
            safe:   { pane: 'routes', color: '#16a34a', weight: 7, opacity: 0.95, lineCap: 'round', lineJoin: 'round' },
            direct: { pane: 'routes', color: '#8b5cf6', weight: 4, opacity: 0.85, dashArray: '9, 9', lineCap: 'round' },
            hazard: { pane: 'routes', color: '#dc2626', weight: 9, opacity: 0.95, lineCap: 'round' },
        };

        async function fetchRoute() {
            const storm = activeStorm();
            const vehicle = selectedVehicle();
            hint('');
            setRouteStatus('info', 'Finding the safest route…', simStorm ? '' : 'Running the flood model for this storm first. This can take up to 30 seconds.');
            setBusy('route-btn', true);
            try {
                const data = await api('/api/route', Object.assign({
                    start_lat: startCoords.lat.toFixed(5), start_lng: startCoords.lng.toFixed(5),
                    end_lat: endCoords.lat.toFixed(5), end_lng: endCoords.lng.toFixed(5), vehicle,
                }, storm));
                if (!startCoords || !endCoords) return;   // cleared while the request was running
                if (routeLayer) map.removeLayer(routeLayer);
                const order = { direct: 0, safe: 1, hazard: 2 };
                const feats = data.features.slice().sort((a, b) => order[a.properties.route_type] - order[b.properties.route_type]);
                routeLayer = L.geoJSON({ type: 'FeatureCollection', features: feats }, {
                    style: f => ROUTE_STYLE[f.properties.route_type], bubblingMouseEvents: false,
                    onEachFeature: (f, layer) => {
                        const p = f.properties;
                        if (p.route_type === 'hazard') {
                            layer.bindTooltip(`<b>${escapeHtml(p.road)}</b><br>Unsafe for this vehicle · up to ${depthText(p.max_depth_m)} deep` +
                                (p.max_dv_m2s != null ? ` · ${p.max_dv_m2s} m²/s` : ''), { sticky: true, className: 'zone-tip' });
                        }
                    }
                }).addTo(map);
                document.getElementById('legend-route').hidden = false;
                if (routeLayer.getBounds().isValid()) map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });

                const P = data.properties;
                const safe = data.features.find(f => f.properties.route_type === 'safe');
                const s = safe ? safe.properties : null;
                const d = P.direct;
                const metrics = (r, label) => `<div class="metrics">
                    <div class="metric"><b>${r.distance_km} km</b><span>${label} distance</span></div>
                    <div class="metric"><b>${Math.round(r.duration_min)} min</b><span>travel time</span></div>
                    <div class="metric"><b>${fmt(r.flooded_length_m)} m</b><span>through water</span></div></div>`;
                const details = `<details class="fine"><summary>Vehicle limits and assumptions</summary>
                    <p>${escapeHtml(`${P.vehicle_label}: ${limitText(vehicles[P.vehicle] || P.limits)}. Basis: ${P.limits.basis}.`)}</p>
                    ${P.notes && P.notes.length ? `<p>${escapeHtml(P.notes.join(' '))}</p>` : ''}
                    <p>${escapeHtml(P.assumptions)}</p>
                    <p>Scenario: ${P.storm.intensity_mmhr} mm/h for ${P.storm.duration_hr} h.</p></details>`;

                if (P.status === 'clear') {
                    setRouteStatus('safe', 'Route is clear', s.flooded_length_m > 0
                        ? `No unsafe water on the way, but ${fmt(s.flooded_length_m)} m runs through shallow water: drive slowly.`
                        : 'No predicted flooding on the way.', metrics(s, 'Route') + details);
                } else if (P.status === 'detour') {
                    setRouteStatus(P.advisory ? 'med' : 'safe', P.advisory ? 'Long detour – consider waiting' : 'Safe detour found',
                        `Avoids ${d.unsafe_segments} unsafe ${d.unsafe_segments === 1 ? 'stretch' : 'stretches'} on the direct route (red). Direct route: ${Math.round(d.duration_min)} min.` +
                        (P.advisory ? ' ' + P.advisory : ''), metrics(s, 'Detour') + details);
                } else {
                    setRouteStatus('high', 'No safe route for this vehicle', P.advisory +
                        ` The direct route crosses ${d.unsafe_segments} unsafe ${d.unsafe_segments === 1 ? 'stretch' : 'stretches'} (red), up to ${depthText(d.max_depth_m)} deep.`, details);
                }
            } catch (err) {
                console.error('Routing error:', err);
                setRouteStatus('high', "Couldn't calculate the route", err.message);
            } finally {
                setBusy('route-btn', false);
            }
        }

        // =========================================================
        // ALERTS (polled every minute; the poll also runs the server's automatic checks)
        // =========================================================
        const ALERT_POLL_MS = 60000;
        const LEVEL_RANK = { info: 0, warning: 1, severe: 2 };
        const KIND_TEXT = { system: 'System', weather: 'Weather', advisory: 'Advisory', place: 'Your places' };
        const seenAlerts = new Set();
        let alertsPrimed = false, advisoryOutlinesShown = false;

        async function apiSend(method, path, body) {
            const res = await fetch(path, {
                method,
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json',
                           'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: body ? JSON.stringify(body) : undefined,
            });
            let data = null;
            try { data = await res.json(); } catch (e) {}
            if (!res.ok) {
                const first = data && data.errors ? Object.values(data.errors)[0][0] : null;
                throw new Error(first || (data && (data.error || data.message)) || `Request failed (HTTP ${res.status})`);
            }
            return data;
        }
        // Guests have no account, so what they have read is remembered in this browser only
        function guestReadSet() { try { return new Set(JSON.parse(localStorage.getItem('floodDssReadAlerts') || '[]')); } catch (e) { return new Set(); } }
        function isRead(a) { return ME ? a.read : guestReadSet().has(a.id); }
        function timeText(iso) { return new Date(iso).toLocaleString('en-KE', { weekday: 'short', hour: '2-digit', minute: '2-digit' }); }
        function ago(iso) {
            const m = Math.round((Date.now() - new Date(iso)) / 60000);
            return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`;
        }

        async function pollAlerts() {
            try {
                const r = await api('/api/alerts');
                alerts = r.alerts || [];
                if (r.weather) weatherInfo = r.weather;
            } catch (e) { return; }
            const fresh = alerts.filter(a => !seenAlerts.has(a.id) && !isRead(a));
            alerts.forEach(a => seenAlerts.add(a.id));
            if (alertsPrimed && fresh.length) {
                const top = fresh.sort((a, b) => LEVEL_RANK[b.level] - LEVEL_RANK[a.level])[0];
                toast(`${top.title}. ${top.body}`, top.level === 'severe' ? 'error' : 'info', 12000);
            }
            alertsPrimed = true;

            advisoryAreas = {};
            alerts.filter(a => a.kind === 'advisory').forEach(a => a.areas.forEach(n => {
                if (!advisoryAreas[n] || LEVEL_RANK[a.level] > LEVEL_RANK[advisoryAreas[n]]) advisoryAreas[n] = a.level;
            }));
            showAdvisoryOutlines();
            refreshAlertViews();
            if (IS_STAFF && activeModule === 'response') renderResponseAdvisories();
            if (ME && activeModule === 'places') renderPlaces();
        }
        // Outline advisory sub-counties on the map (switches the boundary layer on once per visit)
        function showAdvisoryOutlines() {
            if (!subcountyLayer) return;
            subcountyLayer.setStyle(subcountyStyle);
            if (Object.keys(advisoryAreas).length && !advisoryOutlinesShown && !map.hasLayer(subcountyLayer)) {
                subcountyLayer.addTo(map);
                document.getElementById('lyr-bounds').checked = true;
            }
            if (Object.keys(advisoryAreas).length) advisoryOutlinesShown = true;
        }
        function refreshAlertViews() {
            renderBell();
            renderBanner();
            if (!document.getElementById('alert-pop').hidden) renderAlertPop();
        }
        function renderBell() {
            const n = alerts.filter(a => !isRead(a)).length;
            const c = document.getElementById('bell-count');
            c.hidden = !n;
            c.innerText = n > 9 ? '9+' : n;
            document.getElementById('bell-btn').setAttribute('aria-label', n ? `Alerts, ${n} unread` : 'Alerts');
        }
        function toggleAlerts(force) {
            const pop = document.getElementById('alert-pop');
            const open = force !== undefined ? force : pop.hidden;
            pop.hidden = !open;
            document.getElementById('bell-btn').setAttribute('aria-expanded', String(open));
            if (open) renderAlertPop();
        }
        function alertCard(a) {
            const forStaff = a.audience === 'staff' || a.audience === 'admin';
            const actions = [];
            if (a.action && a.action.type === 'scenario')
                actions.push(`<button class="btn btn-primary" onclick="runAlertScenario(${Number(a.action.intensity)}, ${Number(a.action.duration)})">Run ${Number(a.action.intensity)} mm/h · ${Number(a.action.duration)} h</button>`);
            if (a.areas.length) actions.push(`<button class="btn btn-outline" onclick="showAdvisoryAreas(${a.id})">Show on map</button>`);
            if (!isRead(a)) actions.push(`<button class="btn btn-outline" onclick="markRead(${a.id})">${forStaff ? 'Acknowledge' : 'Mark as read'}</button>`);
            if (a.can_withdraw) actions.push(`<button class="btn btn-outline" onclick="withdrawAdvisory(${a.id})">Withdraw</button>`);
            const who = a.kind === 'advisory' && a.issued_by ? ` by ${escapeHtml(a.issued_by)}` : '';
            return `<article class="al" data-level="${a.level}" data-unread="${!isRead(a)}">
                <div class="al-head"><span class="tag ${a.level}">${escapeHtml(a.level)}</span><span class="muted">${KIND_TEXT[a.kind] || ''}${who} · ${ago(a.created_at)}</span></div>
                <b>${escapeHtml(a.title)}</b><p>${escapeHtml(a.body)}</p>
                ${a.areas.length ? `<p class="muted">Areas: ${a.areas.map(escapeHtml).join(', ')}</p>` : ''}
                ${a.expires_at ? `<p class="muted">Until ${timeText(a.expires_at)}</p>` : ''}
                ${actions.length ? `<div class="btn-row">${actions.join('')}</div>` : ''}
            </article>`;
        }
        function renderAlertPop() {
            const sorted = [...alerts].sort((a, b) => (isRead(a) - isRead(b)) || (LEVEL_RANK[b.level] - LEVEL_RANK[a.level])
                || (new Date(b.created_at) - new Date(a.created_at)));
            const unread = alerts.filter(a => !isRead(a)).length;
            document.getElementById('alert-pop').innerHTML = `<h3>Alerts <span class="muted">${unread ? unread + ' unread' : 'All read'}</span></h3>`
                + (sorted.length ? sorted.map(a => alertCard(a)).join('')
                    : `<div class="empty">No active alerts.${ME ? '' : ' Sign in to save places and get alerts about them.'}</div>`);
        }
        // The most severe unread advisory is shown as a banner across the map
        function renderBanner() {
            const el = document.getElementById('adv-banner');
            const a = alerts.filter(x => (x.kind === 'advisory' || x.kind === 'place') && x.level !== 'info' && !isRead(x))
                .sort((p, q) => (LEVEL_RANK[q.level] - LEVEL_RANK[p.level]) || (new Date(q.created_at) - new Date(p.created_at)))[0];
            document.getElementById('app').classList.toggle('has-banner', !!a);
            if (!a) { el.hidden = true; return; }
            const more = alerts.filter(x => !isRead(x)).length - 1;
            el.dataset.level = a.level;
            el.innerHTML = `<svg class="i"><use href="#i-alert"/></svg>
                <div style="min-width:0"><b>${escapeHtml(a.title)}</b><div class="msg">${escapeHtml(a.body)}</div>
                    <div class="btns">${a.areas.length ? `<button class="link" onclick="showAdvisoryAreas(${a.id})">Show areas</button>` : ''}
                    <button class="link" onclick="toggleAlerts(true)">${more > 0 ? `Details and ${more} more alert${more > 1 ? 's' : ''}` : 'Details'}</button></div></div>
                <button class="icon-btn" aria-label="Dismiss" onclick="markRead(${a.id})"><svg class="i"><use href="#i-x"/></svg></button>`;
            el.hidden = false;
        }
        async function markRead(id) {
            const a = alerts.find(x => x.id === id);
            if (!a) return;
            if (ME) {
                try { await apiSend('POST', `/api/alerts/${id}/ack`); } catch (e) { toast(e.message, 'error'); return; }
                a.read = true;
            } else {
                const s = guestReadSet();
                s.add(id);
                try { localStorage.setItem('floodDssReadAlerts', JSON.stringify([...s].slice(-200))); } catch (e) {}
            }
            refreshAlertViews();
            if (IS_STAFF && activeModule === 'response') renderResponseAdvisories();
        }
        function showAdvisoryAreas(id) {
            const a = alerts.find(x => x.id === id);
            if (!a || !subcountyLayer) return;
            if (!map.hasLayer(subcountyLayer)) { subcountyLayer.addTo(map); document.getElementById('lyr-bounds').checked = true; }
            const hit = [];
            subcountyLayer.eachLayer(l => { if (a.areas.includes(l.feature.properties.name)) hit.push(l); });
            if (hit.length) map.fitBounds(L.featureGroup(hit).getBounds(), { padding: [40, 40] });
            toggleAlerts(false);
        }
        function runAlertScenario(i, d) {
            toggleAlerts(false);
            setSliders(i, d);
            runFloodSimulation();
            showModule('overview');
        }
        async function withdrawAdvisory(id) {
            const a = alerts.find(x => x.id === id);
            if (!a || !confirm(`Withdraw the advisory "${a.title}"? It disappears from everyone's map.`)) return;
            try { await apiSend('POST', `/api/advisories/${id}/withdraw`); } catch (e) { toast(e.message, 'error'); return; }
            toast('Advisory withdrawn.');
            pollAlerts();
        }

        // =========================================================
        // MY PLACES (signed-in residents)
        // =========================================================
        const MAX_PLACES = 5;
        let pendingPlace = null;
        async function loadPlaces() {
            try { places = await api('/api/places'); } catch (e) { places = []; }
            await refreshPlaceStatus();
            renderPlaces();
        }
        // Hazard at each place for the scenario on screen (the model server has the storm cached, so this is quick)
        async function refreshPlaceStatus({ announce = false } = {}) {
            if (!ME || !simStorm || !places.length) return;
            const storm = simStorm;
            await Promise.all(places.map(async p => {
                try { p.status = await api('/api/flood/point', Object.assign({ lat: p.lat, lng: p.lng }, storm)); } catch (e) { p.status = null; }
            }));
            if (storm !== simStorm) return;
            if (activeModule === 'places') renderPlaces();
            if (announce) {
                const hit = places.filter(p => p.status && ['high', 'very_high', 'severe'].includes(p.status.zone.level));
                if (hit.length) toast(`${hit.map(p => p.name).join(', ')} ${hit.length > 1 ? 'are' : 'is'} in a High or worse hazard zone in this scenario.`, 'error', 10000);
            }
        }
        function placeStatusHtml(p) {
            const s = p.status;
            if (!simStorm) return '<small>Run a scenario</small>';
            if (!s) return '…<small>checking</small>';
            const z = LEVEL[s.zone.level];
            const zone = z ? `${z.label} zone` : s.zone.level === 'minor' ? 'Minor flooding near' : 'No hazard zone';
            return `${s.flooded ? escapeHtml(depthText(s.depth_m)) : 'Dry'}<small>${zone}</small>`;
        }
        function renderPlaces() {
            const box = document.getElementById('places-list');
            if (!box) return;
            document.getElementById('places-count').innerText = `${places.length} of ${MAX_PLACES}`;
            document.getElementById('add-place-btn').disabled = places.length >= MAX_PLACES;
            if (!places.length) { box.innerHTML = `<div class="empty">No saved places yet. Add your home, work or a child's school.</div>`; return; }
            box.innerHTML = `<div class="rows">${places.map((p, k) => {
                const z = p.status ? LEVEL[p.status.zone.level] : null;
                const adv = advisoryAreas[p.subcounty];
                return `<div class="row" style="--c:${z ? z.color : 'var(--surface-3)'};cursor:default">
                    <span class="rank" aria-hidden="true"><svg class="i" style="width:15px;height:15px"><use href="#i-home"/></svg></span>
                    <span class="row-main"><div class="row-name">${escapeHtml(p.name)}${adv ? ` <span class="tag ${adv}">Advisory</span>` : ''}</div>
                        <div class="row-sub">${escapeHtml(p.subcounty || '')}</div></span>
                    <span class="row-val">${placeStatusHtml(p)}</span>
                    <span class="row-actions"><button class="link" onclick="goPlace(${k})">Inspect</button><button class="link" onclick="routeToPlace(${k})">Route here</button><button class="link" onclick="deletePlace(${p.id})">Remove</button></span>
                </div>`;
            }).join('')}</div>`;
        }
        function goPlace(k) {
            const p = places[k];
            map.flyTo([p.lat, p.lng], 16);
            inspect(L.latLng(p.lat, p.lng), { title: p.name });
        }
        function routeToPlace(k) {
            const p = places[k];
            showModule('route');
            setDestination(p.lat, p.lng, p.name);
            if (startCoords) fetchRoute();
        }
        function startAddPlace() {
            if (places.length >= MAX_PLACES) return;
            addingPlace = true;
            hint('<svg class="i"><use href="#i-pin"/></svg> Click the map to choose the place');
            map.getContainer().style.cursor = 'crosshair';
            if (window.innerWidth <= 820) setPanel(false);
        }
        function showPlaceForm(lat, lng) {
            if (places.length >= MAX_PLACES) { toast(`You can save up to ${MAX_PLACES} places. Remove one first.`, 'error'); return; }
            pendingPlace = { lat, lng };
            showModule('places');
            hint('');
            map.getContainer().style.cursor = '';
            const box = document.getElementById('place-form');
            box.hidden = false;
            box.innerHTML = `<form onsubmit="savePlace(event)" class="scenario-card" style="display:block">
                <label class="fld" style="margin-top:0"><span>Name this place</span><input id="place-name" maxlength="60" required placeholder="e.g. Home, Office, School"></label>
                <p class="muted" style="margin:6px 0 0">${lat.toFixed(4)}, ${lng.toFixed(4)}</p>
                <div class="btn-row" style="margin-top:10px"><button class="btn btn-primary" id="place-save">Save place</button><button type="button" class="btn btn-outline" onclick="cancelPlace()">Cancel</button></div>
            </form>`;
            document.getElementById('place-name').focus();
        }
        function cancelPlace() {
            pendingPlace = null;
            const box = document.getElementById('place-form');
            box.hidden = true;
            box.innerHTML = '';
        }
        async function savePlace(e) {
            e.preventDefault();
            if (!pendingPlace) return;
            setBusy('place-save', true);
            try {
                const p = await apiSend('POST', '/api/places', {
                    name: document.getElementById('place-name').value.trim(),
                    lat: pendingPlace.lat.toFixed(6), lng: pendingPlace.lng.toFixed(6),
                });
                places.push(p);
                cancelPlace();
                toast(`Saved "${p.name}" in ${p.subcounty}.`);
                renderPlaces();
                await refreshPlaceStatus();
                renderPlaces();
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                setBusy('place-save', false);
            }
        }
        async function deletePlace(id) {
            const p = places.find(x => x.id === id);
            if (!p || !confirm(`Remove "${p.name}" from your places?`)) return;
            try { await apiSend('DELETE', `/api/places/${id}`); } catch (e) { toast(e.message, 'error'); return; }
            places = places.filter(x => x.id !== id);
            renderPlaces();
        }

        // =========================================================
        // RESPONSE (responders and admins)
        // =========================================================
        const RAIN_ALERT_MMHR = 10;   // SystemMonitor::RAIN_WARNING_MMHR
        let respPane = 'advisories';
        document.querySelectorAll('[data-resp]').forEach(b => b.addEventListener('click', () => { respPane = b.dataset.resp; renderResponse(); }));
        function renderResponse() {
            if (!IS_STAFF) return;
            document.querySelectorAll('[data-resp]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.resp === respPane)));
            document.querySelectorAll('[data-resp-pane]').forEach(p => { p.hidden = p.dataset.respPane !== respPane; });
            if (respPane === 'advisories') { renderAdvisoryAreas(); renderResponseAdvisories(); }
            if (respPane === 'facilities') renderResponseFacilities();
            if (respPane === 'report') renderForecast();
        }
        function renderAdvisoryAreas() {
            const box = document.getElementById('adv-areas');
            if (!box || !subcountyLayer || box.dataset.ready) return;
            const names = [];
            subcountyLayer.eachLayer(l => names.push(l.feature.properties.name));
            names.sort();
            box.innerHTML = names.map(n => `<label><input type="checkbox" name="areas" value="${escapeHtml(n)}">${escapeHtml(n)}</label>`).join('');
            box.dataset.ready = '1';
        }
        // Draft an advisory from the scenario on screen: the three sub-counties with most buildings exposed
        function prefillAdvisory() {
            if (!lastData || !simStorm) { toast('Run a scenario first.', 'error'); return; }
            renderAdvisoryAreas();
            const top = lastData.subcounties.filter(s => s.buildings_exposed > 0).slice(0, 3);
            if (!top.length) { toast('No buildings are exposed in this scenario, so there is nothing to draft.', 'info'); return; }
            const f = document.getElementById('adv-form');
            f.querySelectorAll('input[name="areas"]').forEach(c => { c.checked = top.some(s => s.name === c.value); });
            const names = top.map(s => s.name);
            const list = names.length > 1 ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1] : names[0];
            const b = top.reduce((n, s) => n + s.buildings_exposed, 0);
            f.level.value = simStorm.intensity >= 50 ? 'severe' : 'warning';
            f.title.value = `Flooding likely in ${list}`;
            f.body.value = `If ${simStorm.intensity} mm/h of rain falls for ${simStorm.duration} h, the flood model expects water deeper than 30 cm around about ${fmt(b)} buildings in these sub-counties. Keep away from river banks and low-lying roads, do not walk or drive through moving water, and move people and valuables off ground floors in flood-prone areas.`;
        }
        async function publishAdvisory(e) {
            e.preventDefault();
            const f = e.target;
            const areas = [...f.querySelectorAll('input[name="areas"]:checked')].map(c => c.value);
            if (!areas.length) { toast('Choose at least one sub-county.', 'error'); return; }
            if (!confirm(`Publish this ${f.level.value} advisory for ${areas.join(', ')}? Everyone using the map will see it.`)) return;
            setBusy('adv-submit', true);
            try {
                const r = await apiSend('POST', '/api/advisories', {
                    level: f.level.value, title: f.title.value.trim(), body: f.body.value.trim(), areas, hours: Number(f.hours.value),
                });
                seenAlerts.add(r.id);
                toast(`Advisory published.${r.residents_notified ? ` ${r.residents_notified} resident${r.residents_notified > 1 ? 's' : ''} with saved places there were alerted.` : ''}`);
                f.reset();
                await pollAlerts();
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                setBusy('adv-submit', false);
            }
        }
        function renderResponseAdvisories() {
            const box = document.getElementById('resp-advisories');
            if (!box) return;
            const adv = alerts.filter(a => a.kind === 'advisory');
            box.innerHTML = adv.length ? adv.map(a => alertCard(a)).join('') : '<div class="empty">No active advisories.</div>';
        }
        function renderResponseFacilities() {
            const box = document.getElementById('resp-facilities');
            if (!lastData || !simStorm) return;
            const f = lastData.facilities, n = lastData.summary.facilities_exposed;
            document.getElementById('resp-fac-sub').innerText = `${fmt(n)} schools and health facilities are in water deeper than 30 cm for ${simStorm.intensity} mm/h · ${simStorm.duration} h`
                + (n > f.length ? ` (the ${f.length} deepest are listed)` : '') + '. Plan access with a lorry route, or inspect a site.';
            box.innerHTML = f.length ? `<div class="rows">${f.map((x, k) => `
                <div class="row" style="--c:${depthColor(x.depth_m)};cursor:default">
                    <span class="rank" style="color:${inkOn(x.depth_m)}">${k + 1}</span>
                    <span class="row-main"><div class="row-name">${escapeHtml(x.name)}</div><div class="row-sub">${escapeHtml(String(x.type).replace(/_/g, ' '))}</div></span>
                    <span class="row-val">${escapeHtml(x.depth_label)}<small>depth</small></span>
                    <span class="row-actions"><button class="link" onclick="goFacility(${k})">Inspect</button><button class="link" onclick="routeToFacility(${k})">Lorry route here</button></span>
                </div>`).join('')}</div>` : '<div class="empty">No facilities in floodwater for this storm.</div>';
        }
        function routeToFacility(k) {
            const f = lastData.facilities[k];
            showModule('route');
            setDestination(f.lat, f.lng, f.name);
            const r = document.querySelector('input[name="vehicle"][value="lorry_bus"]');
            if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }   // the change handler fetches the route
            else if (startCoords) fetchRoute();
        }
        function renderForecast() {
            const box = document.getElementById('wx-forecast');
            const w = weatherInfo;
            if (!w || !w.series) { box.innerHTML = '<div class="empty">Forecast not loaded yet. It is fetched from Open-Meteo about once a minute.</div>'; return; }
            const s = w.series, W = 300, H = 92, maxV = Math.max(15, ...s.map(x => x.mm || 0));
            const bw = W / s.length, y = v => H - 14 - (v / maxV) * (H - 26);
            const bars = s.map((x, k) => { const v = x.mm || 0; return `<rect x="${(k * bw + 1).toFixed(1)}" y="${y(v).toFixed(1)}" width="${Math.max(1, bw - 2).toFixed(1)}" height="${(H - 14 - y(v)).toFixed(1)}" fill="${v >= RAIN_ALERT_MMHR ? '#d97706' : '#38bdf8'}"><title>${escapeHtml(x.time.slice(11, 16))}: ${v} mm</title></rect>`; }).join('');
            const ticks = s.map((x, k) => k % 6 === 0 ? `<text x="${(k * bw + bw / 2).toFixed(1)}" y="${H - 2}" font-size="9" text-anchor="middle" fill="currentColor">${escapeHtml(x.time.slice(11, 16))}</text>` : '').join('');
            const ty = y(RAIN_ALERT_MMHR).toFixed(1);
            const day = new Date(w.peak_time).toLocaleDateString('en-KE', { weekday: 'short' });
            box.innerHTML = `<svg class="wx-chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Hourly rain forecast, peak ${w.peak_mmhr} mm per hour">${bars}
                    <line x1="0" x2="${W}" y1="${ty}" y2="${ty}" stroke="#d97706" stroke-dasharray="4 3"/>
                    <text x="${W - 2}" y="${ty - 3}" font-size="9" text-anchor="end" fill="#d97706">${RAIN_ALERT_MMHR} mm/h alert level</text>${ticks}</svg>
                <p class="muted" style="margin:6px 0 0">Peak ${w.peak_mmhr} mm/h, ${day} ${escapeHtml(w.peak_time.slice(11, 16))}. ${w.peak_mmhr >= RAIN_ALERT_MMHR ? 'A heavy-rain alert has been raised.' : 'Below the alert level.'}
                Hourly forecast totals smooth out short downpours, so real peaks can be higher.</p>`;
        }

        // Situation report for the scenario on screen
        function sitrepRows() {
            const d = lastData, s = simStorm;
            return [
                ['Nairobi Flood DSS situation report'], ['Generated', new Date().toLocaleString('en-KE')],
                ['Scenario', `${s.intensity} mm/h for ${s.duration} h (${Math.round(s.intensity * s.duration)} mm)`],
                ['Source', 'Model prediction (U-Net surrogate of a 2D shallow-water simulation, 30 m cells), not observations'], [],
                ['Summary'], ['Area flooded (km2)', d.summary.flooded_km2], ['Area deeper than 30 cm (km2)', d.summary.deep_km2],
                ['Buildings in water deeper than 30 cm', d.summary.buildings_exposed], ['Facilities in water deeper than 30 cm', d.summary.facilities_exposed], [],
                ['Sub-counties'], ['Rank', 'Sub-county', 'Flooded (km2)', 'Flooded (%)', 'Buildings exposed', '90th percentile depth (m)'],
                ...d.subcounties.map((x, k) => [k + 1, x.name, x.flooded_km2, x.flooded_pct, x.buildings_exposed, x.p90_depth_m]), [],
                ['Hotspots (1 km squares)'], ['Rank', 'Place', 'Sub-county', 'Lat', 'Lng', 'Area in deep water (ha)', 'Buildings exposed', 'Depth'],
                ...d.hotspots.map((x, k) => [k + 1, x.name, x.subcounty, x.lat, x.lng, x.area_ha, x.buildings_exposed, x.depth_label]), [],
                ['Facilities at risk'], ['Rank', 'Name', 'Type', 'Lat', 'Lng', 'Depth'],
                ...d.facilities.map((x, k) => [k + 1, x.name, x.type, x.lat, x.lng, x.depth_label]), [],
                ['Active advisories'], ['Level', 'Headline', 'Areas', 'Issued', 'Until'],
                ...alerts.filter(a => a.kind === 'advisory').map(a => [a.level, a.title, a.areas.join('; '), a.created_at, a.expires_at || '']),
            ];
        }
        function downloadSitrepCsv() {
            if (!lastData) { toast('Run a scenario first.', 'error'); return; }
            // Quote cells with separators, and neutralise text that a spreadsheet would run as a formula
            const cell = v => {
                let t = String(v ?? '');
                if (typeof v === 'string' && /^[=+\-@]/.test(t)) t = "'" + t;
                return /[",\r\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t;
            };
            const csv = sitrepRows().map(r => r.map(cell).join(',')).join('\r\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
            a.download = `nairobi-flood-sitrep-${simStorm.intensity}mmh-${simStorm.duration}h-${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        }
        function printSitrep() {
            if (!lastData) { toast('Run a scenario first.', 'error'); return; }
            const d = lastData, s = simStorm, adv = alerts.filter(a => a.kind === 'advisory');
            const tbl = (head, rows) => `<table><thead><tr>${head.map(h => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
                <tbody>${rows.map(r => `<tr>${r.map(c => `<td>${escapeHtml(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
            const w = window.open('', '_blank');
            if (!w) { toast('Allow pop-ups for this site to print the report.', 'error'); return; }
            w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Flood situation report, ${s.intensity} mm/h for ${s.duration} h</title><style>
                body{font:12.5px/1.4 system-ui,sans-serif;margin:24px;color:#0f172a} h1{font-size:20px;margin:0} h2{font-size:14px;margin:18px 0 6px}
                table{width:100%;border-collapse:collapse} th,td{border:1px solid #cbd5e1;padding:3px 6px;text-align:left} th{background:#f1f5f9}
                .kv{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px} .kv div{border:1px solid #cbd5e1;padding:8px;border-radius:6px} .kv b{display:block;font-size:17px}
                .muted{color:#475569} @media print{h2{break-after:avoid} tr{break-inside:avoid}}</style></head><body>
                <h1>Nairobi flood situation report</h1>
                <p class="muted">Scenario: ${s.intensity} mm/h for ${s.duration} h (${Math.round(s.intensity * s.duration)} mm of rain). Generated ${escapeHtml(new Date().toLocaleString('en-KE'))}.
                Model predictions from a U-Net surrogate of a 2D shallow-water simulation at 30 m, not observations.</p>
                <div class="kv"><div><b>${d.summary.flooded_km2} km²</b>flooded</div><div><b>${fmt(d.summary.buildings_exposed)}</b>buildings in water deeper than 30 cm</div>
                    <div><b>${fmt(d.summary.facilities_exposed)}</b>facilities in water deeper than 30 cm</div><div><b>${adv.length}</b>active advisories</div></div>
                ${adv.length ? `<h2>Active advisories</h2>${tbl(['Level', 'Headline', 'Areas', 'Until'], adv.map(a => [a.level, a.title, a.areas.join(', '), a.expires_at ? timeText(a.expires_at) : '']))}` : ''}
                <h2>Sub-counties, ranked by buildings exposed</h2>${tbl(['#', 'Sub-county', 'Flooded km²', 'Flooded %', 'Buildings exposed', '90th pct depth (m)'],
                    d.subcounties.map((x, k) => [k + 1, x.name, x.flooded_km2, x.flooded_pct, fmt(x.buildings_exposed), x.p90_depth_m]))}
                <h2>Hotspots</h2>${tbl(['#', 'Place', 'Sub-county', 'Area in deep water (ha)', 'Buildings exposed', 'Depth'],
                    d.hotspots.map((x, k) => [k + 1, x.name, x.subcounty, x.area_ha, fmt(x.buildings_exposed), x.depth_label]))}
                <h2>Facilities at risk</h2>${tbl(['#', 'Name', 'Type', 'Depth', 'Location'],
                    d.facilities.map((x, k) => [k + 1, x.name, String(x.type).replace(/_/g, ' '), x.depth_label, `${x.lat.toFixed(5)}, ${x.lng.toFixed(5)}`]))}
                </body></html>`);
            w.document.close();
            w.focus();
            setTimeout(() => w.print(), 300);
        }

        // =========================================================
        // INITIALISE
        // =========================================================
        try {
            const savedDisplay = localStorage.getItem('floodDssDisplay');
            if (savedDisplay) { displayMode = savedDisplay; const r = document.querySelector(`input[name="display"][value="${savedDisplay}"]`); if (r) r.checked = true; }
        } catch (e) {}
        applyTheme(currentTheme());
        updateRainTotal();
        markPreset();
        renderLegend(effectiveDisplay());
        loadSubcounties();
        loadModelInfo();
        runFloodSimulation();   // show the default scenario straight away (the model server pre-computes it)
        pollAlerts();
        setInterval(pollAlerts, ALERT_POLL_MS);
        if (ME) loadPlaces();
    </script>
</body>
</html>
