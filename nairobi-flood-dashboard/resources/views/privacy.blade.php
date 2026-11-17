<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy policy | Nairobi Flood DSS</title>
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
        :root, [data-theme="light"] {
            --bg: #e9eef5; --surface: #ffffff; --line: #cbd5e1;
            --text: #0f172a; --text-2: #1e293b; --text-3: #475569; --accent: #0369a1;
        }
        [data-theme="dark"] {
            --bg: #0b1220; --surface: #111b2e; --line: #2d3b55;
            --text: #f8fafc; --text-2: #e2e8f0; --text-3: #a8b8d0; --accent: #38bdf8;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; padding: 32px 16px 48px; background: var(--bg); color: var(--text-2);
            font-family: 'Atkinson Hyperlegible', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 16px; line-height: 1.6; -webkit-font-smoothing: antialiased;
        }
        main { max-width: 760px; margin: 0 auto; padding: 32px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
        h1 { margin: 0 0 4px; font-size: 1.75rem; line-height: 1.25; color: var(--text); }
        h2 { margin: 28px 0 8px; font-size: 1.15rem; color: var(--text); }
        .muted { color: var(--text-3); font-size: 14.5px; }
        a { color: var(--accent); font-weight: 700; text-underline-offset: 3px; }
        ul { padding-left: 22px; margin: 8px 0; }
        li + li { margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 15px; }
        th, td { text-align: left; vertical-align: top; padding: 8px 10px; border-bottom: 1px solid var(--line); }
        th { color: var(--text); }
        .back { display: inline-block; margin-bottom: 20px; }
        @media (max-width: 560px) { main { padding: 22px 18px; } th, td { padding: 6px; } }
    </style>
</head>
<body>
<main>
    <a class="back" href="/">&larr; Back to the flood map</a>
    <h1>Privacy policy</h1>
    <p class="muted">Nairobi Flood Decision Support System · last updated {{ \Illuminate\Support\Carbon::parse('2026-11-08')->format('j F Y') }}</p>

    <p>This system shows predicted flooding in Nairobi and helps residents and responders plan safe routes during storms.
        It was built as a final-year project at Strathmore University. You can use the map without an account; signing in is
        only needed to save places, receive alerts for them, or use the responder and administrator tools.</p>

    <h2>What we collect and why</h2>
    <table>
        <thead><tr><th>Information</th><th>Why</th></tr></thead>
        <tbody>
            <tr><td>Your Google name, email address, profile picture and Google account ID (when you sign in with Google)</td>
                <td>To create your account, show who is signed in and recognise you next time. We never see or store your Google password.</td></tr>
            <tr><td>Your role (resident, responder or administrator)</td>
                <td>To decide which tools you can use.</td></tr>
            <tr><td>Places you save (a name you choose and the map position)</td>
                <td>To alert you when flooding is predicted near them.</td></tr>
            <tr><td>Which alerts you have acknowledged</td>
                <td>So the alert bell shows what is new to you.</td></tr>
            <tr><td>Sign-in session data: IP address, browser type and time of last activity</td>
                <td>To keep you signed in securely and to sign staff out after inactivity.</td></tr>
            <tr><td>An audit log of staff actions and staff sign-ins, including the IP address</td>
                <td>Security and accountability for actions that change what other people see (advisories, roles, accounts).</td></tr>
            <tr><td>A daily count of visits (a number only, not who visited)</td>
                <td>To see how much the system is used.</td></tr>
        </tbody>
    </table>
    <p>We do not sell your information, use it for advertising, or share it with anyone except the services listed below.</p>

    <h2>Your location</h2>
    <p>Your device's location is only read if you press the button to use your current location as a route's starting point,
        and only after your browser asks your permission. It is used for that route and is not stored.</p>

    <h2>Information kept in your browser</h2>
    <p>Your browser remembers your light or dark theme choice and, if you are not signed in, which alerts you have already read.
        This stays on your device; you can clear it in your browser settings.</p>

    <h2>Other services the map uses</h2>
    <p>To work, the map loads content from these services directly in your browser, so they receive your IP address
        and the request (for example the map area or the place you searched for):</p>
    <ul>
        <li><b>Google</b> for signing in (<a href="https://policies.google.com/privacy" rel="noopener" target="_blank">Google privacy policy</a>), and Google Fonts for the typeface.</li>
        <li><b>OpenFreeMap</b> and <b>Esri</b> for the street and satellite map.</li>
        <li><b>OpenStreetMap Nominatim</b> for place search and naming a point you pick on the map.</li>
        <li><b>Open-Meteo</b> for live and forecast rainfall.</li>
        <li><b>unpkg</b> for the mapping software libraries.</li>
    </ul>

    <h2>How long we keep it</h2>
    <p>Your account and saved places are kept until you ask for the account to be deleted. Deleting an account also deletes its
        saved places and alert history. Audit log entries are kept as a security record; they are no longer linked to the deleted account but may still mention its name.</p>

    <h2>Your rights</h2>
    <p>In line with Kenya's Data Protection Act, 2019, you can ask to see the information held about you, correct it, or have
        your account deleted.
        @if (config('app.privacy_contact'))
            Contact <a href="mailto:{{ config('app.privacy_contact') }}">{{ config('app.privacy_contact') }}</a>.
        @else
            Contact the system administrator.
        @endif
        You can also remove this system's access to your Google account at any time from your
        <a href="https://myaccount.google.com/connections" rel="noopener" target="_blank">Google account settings</a>.</p>

    <h2>Security</h2>
    <p>The site uses HTTPS. Sign-in is handled by Google, so no passwords are stored here. Staff accounts are signed out after
        inactivity and must confirm their identity again before changing roles or deleting accounts.</p>
</main>
</body>
</html>
