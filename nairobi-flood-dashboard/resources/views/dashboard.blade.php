<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
            --bg: #e9eef5;
            --surface: #ffffff;
            --surface-2: #f1f5f9;
            --line: #cbd5e1;
            --line-strong: #64748b;
            --text: #0f172a;
            --text-2: #1e293b;
            --text-3: #475569;
            --accent: #0369a1;
            --accent-ink: #ffffff;
            --accent-soft: #e0f2fe;

            --high: #b91c1c;  --high-soft: #fee2e2;
            --med:  #92400e;  --med-soft:  #fef3c7;
            --low:  #075985;  --low-soft:  #e0f2fe;
            --safe: #166534;  --safe-soft: #dcfce7;
            --none: #334155;  --none-soft: #e2e8f0;

            --shadow: 0 10px 30px -8px rgba(15, 23, 42, .28);
        }
        [data-theme="dark"] {
            --bg: #0b1220;
            --surface: #111b2e;
            --surface-2: #19253b;
            --line: #2d3b55;
            --line-strong: #8da2c0;
            --text: #f8fafc;
            --text-2: #e2e8f0;
            --text-3: #a8b8d0;
            --accent: #38bdf8;
            --accent-ink: #04111f;
            --accent-soft: #0c2a40;

            --high: #fca5a5;  --high-soft: #4a1717;
            --med:  #fcd34d;  --med-soft:  #43300a;
            --low:  #7dd3fc;  --low-soft:  #0c2a40;
            --safe: #86efac;  --safe-soft: #0f3320;
            --none: #cbd5e1;  --none-soft: #1f2c44;

            --shadow: 0 14px 40px -10px rgba(0, 0, 0, .7);
        }
        :root {
            --radius: 14px;
            --gap: 16px;
            --panel-w: 420px;
            --peek: 150px;
            --ease: cubic-bezier(.2, .8, .2, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 16px;
            line-height: 1.45;
            background: var(--bg);
            color: var(--text);
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        button, input, summary { font-family: inherit; font-size: inherit; }
        button { color: inherit; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }

        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .skip {
            position: fixed; left: 12px; top: -60px; z-index: 5000; padding: 12px 16px;
            background: var(--accent); color: var(--accent-ink); font-weight: 700; border-radius: 10px; text-decoration: none;
        }
        .skip:focus { top: 12px; }
        svg.i { width: 20px; height: 20px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* =========================================================
           MAP
        ========================================================= */
        #map { position: fixed; inset: 0; z-index: 0; background: var(--bg); }
        .leaflet-container { font-family: inherit; background: var(--bg); }
        .leaflet-container img { max-width: none !important; max-height: none !important; }
        [data-theme="dark"] .bright-map-labels { filter: brightness(2.2) contrast(1.3) drop-shadow(0 0 3px rgba(0,0,0,.9)); }

        .leaflet-bar { border: 2px solid var(--line-strong) !important; border-radius: 14px !important; overflow: hidden; box-shadow: var(--shadow) !important; }
        .leaflet-bar a, .leaflet-bar a:hover {
            width: 48px; height: 48px; line-height: 46px; font-size: 22px;
            background: var(--surface); color: var(--text); border-bottom: 1px solid var(--line);
        }
        .leaflet-bar a:hover { background: var(--surface-2); }
        .leaflet-control-attribution { background: var(--surface) !important; color: var(--text-3) !important; font-size: 11px; }
        .leaflet-control-attribution a { color: var(--accent) !important; }

        .leaflet-popup-content-wrapper { background: var(--surface); color: var(--text); border: 2px solid var(--line-strong); border-radius: 16px; box-shadow: var(--shadow); }
        .leaflet-popup-tip { background: var(--surface); }
        .leaflet-popup-content { margin: 16px 18px; font-size: 15px; line-height: 1.45; min-width: 240px; }
        .leaflet-container a.leaflet-popup-close-button { width: 40px; height: 40px; font-size: 24px; line-height: 40px; color: var(--text-2); }

        .pop-title { font-size: 17px; font-weight: 700; margin-bottom: 8px; }
        .pop-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 12px 0; }
        .pop-stat { background: var(--surface-2); border-radius: 10px; padding: 10px 12px; }
        .pop-stat b { display: block; font-size: 20px; line-height: 1.2; }
        .pop-stat span { font-size: 13px; color: var(--text-3); }
        .pop-btn {
            width: 100%; min-height: 44px; border: 2px solid var(--accent); background: transparent; color: var(--accent);
            font-weight: 700; border-radius: 10px; cursor: pointer;
        }
        .pop-btn:hover { background: var(--accent-soft); }
        .pop-note { font-size: 14px; color: var(--text-3); }

        .water-polygon { transition: filter .2s; cursor: pointer; }
        .water-polygon:hover { filter: brightness(1.15) drop-shadow(0 0 4px rgba(0,0,0,.4)); }

        .map-pin {
            width: 30px; height: 30px; border-radius: 50%; border: 3px solid #fff;
            display: grid; place-items: center; font-weight: 700; font-size: 15px; color: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,.5);
        }
        .map-pin.origin { background: #15803d; }
        .map-pin.dest { background: #b91c1c; }

        /* =========================================================
           LEGEND
        ========================================================= */
        .legend {
            position: fixed; z-index: 900; left: calc(var(--panel-w) + var(--gap) * 2); bottom: var(--gap);
            background: var(--surface); border: 2px solid var(--line); border-radius: var(--radius);
            padding: 12px 16px; box-shadow: var(--shadow); font-size: 14px;
            transition: left .4s var(--ease);
        }
        body.panel-closed .legend { left: var(--gap); }
        .legend h2 { margin: 0 0 8px; font-size: 14px; font-weight: 700; }
        .legend-row { display: flex; align-items: center; gap: 10px; padding: 2px 0; color: var(--text-2); }
        .sw { width: 22px; height: 14px; border-radius: 4px; flex: none; border: 2px solid rgba(0,0,0,.25); }
        .sw.high { background: #dc2626; } .sw.med { background: #f59e0b; } .sw.low { background: #0ea5e9; }
        .ln { width: 22px; height: 0; flex: none; border-top: 5px solid #16a34a; border-radius: 3px; }
        .ln.unsafe { border-top: 4px dashed #8b5cf6; }

        /* =========================================================
           PANEL
        ========================================================= */
        .panel {
            position: fixed; top: var(--gap); left: var(--gap); bottom: var(--gap); width: var(--panel-w); z-index: 1000;
            display: flex; flex-direction: column;
            background: var(--surface); border: 2px solid var(--line); border-radius: 20px; box-shadow: var(--shadow);
            transition: transform .4s var(--ease), opacity .3s;
        }
        body.panel-closed .panel { transform: translateX(calc(-100% - 40px)); opacity: 0; pointer-events: none; visibility: hidden; }

        .panel-head { display: flex; align-items: center; gap: 12px; padding: 14px 14px 12px 18px; }
        .head-text { flex: 1; min-width: 0; }
        .head-text h1 { margin: 0; font-size: 1.15rem; line-height: 1.2; }
        .head-text p { margin: 2px 0 0; font-size: 14px; color: var(--text-3); }
        .icon-btn {
            flex: none; width: 44px; height: 44px; display: grid; place-items: center;
            background: var(--surface-2); border: 2px solid var(--line); border-radius: 12px; cursor: pointer; color: var(--text);
        }
        .icon-btn:hover { border-color: var(--line-strong); }

        .reopen {
            position: fixed; top: var(--gap); left: var(--gap); z-index: 1000; min-height: 48px; padding: 0 18px;
            display: none; align-items: center; gap: 10px;
            background: var(--surface); border: 2px solid var(--line-strong); border-radius: 14px;
            font-weight: 700; cursor: pointer; box-shadow: var(--shadow);
        }
        body.panel-closed .reopen { display: flex; }

        /* Summary strip: the answer to "what is happening right now?" */
        .summary { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 0 14px 14px; }
        .tile { --c: var(--none); --cs: var(--none-soft); background: var(--cs); color: var(--c); border-radius: 12px; padding: 10px 12px; min-width: 0; }
        .tile[data-tone="low"]  { --c: var(--low);  --cs: var(--low-soft); }
        .tile[data-tone="med"]  { --c: var(--med);  --cs: var(--med-soft); }
        .tile[data-tone="high"] { --c: var(--high); --cs: var(--high-soft); }
        .tile[data-tone="safe"] { --c: var(--safe); --cs: var(--safe-soft); }
        .tile-label { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .tile-label .i { width: 16px; height: 16px; }
        .tile-value { font-size: 1.3rem; line-height: 1.2; font-weight: 700; margin-top: 2px; }
        .tile-sub { font-size: 13px; color: var(--text-2); margin-top: 1px; }
        .advice { grid-column: 1 / -1; margin: 0; font-size: 14px; font-weight: 700; color: var(--med); background: var(--med-soft); border-radius: 10px; padding: 8px 12px; }
        .advice[hidden] { display: none; }
        .weather-line { grid-column: 1 / -1; margin: 0; font-size: 14px; color: var(--text-3); }

        /* Tabs */
        .tabs { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 2px solid var(--line); border-bottom: 2px solid var(--line); }
        .tab {
            min-height: 52px; padding: 0 6px; background: transparent; border: 0; border-bottom: 4px solid transparent;
            display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 700; color: var(--text-3); cursor: pointer;
        }
        .tab:hover { background: var(--surface-2); color: var(--text); }
        .tab[aria-selected="true"] { color: var(--accent); border-bottom-color: var(--accent); background: var(--accent-soft); }

        .panel-body { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: 18px; scrollbar-width: thin; }
        .tab-panel[hidden] { display: none; }

        h2.sec-title { margin: 0 0 4px; font-size: 1.1rem; }
        .help { margin: 0 0 14px; font-size: 15px; color: var(--text-3); }
        .section + .section { margin-top: 24px; padding-top: 20px; border-top: 2px solid var(--line); }

        /* =========================================================
           BUTTONS / FORM
        ========================================================= */
        .btn {
            width: 100%; min-height: 50px; padding: 0 16px; border: 2px solid transparent; border-radius: 12px;
            font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
            transition: transform .1s, background .15s;
        }
        .btn:active { transform: scale(.985); }
        .btn-primary { background: var(--accent); color: var(--accent-ink); }
        .btn-primary:hover { filter: brightness(1.08); }
        .btn-safe { background: #15803d; color: #fff; }
        [data-theme="dark"] .btn-safe { background: #4ade80; color: #04170f; }
        .btn-outline { background: var(--surface); border-color: var(--line-strong); color: var(--text); }
        .btn-outline:hover { background: var(--surface-2); }
        .btn-row { display: flex; gap: 10px; }
        .btn-row .btn-outline { width: 36%; }
        .btn.is-loading { pointer-events: none; opacity: .85; }
        .btn.is-loading::after, .icon-btn.is-loading::after {
            content: ''; width: 16px; height: 16px; border-radius: 50%; border: 3px solid currentColor; border-right-color: transparent; animation: spin .7s linear infinite;
        }
        .icon-btn.is-loading > * { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .control-group + .control-group { margin-top: 18px; }
        .control-group label { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
        .control-group strong { font-size: 1.1rem; }

        input[type="range"] {
            -webkit-appearance: none; appearance: none; width: 100%; height: 10px; border-radius: 10px; cursor: pointer;
            background: linear-gradient(to right, var(--accent) var(--p, 50%), var(--line) var(--p, 50%));
        }
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; width: 30px; height: 30px; border-radius: 50%; background: var(--surface); border: 4px solid var(--accent); box-shadow: 0 2px 8px rgba(0,0,0,.35); }
        input[type="range"]::-moz-range-thumb { width: 22px; height: 22px; border-radius: 50%; background: var(--surface); border: 4px solid var(--accent); }

        .status { margin: 14px 0 0; font-size: 15px; color: var(--text-2); min-height: 1.4em; }

        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        .route-grid { display: grid; grid-template-columns: 1fr 48px; gap: 6px 10px; align-items: end; }
        .input-wrapper { position: relative; }
        .input-wrapper .badge-ab {
            position: absolute; left: 10px; bottom: 11px; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center;
            font-weight: 700; font-size: 14px; color: #fff; z-index: 2; pointer-events: none;
        }
        .badge-ab.a { background: #15803d; } .badge-ab.b { background: #b91c1c; }
        .input-wrapper input[type="text"] {
            width: 100%; min-height: 48px; padding: 0 14px 0 46px; background: var(--surface); color: var(--text);
            border: 2px solid var(--line-strong); border-radius: 12px;
        }
        .input-wrapper input::placeholder { color: var(--text-3); opacity: 1; }
        .input-wrapper input:focus { outline: 3px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
        .suggestions {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 2000; display: none; max-height: 220px; overflow-y: auto;
            background: var(--surface); border: 2px solid var(--line-strong); border-radius: 12px; box-shadow: var(--shadow);
        }
        .suggestion-item { padding: 12px 14px; min-height: 48px; border-bottom: 1px solid var(--line); cursor: pointer; }
        .suggestion-item:last-child { border-bottom: 0; }
        .suggestion-item:hover, .suggestion-item:focus { background: var(--accent-soft); }
        .link-btn {
            display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 4px; margin: 6px 0 0;
            background: none; border: 0; color: var(--accent); font-weight: 700; cursor: pointer; text-decoration: underline; text-underline-offset: 3px;
        }
        .swap { grid-column: 2; grid-row: 1 / span 2; align-self: center; }

        .toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 18px 0; padding: 12px 14px; background: var(--surface-2); border-radius: 12px; cursor: pointer; }
        .toggle-row b { display: block; }
        .toggle-row small { color: var(--text-3); font-size: 14px; }
        .switch { position: relative; width: 56px; height: 32px; flex: none; }
        .switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; z-index: 2; }
        .switch .track { position: absolute; inset: 0; border-radius: 999px; background: var(--line-strong); transition: background .2s; }
        .switch .track::after { content: ''; position: absolute; top: 4px; left: 4px; width: 24px; height: 24px; border-radius: 50%; background: #fff; transition: transform .2s var(--ease); }
        .switch input:checked + .track { background: #15803d; }
        .switch input:checked + .track::after { transform: translateX(24px); }
        .switch input:focus-visible + .track { outline: 3px solid var(--accent); outline-offset: 2px; }

        .result { --c: var(--none); --cs: var(--none-soft); margin-top: 16px; padding: 14px; border-radius: 12px; background: var(--cs); color: var(--text); border: 2px solid var(--c); display: flex; gap: 12px; }
        .result[data-tone="safe"] { --c: var(--safe); --cs: var(--safe-soft); }
        .result[data-tone="med"]  { --c: var(--med);  --cs: var(--med-soft); }
        .result[data-tone="high"] { --c: var(--high); --cs: var(--high-soft); }
        .result .i { color: var(--c); margin-top: 2px; }
        .result b { display: block; font-size: 1.05rem; color: var(--c); }
        .result p { margin: 2px 0 0; font-size: 15px; }

        /* =========================================================
           HOTSPOTS
        ========================================================= */
        #blackspot-list { display: flex; flex-direction: column; gap: 10px; }
        .hotspot {
            --c: var(--low); --cs: var(--low-soft);
            width: 100%; text-align: left; display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center;
            padding: 12px 14px; min-height: 64px; background: var(--surface); border: 2px solid var(--line); border-left: 8px solid var(--c); border-radius: 12px; cursor: pointer;
        }
        .hotspot:hover { background: var(--surface-2); }
        .hotspot.tier-high { --c: #dc2626; --cs: var(--high-soft); }
        .hotspot.tier-med  { --c: #f59e0b; --cs: var(--med-soft); }
        .hotspot.tier-low  { --c: #0ea5e9; --cs: var(--low-soft); }
        .hs-name { font-weight: 700; font-size: 1.05rem; }
        .hs-cat { font-size: 14px; color: var(--text-3); }
        .hs-depth { grid-row: 1 / span 2; grid-column: 2; text-align: right; font-size: 1.3rem; font-weight: 700; line-height: 1.1; }
        .hs-depth small { display: block; font-size: 13px; font-weight: 400; color: var(--text-3); }
        .chip { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 700; padding: 2px 10px; border-radius: 999px; background: var(--cs); color: var(--tc); width: fit-content; }
        .tier-high { --tc: var(--high); } .tier-med { --tc: var(--med); } .tier-low { --tc: var(--low); }
        .empty { padding: 14px; background: var(--surface-2); border-radius: 12px; color: var(--text-2); font-size: 15px; }

        /* SHAP */
        .shap-subtitle { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; font-size: 15px; }
        #shap-target-name { font-weight: 700; }
        #shap-pred-prob { color: var(--text-3); }
        .scope-badge { display: inline-block; font-size: 14px; font-weight: 700; padding: 2px 12px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); margin-bottom: 10px; }
        .shap-row + .shap-row { margin-top: 14px; }
        .shap-label-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 15px; }
        .shap-bar-track { height: 12px; background: var(--line); border-radius: 12px; overflow: hidden; }
        .shap-bar-fill { height: 100%; border-radius: 12px; transition: width .4s var(--ease); }
        .shap-legend { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 18px; font-size: 14px; color: var(--text-2); }
        .shap-legend i { display: inline-block; width: 14px; height: 14px; border-radius: 4px; margin-right: 6px; vertical-align: -2px; }

        /* =========================================================
           ACCOUNT
        ========================================================= */
        .account { position: fixed; top: var(--gap); right: var(--gap); z-index: 1000; }
        .account-pill { display: flex; align-items: center; gap: 10px; padding: 6px 8px 6px 6px; background: var(--surface); border: 2px solid var(--line-strong); border-radius: 999px; box-shadow: var(--shadow); }
        .avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; display: grid; place-items: center; font-weight: 700; background: var(--accent-soft); color: var(--accent); border: 2px solid var(--accent); flex: none; }
        .account-name { font-size: 14px; font-weight: 700; line-height: 1.2; }
        .account-role { font-size: 13px; color: var(--text-3); }
        .pill-link, .pill-btn { min-height: 40px; padding: 0 14px; border-radius: 999px; font-weight: 700; font-size: 14px; border: 0; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap; }
        .pill-link { background: var(--accent-soft); color: var(--accent); }
        .pill-btn { background: transparent; color: var(--text-2); text-decoration: underline; text-underline-offset: 3px; }
        .signin { display: inline-flex; align-items: center; gap: 10px; min-height: 48px; padding: 0 18px 0 10px; background: var(--surface); color: var(--text); border: 2px solid var(--line-strong); border-radius: 999px; text-decoration: none; font-weight: 700; box-shadow: var(--shadow); }
        .signin svg { width: 26px; height: 26px; background: #fff; border-radius: 50%; padding: 3px; }

        /* =========================================================
           MOBILE: bottom sheet
        ========================================================= */
        @media (max-width: 760px) {
            :root { --gap: 10px; }
            .panel { top: auto; left: var(--gap); right: var(--gap); width: auto; height: 72vh; bottom: calc(var(--gap) + env(safe-area-inset-bottom, 0px)); }
            body.panel-closed .panel { transform: translateY(calc(100% - var(--peek))); opacity: 1; pointer-events: auto; visibility: visible; }
            body.panel-closed .reopen { display: none; }
            .head-text p { display: none; }
            .legend { left: var(--gap); top: var(--gap); bottom: auto; padding: 8px 10px; font-size: 13px; max-width: 46vw; }
            body.panel-closed .legend { left: var(--gap); }
            .legend h2 { display: none; }
            .account-name, .account-role { display: none; }
            .leaflet-bottom.leaflet-right { bottom: 74vh; }
            .tab span { font-size: 14px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
        @media (forced-colors: active) { .tile, .chip, .result { border: 2px solid CanvasText; } }
    </style>
</head>
<body>
    <a class="skip" href="#sidebar">Skip to flood tools</a>

    <!-- Icon sprite -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-rain" viewBox="0 0 24 24"><path d="M7 16a4 4 0 0 1-.5-7.97A5.5 5.5 0 0 1 17 9a3.5 3.5 0 0 1 0 7"/><path d="M8 19l-1 2M12 19l-1 2M16 19l-1 2"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3z"/><path d="M12 10v4M12 17.5v.01"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/></symbol>
        <symbol id="i-route" viewBox="0 0 24 24"><circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7"/></symbol>
        <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></symbol>
        <symbol id="i-wave" viewBox="0 0 24 24"><path d="M2 9c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5M2 16c2.5-2.5 5-2.5 7.5 0s5 2.5 7.5 0 3.5-1.5 5-.5"/></symbol>
        <symbol id="i-chev-left" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
        <symbol id="i-chev-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
        <symbol id="i-locate" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="8"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></symbol>
        <symbol id="i-swap" viewBox="0 0 24 24"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></symbol>
        <symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></symbol>
        <symbol id="i-play" viewBox="0 0 24 24"><path d="M7 4l13 8-13 8V4z"/></symbol>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/></symbol>
    </svg>

    <div id="map" role="region" aria-label="Flood map of Nairobi"></div>

    <!-- Account -->
    <div class="account">
        @auth
            <div class="account-pill">
                @if(Auth::user()->avatar)
                    <img src="{{ Auth::user()->avatar }}" class="avatar" alt="">
                @else
                    <div class="avatar" aria-hidden="true">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                @endif
                <div>
                    <div class="account-name">{{ Auth::user()->name }}</div>
                    <div class="account-role">{{ Auth::user()->role === 'admin' ? 'System Admin' : 'Signed in' }}</div>
                </div>
                @if(Auth::user()->role === 'admin')
                    <a href="{{ route('admin.index') }}" class="pill-link">Admin console</a>
                @endif
                <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="pill-btn">Sign out</button>
                </form>
            </div>
        @else
            <a href="{{ route('login.google') }}" class="signin">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.11-6.72-4.96H1.29v3.13C3.26 21.3 7.31 24 12 24z"/>
                    <path fill="#FBBC05" d="M5.28 14.24c-.25-.72-.38-1.49-.38-2.24s.13-1.52.38-2.24V6.63H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.37l3.99-3.13z"/>
                    <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.63l3.99 3.13c.95-2.85 3.6-4.96 6.72-4.96z"/>
                </svg>
                <span>Sign in with Google</span>
            </a>
        @endauth
    </div>

    <!-- Map legend -->
    <div class="legend" aria-label="Map legend">
        <h2>Map key</h2>
        <div class="legend-row"><span class="sw high"></span> High flood risk</div>
        <div class="legend-row"><span class="sw med"></span> Medium flood risk</div>
        <div class="legend-row"><span class="sw low"></span> Lower flood risk</div>
        <div class="legend-row"><span class="ln"></span> Safe route</div>
        <div class="legend-row"><span class="ln unsafe"></span> Direct route through flooding</div>
    </div>

    <button class="reopen" onclick="togglePanel()" aria-label="Open flood tools panel">
        <svg class="i"><use href="#i-wave"/></svg> Flood tools
    </button>

    <!-- Main panel -->
    <aside class="panel" id="sidebar" aria-label="Flood tools" tabindex="-1">
        <header class="panel-head">
            <svg class="i" style="width:30px;height:30px;color:var(--accent)" aria-hidden="true"><use href="#i-wave"/></svg>
            <div class="head-text">
                <h1>Nairobi Flood DSS</h1>
                <p>Flood risk and safe routes</p>
            </div>
            <button class="icon-btn" id="theme-btn" onclick="toggleTheme()" aria-label="Switch to dark theme">
                <svg class="i" id="theme-icon"><use href="#i-moon"/></svg>
            </button>
            <button class="icon-btn" id="collapse-btn" onclick="togglePanel()" aria-label="Hide panel" aria-expanded="true">
                <svg class="i" id="collapse-icon"><use href="#i-chev-left"/></svg>
            </button>
        </header>

        <!-- Situation summary: always visible -->
        <div class="summary" role="status" aria-live="polite">
            <div class="tile" id="tile-rain" data-tone="none">
                <div class="tile-label"><svg class="i"><use href="#i-rain"/></svg>Rain now</div>
                <div class="tile-value" id="live-rain-level">Checking…</div>
                <div class="tile-sub"><span id="live-rain">-- mm</span></div>
            </div>
            <div class="tile" id="tile-risk" data-tone="none">
                <div class="tile-label"><svg class="i"><use href="#i-alert"/></svg>Flood risk</div>
                <div class="tile-value" id="risk-headline">Not run yet</div>
                <div class="tile-sub" id="risk-sub">Run a simulation</div>
            </div>
            <p class="advice" id="rain-advice" hidden></p>
            <p class="weather-line">Temperature <b id="live-temp">-- °C</b> · Humidity <b id="live-hum">-- %</b> · Live data</p>
        </div>

        <div class="tabs" role="tablist" aria-label="Flood tools">
            <button class="tab" role="tab" id="tab-risk" aria-controls="panel-risk" aria-selected="true" onclick="showTab('risk')">
                <svg class="i"><use href="#i-alert"/></svg><span>Flood risk</span>
            </button>
            <button class="tab" role="tab" id="tab-route" aria-controls="panel-route" aria-selected="false" tabindex="-1" onclick="showTab('route')">
                <svg class="i"><use href="#i-route"/></svg><span>Safe route</span>
            </button>
            <button class="tab" role="tab" id="tab-analysis" aria-controls="panel-analysis" aria-selected="false" tabindex="-1" onclick="showTab('analysis')">
                <svg class="i"><use href="#i-chart"/></svg><span>Why</span>
            </button>
        </div>

        <div class="panel-body">

            <!-- TAB 1: FLOOD RISK -->
            <div class="tab-panel" id="panel-risk" role="tabpanel" aria-labelledby="tab-risk">
                <div class="section">
                    <h2 class="sec-title">What if it rains…</h2>
                    <p class="help">Set the rainfall and storm length to see which areas could flood.</p>

                    <div class="control-group">
                        <label for="intensity-slider"><span>Rainfall intensity</span><strong id="intensity-val">55 mm/hr</strong></label>
                        <input type="range" id="intensity-slider" min="5" max="120" value="55" oninput="onSlide(this, 'intensity-val', ' mm/hr')">
                    </div>
                    <div class="control-group">
                        <label for="duration-slider"><span>Storm duration</span><strong id="duration-val">3 hrs</strong></label>
                        <input type="range" id="duration-slider" min="1" max="24" value="3" oninput="onSlide(this, 'duration-val', ' hrs')">
                    </div>

                    <div class="btn-row" style="margin-top:20px;flex-direction:column">
                        <button class="btn btn-primary" id="sim-btn" onclick="runFloodSimulation()">Run simulation</button>
                        <button class="btn btn-outline" id="live-btn" onclick="applyLiveWeatherToModel()" style="width:100%">
                            <svg class="i"><use href="#i-rain"/></svg> Use current weather
                        </button>
                    </div>

                    <p class="status" id="sim-status" role="status" aria-live="polite">Set the sliders, then run the simulation.</p>
                </div>

                <div class="section">
                    <h2 class="sec-title">Areas that may flood <span id="blackspot-count" style="font-weight:400;color:var(--text-3);font-size:15px">0 flagged</span></h2>
                    <p class="help">Tap an area to see it on the map.</p>
                    <div id="blackspot-list">
                        <div class="empty">No results yet. Run a simulation to see which areas are flagged.</div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: SAFE ROUTE -->
            <div class="tab-panel" id="panel-route" role="tabpanel" aria-labelledby="tab-route" hidden>
                <h2 class="sec-title">Plan a safe route</h2>
                <p class="help">Type a place, tap the map, or use your location.</p>

                <div class="route-grid">
                    <div class="input-wrapper">
                        <label class="field-label" for="origin-input">From</label>
                        <span class="badge-ab a" aria-hidden="true">A</span>
                        <input type="text" id="origin-input" placeholder="e.g. Westlands" autocomplete="off">
                        <div class="suggestions" id="origin-suggestions"></div>
                    </div>
                    <div class="input-wrapper">
                        <label class="field-label" for="dest-input">To</label>
                        <span class="badge-ab b" aria-hidden="true">B</span>
                        <input type="text" id="dest-input" placeholder="e.g. South B" autocomplete="off">
                        <div class="suggestions" id="dest-suggestions"></div>
                    </div>
                    <button class="icon-btn swap" onclick="swapPoints()" aria-label="Swap start and destination"><svg class="i"><use href="#i-swap"/></svg></button>
                </div>
                <button class="link-btn" id="locate-btn" onclick="useMyLocation()"><svg class="i"><use href="#i-locate"/></svg> Use my location as start</button>

                <label class="toggle-row">
                    <span><b>Avoid flooded areas</b><small>Plans a detour around flagged zones</small></span>
                    <span class="switch">
                        <input type="checkbox" id="avoid-flood-toggle" checked onchange="if(startCoords && endCoords) fetchRoute();">
                        <span class="track"></span>
                    </span>
                </label>

                <div class="btn-row">
                    <button class="btn btn-safe" id="route-btn" onclick="handleFindRouteClick()">Find safe route</button>
                    <button class="btn btn-outline" onclick="clearRouting()">Clear</button>
                </div>

                <div id="route-status" role="status" aria-live="polite">
                    <p class="status">Set a start and destination to see your route.</p>
                </div>
            </div>

            <!-- TAB 3: WHY (SHAP) -->
            <div class="tab-panel" id="panel-analysis" role="tabpanel" aria-labelledby="tab-analysis" hidden>
                <h2 class="sec-title">Why is it flagged?</h2>
                <p class="help">Each bar shows how much a factor raised or lowered the flood chance.</p>
                <span class="scope-badge" id="shap-scope-badge">Whole city</span>
                <div class="shap-subtitle">
                    <span id="shap-target-name">Average across all areas</span>
                    <span id="shap-pred-prob">Chance of flooding: --</span>
                </div>
                <div id="shap-bars-container">
                    <div class="empty">Run a simulation, then tap a flagged area to see what drives its risk.</div>
                </div>
                <div class="shap-legend">
                    <span><i style="background:#dc2626"></i>Raises flood risk</span>
                    <span><i style="background:#16a34a"></i>Lowers flood risk</span>
                </div>
            </div>
        </div>
    </aside>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // =========================================================
        // CONFIG
        // =========================================================
        const TILE_KEY = '?key=cb1_4giv_1_8d1b08af50b833befe3b1884';
        const TIER_COLORS = { high: '#dc2626', med: '#f59e0b', low: '#0ea5e9' };

        const map = L.map('map', { zoomControl: false }).setView([-1.2880, 36.8320], 12);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        map.createPane('labels');
        map.getPane('labels').style.zIndex = 650;
        map.getPane('labels').style.pointerEvents = 'none';

        let baseLayer = null, labelLayer = null;
        function setBasemap(theme) {
            if (baseLayer) map.removeLayer(baseLayer);
            if (labelLayer) map.removeLayer(labelLayer);
            const style = theme === 'dark' ? 'dark' : 'light';
            const opts = { attribution: '&copy; OpenStreetMap contributors, &copy; CARTO', subdomains: 'abcd', maxZoom: 19 };
            baseLayer = L.tileLayer(`https://{s}.basemaps.cartocdn.com/${style}_nolabels/{z}/{x}/{y}@2x.png${TILE_KEY}`, opts).addTo(map);
            labelLayer = L.tileLayer(`https://{s}.basemaps.cartocdn.com/${style}_only_labels/{z}/{x}/{y}@2x.png${TILE_KEY}`,
                Object.assign({ pane: 'labels', className: 'bright-map-labels' }, opts)).addTo(map);
        }

        let floodLayerGroup = L.layerGroup().addTo(map);
        let startMarker = null, endMarker = null, routeLayer = null;
        let startCoords = null, endCoords = null;
        let currentLiveRain = 0;
        let simHasRun = false;

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
            setBasemap(t);
        }
        function toggleTheme() { applyTheme(currentTheme() === 'dark' ? 'light' : 'dark'); }

        function togglePanel() {
            const closed = document.body.classList.toggle('panel-closed');
            const btn = document.getElementById('collapse-btn');
            btn.setAttribute('aria-expanded', String(!closed));
            btn.setAttribute('aria-label', closed ? 'Show panel' : 'Hide panel');
            document.getElementById('collapse-icon').style.transform = closed && window.innerWidth <= 760 ? 'rotate(90deg)' : '';
        }

        const TAB_NAMES = ['risk', 'route', 'analysis'];
        function showTab(name) {
            TAB_NAMES.forEach(n => {
                const on = n === name;
                const t = document.getElementById('tab-' + n);
                t.setAttribute('aria-selected', String(on));
                t.tabIndex = on ? 0 : -1;
                document.getElementById('panel-' + n).hidden = !on;
            });
            document.querySelector('.panel-body').scrollTop = 0;
        }
        document.querySelector('.tabs').addEventListener('keydown', (e) => {
            const i = TAB_NAMES.findIndex(n => document.getElementById('tab-' + n).getAttribute('aria-selected') === 'true');
            let next = null;
            if (e.key === 'ArrowRight') next = (i + 1) % 3;
            if (e.key === 'ArrowLeft') next = (i + 2) % 3;
            if (next !== null) { e.preventDefault(); showTab(TAB_NAMES[next]); document.getElementById('tab-' + TAB_NAMES[next]).focus(); }
        });

        function syncSlider(el) { el.style.setProperty('--p', ((el.value - el.min) / (el.max - el.min)) * 100 + '%'); }
        function onSlide(el, valueId, unit) {
            document.getElementById(valueId).innerText = el.value + unit;
            syncSlider(el);
        }
        function setSliders(i, d) {
            const a = document.getElementById('intensity-slider'), b = document.getElementById('duration-slider');
            a.value = i; b.value = d;
            document.getElementById('intensity-val').innerText = i + ' mm/hr';
            document.getElementById('duration-val').innerText = d + ' hrs';
            syncSlider(a); syncSlider(b);
        }
        syncSlider(document.getElementById('intensity-slider'));
        syncSlider(document.getElementById('duration-slider'));

        function setBusy(id, busy) { const b = document.getElementById(id); if (b) b.classList.toggle('is-loading', busy); }
        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        function tierOf(prob) { return prob >= 0.70 ? 'high' : prob >= 0.50 ? 'med' : 'low'; }
        const TIER_LABEL = { high: 'High risk', med: 'Medium risk', low: 'Lower risk' };
        const TIER_ICON = { high: '▲', med: '◆', low: '●' };

        // =========================================================
        // SUMMARY STRIP
        // =========================================================
        function updateRainSummary(mm) {
            let level, tone, advice = '';
            if (mm <= 0)        { level = 'No rain';        tone = 'none'; }
            else if (mm < 2.5)  { level = 'Light rain';     tone = 'low'; }
            else if (mm < 7.6)  { level = 'Moderate rain';  tone = 'low'; }
            else if (mm < 50)   { level = 'Heavy rain';     tone = 'med';  advice = 'Avoid low-lying roads. Never drive through floodwater.'; }
            else                { level = 'Violent rain';   tone = 'high'; advice = 'Avoid low-lying roads. Never drive through floodwater.'; }
            document.getElementById('tile-rain').dataset.tone = tone;
            document.getElementById('live-rain-level').innerText = level;
            const adv = document.getElementById('rain-advice');
            adv.innerText = advice; adv.hidden = !advice;
        }
        function updateRiskSummary(features) {
            const tile = document.getElementById('tile-risk');
            const head = document.getElementById('risk-headline');
            const sub = document.getElementById('risk-sub');
            if (!features) { tile.dataset.tone = 'none'; head.innerText = 'Not run yet'; sub.innerText = 'Run a simulation'; return; }
            if (features.length === 0) { tile.dataset.tone = 'safe'; head.innerText = 'None flagged'; sub.innerText = 'For this storm'; return; }
            const top = tierOf(Math.max(...features.map(f => f.properties.flood_probability)));
            tile.dataset.tone = top;
            head.innerText = `${features.length} ${features.length === 1 ? 'area' : 'areas'}`;
            sub.innerText = `Highest: ${TIER_LABEL[top].toLowerCase()}`;
        }

        // =========================================================
        // 1. SHAP
        // =========================================================
        function renderShapBars(contributions, targetTitle, predProb, baseVal, isLocal = false) {
            const container = document.getElementById('shap-bars-container');
            document.getElementById('shap-scope-badge').innerText = isLocal ? 'Selected area' : 'Whole city';
            document.getElementById('shap-target-name').innerText = targetTitle;
            document.getElementById('shap-pred-prob').innerText = `Usual chance ${Math.round((baseVal || 0.08) * 100)}% → now ${Math.round((predProb || 0) * 100)}%`;

            container.innerHTML = '';
            const entries = Object.entries(contributions || {}).sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]));
            entries.forEach(([feature, val]) => {
                const up = val >= 0;
                const color = up ? '#dc2626' : '#16a34a';
                const width = Math.min(100, Math.max(6, Math.abs(val) * 260));
                const row = document.createElement('div');
                row.className = 'shap-row';
                row.innerHTML = `
                    <div class="shap-label-row">
                        <span>${escapeHtml(feature)}</span>
                        <b style="color:${up ? 'var(--high)' : 'var(--safe)'}">${up ? '▲ raises' : '▼ lowers'} ${up ? '+' : ''}${val.toFixed(3)}</b>
                    </div>
                    <div class="shap-bar-track"><div class="shap-bar-fill" style="width:${width}%; background:${color};"></div></div>`;
                container.appendChild(row);
            });
        }

        // =========================================================
        // 2. LIVE WEATHER
        // =========================================================
        async function fetchLiveWeather() {
            try {
                const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=-1.2921&longitude=36.8219&current=temperature_2m,relative_humidity_2m,precipitation,rain');
                const data = await res.json();
                if (data.current) {
                    currentLiveRain = data.current.precipitation || 0;
                    document.getElementById('live-rain').innerText = `${currentLiveRain} mm`;
                    document.getElementById('live-temp').innerText = `${data.current.temperature_2m} °C`;
                    document.getElementById('live-hum').innerText = `${data.current.relative_humidity_2m} %`;
                    updateRainSummary(currentLiveRain);
                }
            } catch (e) {
                document.getElementById('live-rain-level').innerText = 'Unavailable';
                document.getElementById('live-rain').innerText = 'Check your connection';
            }
        }
        fetchLiveWeather();

        function applyLiveWeatherToModel() {
            setSliders(Math.max(15, Math.round(currentLiveRain)), 2);
            runFloodSimulation();
        }

        // =========================================================
        // 3. FLOOD SIMULATION
        // =========================================================
        function mapPad() {
            const desktop = window.innerWidth > 760;
            const panelOpen = !document.body.classList.contains('panel-closed');
            return {
                paddingTopLeft: [desktop && panelOpen ? 470 : 40, 70],
                paddingBottomRight: [40, desktop ? 70 : (panelOpen ? window.innerHeight * 0.74 : 170)]
            };
        }

        function runFloodSimulation() {
            const intensity = document.getElementById('intensity-slider').value;
            const duration = document.getElementById('duration-slider').value;
            const statusEl = document.getElementById('sim-status');
            const listEl = document.getElementById('blackspot-list');
            const countEl = document.getElementById('blackspot-count');

            statusEl.innerText = `Running simulation for ${intensity} mm/hr over ${duration} hrs…`;
            setBusy('sim-btn', true);
            setBusy('live-btn', true);

            fetch(`http://127.0.0.1:8001/predict?intensity=${intensity}&duration=${duration}`)
                .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                .then(data => {
                    console.log("FastAPI Response:", data);
                    if (floodLayerGroup) floodLayerGroup.clearLayers();
                    listEl.innerHTML = '';

                    if (data.error) { statusEl.innerText = data.error; return; }
                    simHasRun = true;

                    if (data.global_shap) {
                        renderShapBars(data.global_shap.contributions, 'Average across all areas',
                            data.global_shap.mean_prediction, data.global_shap.base_value, false);
                    }

                    const features = data.features || [];
                    countEl.innerText = `${features.length} flagged`;
                    updateRiskSummary(features);

                    if (features.length === 0) {
                        statusEl.innerText = `No flooding predicted at ${intensity} mm/hr for ${duration} hrs.`;
                        listEl.innerHTML = `<div class="empty">No areas flagged. Try a stronger storm to test more extreme conditions.</div>`;
                        return;
                    }

                    const geoLayer = L.geoJSON(data, {
                        style: function(feature) {
                            const color = TIER_COLORS[tierOf(feature.properties.flood_probability)];
                            return { stroke: true, color: '#ffffff', weight: 2.5, fillColor: color, fillOpacity: 0.6, lineJoin: 'round', className: 'water-polygon' };
                        },
                        onEachFeature: function(feature, layer) {
                            const p = feature.properties;
                            const tier = tierOf(p.flood_probability);
                            layer.bindPopup(`
                                <div class="pop">
                                    <div class="pop-title">${escapeHtml(p.name)}</div>
                                    <span class="chip tier-${tier}" style="--cs:var(--${tier === 'high' ? 'high' : tier === 'med' ? 'med' : 'low'}-soft)">${TIER_ICON[tier]} ${TIER_LABEL[tier]}</span>
                                    <div class="pop-stats">
                                        <div class="pop-stat"><b>${p.predicted_depth_m} m</b><span>Expected depth</span></div>
                                        <div class="pop-stat"><b>${Math.round(p.flood_probability * 100)}%</b><span>Chance of flooding</span></div>
                                    </div>
                                    <button class="pop-btn" onclick="showTab('analysis'); if (document.body.classList.contains('panel-closed')) togglePanel();">Why is this flagged?</button>
                                </div>`);
                            layer.on('click', () => renderShapBars(p.shap_values, p.name, p.flood_probability, p.base_value, true));
                        }
                    });

                    floodLayerGroup.addLayer(geoLayer);
                    geoLayer.bringToFront();
                    if (geoLayer.getBounds().isValid()) map.fitBounds(geoLayer.getBounds(), Object.assign({ maxZoom: 14 }, mapPad()));

                    features
                        .sort((a, b) => b.properties.flood_probability - a.properties.flood_probability)
                        .forEach((f) => {
                            const p = f.properties;
                            const tier = tierOf(p.flood_probability);
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = `hotspot tier-${tier}`;
                            item.innerHTML = `
                                <span class="hs-name">${escapeHtml(p.name)}</span>
                                <span class="hs-depth">${p.predicted_depth_m} m<small>expected depth</small></span>
                                <span class="chip" style="--cs:var(--${tier === 'high' ? 'high' : tier === 'med' ? 'med' : 'low'}-soft)">${TIER_ICON[tier]} ${TIER_LABEL[tier]} · ${Math.round(p.flood_probability * 100)}%</span>`;
                            item.onclick = () => {
                                map.flyTo([p.center_lat, p.center_lng], 14);
                                renderShapBars(p.shap_values, p.name, p.flood_probability, p.base_value, true);
                            };
                            listEl.appendChild(item);
                        });

                    statusEl.innerText = `Showing ${features.length} ${features.length === 1 ? 'area' : 'areas'} that may flood at ${intensity} mm/hr for ${duration} hrs.`;
                    if (startCoords && endCoords) fetchRoute();
                })
                .catch(err => {
                    console.error("Simulation error:", err);
                    statusEl.innerText = "Couldn't reach the flood model. Check your connection and try again.";
                })
                .finally(() => { setBusy('sim-btn', false); setBusy('live-btn', false); });
        }

        // =========================================================
        // 4. ROUTING
        // =========================================================
        function pinIcon(kind, letter) {
            return L.divIcon({ className: '', html: `<div class="map-pin ${kind}">${letter}</div>`, iconSize: [30, 30], iconAnchor: [15, 15] });
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

        map.on('click', function(e) {
            if (!startCoords) { setOrigin(e.latlng.lat, e.latlng.lng); setRouteStatus('info', 'Start set (A)', 'Now tap the map or type a destination.'); }
            else if (!endCoords) { setDestination(e.latlng.lat, e.latlng.lng); fetchRoute(); }
            else { clearRouting(); setOrigin(e.latlng.lat, e.latlng.lng); }
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
                            suggBox.innerHTML = '<div class="suggestion-item" style="cursor:default">No matches in Nairobi. Try another name or tap the map.</div>';
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
                                if (startCoords && endCoords) fetchRoute();
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

        function setRouteStatus(tone, title, detail = '') {
            const icon = { safe: 'i-check', med: 'i-alert', high: 'i-alert', info: 'i-info' }[tone] || 'i-info';
            const t = tone === 'info' ? 'none' : tone;
            document.getElementById('route-status').innerHTML = `
                <div class="result" data-tone="${t}">
                    <svg class="i"><use href="#${icon}"/></svg>
                    <div><b>${escapeHtml(title)}</b>${detail ? `<p>${escapeHtml(detail)}</p>` : ''}</div>
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
                    if (endCoords) fetchRoute(); else setRouteStatus('info', 'Start set to your location', 'Now choose a destination.');
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
            if (!o && !d) { setRouteStatus('med', 'Add a start and a destination', 'Type a place, tap the map, or use your location.'); return; }
            if (!o) { setRouteStatus('med', 'Where are you starting from?', 'Add a start, or use your location.'); return; }
            if (!d) { setRouteStatus('med', 'Where are you going?', 'Add a destination or tap it on the map.'); return; }
            if (!startCoords || !endCoords) { setRouteStatus('med', 'Choose a place from the list', 'Pick a suggestion under the field, or tap the point on the map.'); return; }
            fetchRoute();
        }

        function clearRouting() {
            [startMarker, endMarker, routeLayer].forEach(l => { if (l) map.removeLayer(l); });
            startMarker = endMarker = routeLayer = null;
            startCoords = endCoords = null;
            document.getElementById('origin-input').value = '';
            document.getElementById('dest-input').value = '';
            document.getElementById('route-status').innerHTML = '<p class="status">Route cleared. Set a new start and destination.</p>';
        }

        function fetchRoute() {
            const avoidFlood = document.getElementById('avoid-flood-toggle').checked;
            const intensity = document.getElementById('intensity-slider').value;
            const duration = document.getElementById('duration-slider').value;

            setRouteStatus('info', 'Finding the safest route…');
            setBusy('route-btn', true);
            const url = `/api/calculate-route?start_lat=${startCoords.lat}&start_lng=${startCoords.lng}&end_lat=${endCoords.lat}&end_lng=${endCoords.lng}&avoid_flood=${avoidFlood}&intensity=${intensity}&duration=${duration}`;

            fetch(url)
                .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                .then(data => {
                    if (routeLayer) map.removeLayer(routeLayer);
                    if (!data.features || data.features.length === 0) {
                        setRouteStatus('high', "We couldn't find a route", 'Try moving a point closer to a main road.');
                        return;
                    }
                    routeLayer = L.geoJSON(data, {
                        style: f => f.properties.route_type === 'flooded_direct'
                            ? { color: '#8b5cf6', weight: 5, opacity: 0.9, dashArray: '10, 10', lineCap: 'round' }
                            : { color: '#16a34a', weight: 8, opacity: 0.95, lineCap: 'round', lineJoin: 'round' }
                    }).addTo(map);

                    const p = data.features[0].properties || {};
                    const dist = p.distance_km ? `${p.distance_km} km` : '';
                    const mins = p.duration_min ? `about ${p.duration_min} min` : '';
                    const summary = [dist, mins].filter(Boolean).join(' · ');
                    const avoided = p.avoided_zones || 0;

                    map.fitBounds(routeLayer.getBounds(), mapPad());

                    if (avoided > 0) {
                        setRouteStatus('safe', 'Safe detour found', `${summary}. Avoids ${avoided} flooded ${avoided === 1 ? 'area' : 'areas'}. The dashed purple line is the direct route through flooding.`);
                    } else if (!simHasRun && avoidFlood) {
                        setRouteStatus('safe', 'Best route found', `${summary}. Run a simulation on the Flood risk tab so this route can avoid flooded areas.`);
                    } else {
                        setRouteStatus('safe', 'Best route found', `${summary}. No flooded areas on this route.`);
                    }
                })
                .catch(err => {
                    console.error("Routing error:", err);
                    setRouteStatus('high', "Couldn't calculate the route", 'Check your connection and try again.');
                })
                .finally(() => setBusy('route-btn', false));
        }

        // Initialise
        applyTheme(currentTheme());
        updateRainSummary(0);
        document.getElementById('live-rain-level').innerText = 'Checking…';
        document.getElementById('tile-rain').dataset.tone = 'none';
    </script>
</body>
</html>