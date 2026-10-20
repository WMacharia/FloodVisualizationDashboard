<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nairobi Flood Decision Support System</title>
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        body { margin: 0; padding: 0; display: flex; height: 100vh; background: #0f172a; color: #f8fafc; overflow: hidden; }
        
        /* Sidebar Layout */
        #sidebar {
            width: 420px;
            background: #1e293b;
            border-right: 1px solid #334155;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            overflow-y: auto;
            padding: 14px;
            gap: 12px;
        }
        .header h1 { font-size: 1.1rem; margin: 0 0 2px 0; color: #38bdf8; }
        .header p { font-size: 0.75rem; margin: 0; color: #94a3b8; }

        /* Cards */
        .card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 12px;
        }
        .card-title {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #cbd5e1;
            margin: 0 0 8px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-live {
            background: #ef4444;
            color: white;
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 12px;
            animation: pulse 2s infinite;
        }
        .badge-count {
            background: #334155;
            color: #38bdf8;
            font-size: 0.68rem;
            padding: 2px 8px;
            border-radius: 10px;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* Weather Grid */
        .weather-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            text-align: center;
        }
        .weather-stat { background: #1e293b; padding: 6px; border-radius: 6px; }
        .weather-stat .val { font-size: 0.95rem; font-weight: 700; color: #38bdf8; }
        .weather-stat .lbl { font-size: 0.65rem; color: #94a3b8; }

        /* Sliders */
        .control-group { margin-bottom: 8px; }
        .control-group label {
            display: flex;
            justify-content: space-between;
            font-size: 0.78rem;
            margin-bottom: 2px;
            color: #cbd5e1;
        }
        input[type="range"] { width: 100%; accent-color: #38bdf8; cursor: pointer; }

        /* SHAP Explainability Panel */
        .shap-subtitle {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }
        .shap-row { margin-bottom: 6px; }
        .shap-label-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.72rem;
            color: #e2e8f0;
            margin-bottom: 2px;
        }
        .shap-bar-track {
            width: 100%;
            height: 8px;
            background: #1e293b;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .shap-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Flagged Blackspots List */
        #blackspot-list {
            max-height: 135px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .blackspot-item {
            background: #1e293b;
            border-left: 4px solid #38bdf8;
            padding: 6px 9px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .blackspot-item:hover { background: #334155; }
        .blackspot-item.level-3 { border-left-color: #ef4444; }
        .blackspot-item.level-2 { border-left-color: #f97316; }
        .blackspot-item.level-1 { border-left-color: #38bdf8; }
        .bs-name { font-size: 0.78rem; font-weight: 600; color: #f8fafc; }
        .bs-meta { font-size: 0.68rem; color: #94a3b8; display: flex; justify-content: space-between; margin-top: 2px; }

        /* Routing Inputs */
        .input-wrapper { position: relative; margin-bottom: 8px; }
        .input-wrapper input {
            width: 100%;
            padding: 7px 10px 7px 28px;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 6px;
            color: #f8fafc;
            font-size: 0.8rem;
        }
        .input-wrapper input:focus { outline: none; border-color: #38bdf8; }
        .pin-dot {
            position: absolute;
            left: 9px;
            top: 9px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
        }
        .pin-origin { background: #22c55e; }
        .pin-dest { background: #ef4444; }

        .suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 6px;
            max-height: 140px;
            overflow-y: auto;
            z-index: 2000;
            display: none;
        }
        .suggestion-item {
            padding: 6px 10px;
            font-size: 0.76rem;
            border-bottom: 1px solid #334155;
            cursor: pointer;
            color: #e2e8f0;
        }
        .suggestion-item:hover { background: #334155; }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 7px 10px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .btn-primary { background: #0284c7; color: white; }
        .btn-success { background: #16a34a; color: white; }
        .btn-outline {
            background: transparent;
            border: 1px solid #475569;
            color: #cbd5e1;
            margin-top: 6px;
        }
        .btn-row { display: flex; gap: 8px; }
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            margin: 4px 0 8px 0;
            color: #cbd5e1;
        }

        /* Adds a liquid glow effect to the flood polygons */
        .water-polygon {
            filter: drop-shadow(0px 0px 5px rgba(2, 132, 199, 0.6));
            transition: all 0.3s ease;
        }
        .water-polygon:hover {
            filter: drop-shadow(0px 0px 8px rgba(56, 189, 248, 0.9));
        }

        /* Stop Tailwind CSS from breaking Leaflet tile alignment */
        .leaflet-container img {
            max-width: none !important;
            max-height: none !important;
        }

        /* Deepen the basemap contrast to make the glowing flood water pop */
        .basemap-tiles {
            filter: contrast(1.1) brightness(0.85);
        }

        /* Legible Basemap Filter: Brightens text & streets while muting background noise */
        .custom-dark-basemap {
            filter: brightness(0.9) contrast(1.2);
        }

        /* 3. Fluid Organic Blue Glow for Flood Hotspots */
        .water-polygon {
            filter: drop-shadow(0px 0px 8px rgba(56, 189, 248, 0.8));
            transition: all 0.25s ease-in-out;
        }
        .water-polygon:hover {
            filter: drop-shadow(0px 0px 14px rgba(56, 189, 248, 1.0));
            cursor: pointer;
        }

        /* Makes place names bright white and readable against dark backgrounds */
        .bright-map-labels {
            filter: brightness(3.0) contrast(1.6) drop-shadow(0px 0px 3px rgba(0, 0, 0, 0.95));
        }

        #map { flex: 1; height: 100vh; background: #0f172a; }
        .status-text { font-size: 0.72rem; color: #94a3b8; margin-top: 5px; }
    </style>
</head>
<body class="bg-[#0f172a] text-slate-100 font-sans antialiased">

    <!-- Floating Auth Widget -->
    <div class="fixed top-4 right-4 z-[9999]">
        @auth
            <div class="flex items-center space-x-3 bg-[#1e293b]/90 border border-slate-700/80 px-3.5 py-1.5 rounded-lg shadow-xl backdrop-blur">
                @if(Auth::user()->avatar)
                    <img src="{{ Auth::user()->avatar }}" class="w-7 h-7 rounded-full border border-sky-500/50" alt="avatar">
                @else
                    <div class="w-7 h-7 rounded-full bg-sky-600/30 border border-sky-500/50 flex items-center justify-center text-sky-300 text-xs font-bold">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </div>
                @endif

                <div class="flex flex-col text-left">
                    <span class="text-xs font-semibold text-white leading-tight">{{ Auth::user()->name }}</span>
                    <span class="text-[10px] text-slate-400 capitalize">
                        {{ Auth::user()->role === 'admin' ? 'System Admin' : 'Logged In' }}
                    </span>
                </div>

                @if(Auth::user()->role === 'admin')
                    <a href="{{ route('admin.index') }}" class="ml-2 px-2.5 py-1 bg-sky-500/20 hover:bg-sky-500/30 text-sky-400 text-xs font-semibold rounded border border-sky-500/30 transition-colors">
                        Admin Console
                    </a>
                @endif

                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="ml-1 text-slate-400 hover:text-red-400 text-xs font-semibold transition-colors">
                        Sign Out
                    </button>
                </form>
            </div>
        @else
            <a href="{{ route('login.google') }}" class="inline-flex items-center space-x-2 px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold rounded-lg shadow-xl border border-sky-400/30 transition-all hover:scale-[1.02]">
                <svg class="w-4 h-4 bg-white rounded-full p-0.5" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.11-6.72-4.96H1.29v3.13C3.26 21.3 7.31 24 12 24z"/>
                    <path fill="#FBBC05" d="M5.28 14.24c-.25-.72-.38-1.49-.38-2.24s.13-1.52.38-2.24V6.63H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.37l3.99-3.13z"/>
                    <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.63l3.99 3.13c.95-2.85 3.6-4.96 6.72-4.96z"/>
                </svg>
                <span>Sign In with Google</span>
            </a>
        @endauth
    </div>

    <!-- Sidebar Container -->
    <div id="sidebar">
        <div class="header flex items-center justify-between mb-2">
            <div>
                <h1 class="text-xl font-bold text-sky-400">Nairobi Flood DSS</h1>
                <p class="text-xs text-slate-400">U-Net Spatial Flood Risk & Route Safety</p>
            </div>
        </div>

        <!-- 1. LIVE WEATHER FEED -->
        <div class="card">
            <div class="card-title">
                <span>Live Nairobi Weather</span>
                <span class="badge-live">LIVE API</span>
            </div>
            <div class="weather-grid">
                <div class="weather-stat">
                    <div class="val" id="live-rain">-- mm</div>
                    <div class="lbl">Precipitation</div>
                </div>
                <div class="weather-stat">
                    <div class="val" id="live-temp">-- °C</div>
                    <div class="lbl">Temperature</div>
                </div>
                <div class="weather-stat">
                    <div class="val" id="live-hum">-- %</div>
                    <div class="lbl">Humidity</div>
                </div>
            </div>
            <button class="btn btn-outline" onclick="applyLiveWeatherToModel()">
                Predict from Live Weather Feed
            </button>
        </div>

        <!-- 2. FLOOD SIMULATION SLIDERS -->
        <div class="card">
            <div class="card-title">Flood Scenario Simulator</div>
            <div class="control-group">
                <label>
                    <span>Rainfall Intensity</span>
                    <strong id="intensity-val">55 mm/hr</strong>
                </label>
                <input type="range" id="intensity-slider" min="5" max="120" value="55" 
                       oninput="document.getElementById('intensity-val').innerText = this.value + ' mm/hr'">
            </div>
            <div class="control-group">
                <label>
                    <span>Storm Duration</span>
                    <strong id="duration-val">3 hrs</strong>
                </label>
                <input type="range" id="duration-slider" min="1" max="24" value="3" 
                       oninput="document.getElementById('duration-val').innerText = this.value + ' hrs'">
            </div>
            <button class="btn btn-primary" onclick="runFloodSimulation()">
                Run Flood Simulation
            </button>
            <div class="status-text" id="sim-status">Ready to simulate. Adjust sliders and click Run.</div>
        </div>

        <!-- 3. SHAP EXPLAINABILITY (XAI) PANEL -->
        <div class="card">
            <div class="card-title">
                <span>SHAP Explainability (XAI)</span>
                <span class="badge-count" id="shap-scope-badge">Global Model</span>
            </div>
            <div class="shap-subtitle">
                <span id="shap-target-name">Mean Feature Attribution</span>
                <span id="shap-pred-prob">P(Flood): --</span>
            </div>
            <div id="shap-bars-container"></div>
        </div>

        <!-- 4. FLAGGED NAIROBI BLACKSPOTS -->
        <div class="card">
            <div class="card-title">
                <span>Flagged Flood Hotspots</span>
                <span class="badge-count" id="blackspot-count">0 Flagged</span>
            </div>
            <div id="blackspot-list"></div>
        </div>

        <!-- 5. SAFE ROUTE PLANNER -->
        <div class="card">
            <div class="card-title">Safe Route Planner</div>
            <div class="input-wrapper">
                <span class="pin-dot pin-origin"></span>
                <input type="text" id="origin-input" placeholder="Origin (e.g. Westlands, Ngara)" autocomplete="off">
                <div class="suggestions" id="origin-suggestions"></div>
            </div>
            <div class="input-wrapper">
                <span class="pin-dot pin-dest"></span>
                <input type="text" id="dest-input" placeholder="Destination (e.g. Strathmore, South B)" autocomplete="off">
                <div class="suggestions" id="dest-suggestions"></div>
            </div>
            <div class="toggle-row">
                <span>Avoid Flagged Flood Blackspots</span>
                <input type="checkbox" id="avoid-flood-toggle" checked onchange="if(startCoords && endCoords) fetchRoute();">
            </div>
            <div class="btn-row">
                <button class="btn btn-success" onclick="handleFindRouteClick()">Find Safe Route</button>
                <button class="btn btn-outline" style="margin-top:0; width: 40%;" onclick="clearRouting()">Clear</button>
            </div>
            <div class="status-text" id="route-status">Type locations or click on map to route.</div>
        </div>
    </div>

    <!-- Leaflet Map Container -->
    <div id="map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const map = L.map('map').setView([-1.2880, 36.8320], 12);

        // 2. Create a high-priority 'labels' pane so text always renders ABOVE flood polygons
        map.createPane('labels');
        map.getPane('labels').style.zIndex = 650;
        map.getPane('labels').style.pointerEvents = 'none';

        // 3. Dark Street Base Layer (No text)
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}@2x.png?key=cb1_4giv_1_8d1b08af50b833befe3b1884', {
            attribution: '&copy; OpenStreetMap contributors, &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        // 4. High-Contrast Overlay Labels Layer (Floats text on top of everything)
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}@2x.png?key=cb1_4giv_1_8d1b08af50b833befe3b1884', {
            attribution: '&copy; OpenStreetMap contributors, &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19,
            pane: 'labels',
            className: 'bright-map-labels'
        }).addTo(map);

        // L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}@2x.png?key=cb1_4giv_1_8d1b08af50b833befe3b1884', {
        //     attribution: '&copy; OpenStreetMap &copy; CARTO',
        //     subdomains: 'abcd',
        //     maxZoom: 19,
        //     className: 'custom-dark-basemap'
        // }).addTo(map);

        let floodLayerGroup = L.layerGroup().addTo(map);
        let startMarker = null;
        let endMarker = null;
        let routeLayer = null;

        let startCoords = null;
        let endCoords = null;
        let currentLiveRain = 0;

        // =========================================================
        // 1. RENDER SHAP EXPLAINABILITY BARS
        // =========================================================
        function renderShapBars(contributions, targetTitle, predProb, baseVal, isLocal = false) {
            const container = document.getElementById('shap-bars-container');
            document.getElementById('shap-scope-badge').innerText = isLocal ? 'Local Zone SHAP' : 'Global Model';
            document.getElementById('shap-target-name').innerText = targetTitle;
            document.getElementById('shap-pred-prob').innerText = `Base: ${Math.round((baseVal||0.08)*100)}% → Pred: ${Math.round((predProb||0)*100)}%`;

            container.innerHTML = '';
            const entries = Object.entries(contributions || {}).sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]));

            entries.forEach(([feature, val]) => {
                const isPositive = val >= 0;
                const sign = isPositive ? '+' : '';
                const pctWidth = Math.min(100, Math.max(6, Math.abs(val) * 260));
                const color = isPositive ? '#ef4444' : '#22c55e';

                const row = document.createElement('div');
                row.className = 'shap-row';
                row.innerHTML = `
                    <div class="shap-label-row">
                        <span>${feature}</span>
                        <b style="color:${color}">${sign}${val.toFixed(3)}</b>
                    </div>
                    <div class="shap-bar-track">
                        <div class="shap-bar-fill" style="width:${pctWidth}%; background:${color};"></div>
                    </div>
                `;
                container.appendChild(row);
            });
        }

        // =========================================================
        // 2. LIVE WEATHER API
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
                }
            } catch (e) {}
        }
        fetchLiveWeather();

        function applyLiveWeatherToModel() {
            const targetRain = Math.max(15, Math.round(currentLiveRain));
            document.getElementById('intensity-slider').value = targetRain;
            document.getElementById('intensity-val').innerText = `${targetRain} mm/hr`;
            document.getElementById('duration-slider').value = 2;
            document.getElementById('duration-val').innerText = `2 hrs`;
            runFloodSimulation();
        }

        // =========================================================
        // 3. RUN FLOOD SIMULATION
        // =========================================================
        function runFloodSimulation() {
    const intensity = document.getElementById('intensity-slider').value;
    const duration = document.getElementById('duration-slider').value;
    const statusEl = document.getElementById('sim-status');
    const listEl = document.getElementById('blackspot-list');
    const countEl = document.getElementById('blackspot-count');

    statusEl.innerText = `Running U-Net + SHAP (${intensity} mm/hr, ${duration} hrs)...`;

    // 🔴 THE FIX: Fetch directly from FastAPI (Port 8001) to bypass PHP memory truncation
    fetch(`http://127.0.0.1:8001/predict?intensity=${intensity}&duration=${duration}`)
        .then(res => res.json())
        .then(data => {
            
            // Log exactly what arrived in the browser so we can verify it
            console.log("FastAPI Response:", data);

            // 1. Clear existing layers and sidebar cards
            if (floodLayerGroup) floodLayerGroup.clearLayers();
            listEl.innerHTML = '';

            if (data.error) {
                statusEl.innerText = data.error;
                return;
            }

            // 2. Update Global SHAP XAI panel dynamically
            if (data.global_shap) {
                renderShapBars(
                    data.global_shap.contributions,
                    'Global Mean Attribution',
                    data.global_shap.mean_prediction,
                    data.global_shap.base_value,
                    false
                );
            }

            // Fallback to empty array if features are genuinely missing
            const features = data.features || [];
            countEl.innerText = `${features.length} Flagged`;

            if (features.length === 0) {
                statusEl.innerText = `${intensity} mm/hr: No active flood hotspots predicted.`;
                listEl.innerHTML = `<div class="status-text">All monitored Nairobi areas clear at ${intensity} mm/hr.</div>`;
                return;
            }

            // 3. Render dynamic GeoJSON features from PyTorch/FastAPI
            const geoLayer = L.geoJSON(data, {
                style: function(feature) {
                    const prob = feature.properties.flood_probability;
                    let fillColor, borderColor;

                    if (prob >= 0.70) {
                        fillColor = '#0284c7';   // Deep Water Blue
                        borderColor = '#38bdf8'; // Bright Cyan Border
                    } else if (prob >= 0.50) {
                        fillColor = '#0ea5e9';   // Sky Blue
                        borderColor = '#7dd3fc';
                    } else {
                        fillColor = '#38bdf8';   // Light Translucent Blue
                        borderColor = '#bae6fd';
                    }

                    return {
                        stroke: true,
                        color: borderColor,
                        weight: 2,
                        fillColor: fillColor,
                        fillOpacity: 0.55,
                        lineJoin: 'round',
                        lineCap: 'round',
                        className: 'water-polygon'
                    };
                },
                onEachFeature: function(feature, layer) {
                    const p = feature.properties;
                    const shapEntries = Object.entries(p.shap_values || {})
                        .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]))
                        .slice(0, 4)
                        .map(([k, v]) => `<div>• ${k}: <b style="color:${v>=0?'#ef4444':'#22c55e'}">${v >= 0 ? '+' : ''}${v.toFixed(2)}</b></div>`)
                        .join('');

                    const popupHtml = `
                        <div style="font-size:12.5px; line-height:1.4; min-width:210px; color:#0f172a;">
                            <b style="color:#0284c7; font-size:13.5px;">${p.name}</b><br>
                            <b>Status:</b> ${p.hazard_label}<br>
                            <b>Est. Depth:</b> ${p.predicted_depth_m} m | <b>Prob:</b> ${Math.round(p.flood_probability*100)}%<br>
                            <hr style="margin:5px 0; border:0; border-top:1px solid #cbd5e1;">
                            <b>Top SHAP Drivers (φᵢ):</b>
                            ${shapEntries}
                        </div>
                    `;
                    layer.bindPopup(popupHtml);

                    layer.on('click', () => {
                        renderShapBars(p.shap_values, p.name, p.flood_probability, p.base_value, true);
                    });
                }
            });

            // 4. Add vectors to map
            floodLayerGroup.addLayer(geoLayer);
            geoLayer.bringToFront();

            // 5. DYNAMIC FIX: Automatically pan/zoom Leaflet to match ANY predicted hotspots
            if (geoLayer.getBounds().isValid()) {
                map.fitBounds(geoLayer.getBounds(), { padding: [50, 50], maxZoom: 14 });
            }

            // 6. Dynamically build side panel cards for predicted hotspots
            features
                .sort((a, b) => b.properties.flood_probability - a.properties.flood_probability)
                .forEach((f) => {
                    const p = f.properties;
                    const item = document.createElement('div');
                    item.className = `blackspot-item level-${p.hazard_level}`;
                    item.innerHTML = `
                        <div class="bs-name">${p.name}</div>
                        <div class="bs-meta">
                            <span>${p.category}</span>
                            <b>Depth: ${p.predicted_depth_m}m</b>
                        </div>
                    `;
                    item.onclick = () => {
                        map.flyTo([p.center_lat, p.center_lng], 14);
                        renderShapBars(p.shap_values, p.name, p.flood_probability, p.base_value, true);
                    };
                    listEl.appendChild(item);
                });

            statusEl.innerText = `Rendered ${features.length} dynamic flood zone(s) (${intensity} mm/hr).`;
            
            // 7. Update routing bypass
            if (startCoords && endCoords) fetchRoute();
        })
        .catch(err => {
            console.error("Simulation error:", err);
            statusEl.innerText = "Error reaching flood model endpoint.";
        });
}

        // =========================================================
        // 4. ROUTING LOGIC
        // =========================================================
        function setOrigin(lat, lng, labelText = null) {
            startCoords = { lat: parseFloat(lat), lng: parseFloat(lng) };
            if (startMarker) map.removeLayer(startMarker);

            const originIcon = L.divIcon({
                className: '',
                html: `<div style="width:16px;height:16px;background:#22c55e;border:3px solid #fff;border-radius:50%;box-shadow:0 0 10px #22c55e;"></div>`,
                iconSize: [16, 16],
                iconAnchor: [8, 8]
            });

            startMarker = L.marker([startCoords.lat, startCoords.lng], { icon: originIcon, draggable: true })
                .addTo(map)
                .bindPopup("<b>Origin</b>")
                .openPopup();

            startMarker.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                setOrigin(pos.lat, pos.lng);
                if (endCoords) fetchRoute();
            });

            if (labelText) {
                document.getElementById('origin-input').value = labelText;
            } else {
                reverseGeocode(startCoords.lat, startCoords.lng, 'origin-input');
            }
        }

        function setDestination(lat, lng, labelText = null) {
            endCoords = { lat: parseFloat(lat), lng: parseFloat(lng) };
            if (endMarker) map.removeLayer(endMarker);

            const destIcon = L.divIcon({
                className: '',
                html: `<div style="width:16px;height:16px;background:#ef4444;border:3px solid #fff;border-radius:50%;box-shadow:0 0 10px #ef4444;"></div>`,
                iconSize: [16, 16],
                iconAnchor: [8, 8]
            });

            endMarker = L.marker([endCoords.lat, endCoords.lng], { icon: destIcon, draggable: true })
                .addTo(map)
                .bindPopup("<b>Destination</b>")
                .openPopup();

            endMarker.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                setDestination(pos.lat, pos.lng);
                if (startCoords) fetchRoute();
            });

            if (labelText) {
                document.getElementById('dest-input').value = labelText;
            } else {
                reverseGeocode(endCoords.lat, endCoords.lng, 'dest-input');
            }
        }

        map.on('click', function(e) {
            if (!startCoords) {
                setOrigin(e.latlng.lat, e.latlng.lng);
            } else if (!endCoords) {
                setDestination(e.latlng.lat, e.latlng.lng);
                fetchRoute();
            } else {
                clearRouting();
                setOrigin(e.latlng.lat, e.latlng.lng);
            }
        });

        async function reverseGeocode(lat, lng, inputId) {
            const inputEl = document.getElementById(inputId);
            inputEl.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            try {
                const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
                const data = await res.json();
                if (data && data.display_name) {
                    inputEl.value = data.display_name.split(',').slice(0, 2).join(', ');
                }
            } catch (err) {}
        }

        function setupAutocomplete(inputId, suggestionsId, isOrigin) {
            const inputEl = document.getElementById(inputId);
            const suggBox = document.getElementById(suggestionsId);
            let debounceTimer = null;

            inputEl.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();
                if (query.length < 3) {
                    suggBox.style.display = 'none';
                    return;
                }

                debounceTimer = setTimeout(async () => {
                    try {
                        const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&q=${encodeURIComponent(query)}&countrycodes=ke&viewbox=36.65,-1.15,37.10,-1.45&bounded=1&limit=5`;
                        const res = await fetch(url);
                        const results = await res.json();

                        suggBox.innerHTML = '';
                        if (results.length === 0) {
                            suggBox.style.display = 'none';
                            return;
                        }

                        results.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'suggestion-item';
                            const shortLabel = item.display_name.split(',').slice(0, 3).join(', ');
                            div.innerText = shortLabel;
                            div.onclick = () => {
                                suggBox.style.display = 'none';
                                if (isOrigin) {
                                    setOrigin(item.lat, item.lon, shortLabel);
                                } else {
                                    setDestination(item.lat, item.lon, shortLabel);
                                }
                                map.panTo([parseFloat(item.lat), parseFloat(item.lon)]);
                                if (startCoords && endCoords) fetchRoute();
                            };
                            suggBox.appendChild(div);
                        });
                        suggBox.style.display = 'block';
                    } catch (e) {}
                }, 350);
            });

            document.addEventListener('click', (e) => {
                if (e.target !== inputEl) suggBox.style.display = 'none';
            });
        }

        setupAutocomplete('origin-input', 'origin-suggestions', true);
        setupAutocomplete('dest-input', 'dest-suggestions', false);

        async function handleFindRouteClick() {
            const originText = document.getElementById('origin-input').value.trim();
            const destText = document.getElementById('dest-input').value.trim();
            const statusEl = document.getElementById('route-status');

            if (!originText || !destText) {
                statusEl.innerText = "Please enter or click both an Origin and Destination.";
                return;
            }

            statusEl.innerText = "Locating route endpoints...";
            if (startCoords && endCoords) {
                fetchRoute();
            }
        }

        function clearRouting() {
            if (startMarker) map.removeLayer(startMarker);
            if (endMarker) map.removeLayer(endMarker);
            if (routeLayer) map.removeLayer(routeLayer);
            startMarker = null;
            endMarker = null;
            routeLayer = null;
            startCoords = null;
            endCoords = null;
            document.getElementById('origin-input').value = '';
            document.getElementById('dest-input').value = '';
            document.getElementById('route-status').innerText = 'Cleared. Type or click on map to route.';
        }

        function fetchRoute() {
            const statusEl = document.getElementById('route-status');
            const avoidFlood = document.getElementById('avoid-flood-toggle').checked;
            const intensity = document.getElementById('intensity-slider').value;
            const duration = document.getElementById('duration-slider').value;

            statusEl.innerText = "Calculating safe street route...";
            const url = `/api/calculate-route?start_lat=${startCoords.lat}&start_lng=${startCoords.lng}&end_lat=${endCoords.lat}&end_lng=${endCoords.lng}&avoid_flood=${avoidFlood}&intensity=${intensity}&duration=${duration}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (routeLayer) map.removeLayer(routeLayer);

                    if (!data.features || data.features.length === 0) {
                        statusEl.innerText = "Could not compute route.";
                        return;
                    }

                    routeLayer = L.geoJSON(data, {
                        style: function(feature) {
                            if (feature.properties.route_type === 'flooded_direct') {
                                return { color: '#ef4444', weight: 4, opacity: 0.75, dashArray: '8, 8' };
                            }
                            return { color: '#22c55e', weight: 6, opacity: 0.95 };
                        }
                    }).addTo(map);

                    const mainProps = data.features[0].properties || {};
                    const dist = mainProps.distance_km ? `${mainProps.distance_km} km` : '';
                    const mins = mainProps.duration_min ? `${mainProps.duration_min} mins` : '';
                    const avoided = mainProps.avoided_zones || 0;

                    map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
                    statusEl.innerText = avoided > 0
                        ? `Safe detour found: ${dist} (~${mins}) — Avoided ${avoided} flooded zone(s)!`
                        : `Optimal route: ${dist} (~${mins}).`;
                })
                .catch(err => {
                    console.error("Routing error:", err);
                    statusEl.innerText = "Error calculating route.";
                });
        }
    </script>
</body>
</html>