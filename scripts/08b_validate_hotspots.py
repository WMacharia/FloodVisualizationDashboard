"""
PHASE 8b: VALIDATE SIMULATED FLOODING AGAINST REPORTED APRIL-MAY 2024 HOTSPOTS

For each reported hotspot, computes the share of cells within RADIUS_M that the
simulator floods (> FLOOD_DEPTH_M) and ranks it against every same-sized window in
Nairobi. If the simulator is realistic, reported hotspots should rank well above
the city median.

Hotspots come from Kenya Red Cross alerts, KURA road closures and news coverage
of the April-May 2024 floods. Coordinates are geocoded once with Nominatim and saved
to data/validation/hotspots.geojson so they can be checked and corrected by hand.

Usage:
    python 08b_validate_hotspots.py                          # uses depth_i050_d2.tif
    python 08b_validate_hotspots.py --depth data/scenarios/depth_i090_d3.tif
"""
import os
import json
import time
import argparse
import urllib.parse
import urllib.request
import numpy as np
import rasterio
from scipy.ndimage import uniform_filter

script_dir = os.path.dirname(os.path.abspath(__file__))
val_dir = os.path.join(script_dir, "data", "validation")
os.makedirs(val_dir, exist_ok=True)
hotspot_path = os.path.join(val_dir, "hotspots.geojson")

FLOOD_DEPTH_M = 0.15
RADIUS_M = 1000

# (query, reported mechanism)
HOTSPOTS = [
    ("Mathare, Nairobi", "riverine - Mathare River"),
    ("Mukuru Kayaba, Nairobi", "pluvial"),
    ("Mukuru Viwandani, Nairobi", "pluvial"),
    ("Kibera, Nairobi", "pluvial / Ngong River"),
    ("Huruma, Nairobi", "flash flood"),
    ("Ruaraka, Nairobi", "flash flood"),
    ("Baba Dogo, Nairobi", "flash flood"),
    ("Umoja, Nairobi", "flash flood"),
    ("Njiru, Nairobi", "flash flood"),
    ("Ruai, Nairobi", "flash flood"),
    ("Utawala, Nairobi", "flash flood"),
    ("Kahawa Sukari, Kenya", "pluvial / Thika Road"),
    ("Kahawa West, Nairobi", "pluvial"),
    ("Githurai, Kenya", "pluvial"),
    ("Kamulu, Kenya", "riverine - Athi River"),
    ("Syokimau, Kenya", "riverine - Athi River"),
    ("Kitengela, Kenya", "riverine - Athi River"),
    ("Enterprise Road, Nairobi", "road closure (KURA)"),
]


def geocode_hotspots():
    if os.path.exists(hotspot_path):
        with open(hotspot_path, encoding="utf-8") as f:
            return json.load(f)

    features = []
    for query, mechanism in HOTSPOTS:
        url = "https://nominatim.openstreetmap.org/search?" + urllib.parse.urlencode(
            {"q": query, "format": "jsonv2", "limit": 1, "countrycodes": "ke"})
        req = urllib.request.Request(url, headers={"User-Agent": "NairobiFloodDSS_Capstone/1.0"})
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                res = json.loads(resp.read().decode("utf-8"))
        except Exception as e:
            res = []
            print(f"  [WARN] {query}: {e}")
        if res:
            lat, lon = float(res[0]["lat"]), float(res[0]["lon"])
            features.append({
                "type": "Feature",
                "geometry": {"type": "Point", "coordinates": [lon, lat]},
                "properties": {"name": query, "mechanism": mechanism,
                               "osm_display_name": res[0].get("display_name")},
            })
            print(f"  {query:30s} -> {lat:.4f}, {lon:.4f}")
        else:
            print(f"  {query:30s} -> NOT FOUND")
        time.sleep(1.1)  # Nominatim usage policy: max 1 request/second

    fc = {"type": "FeatureCollection", "features": features}
    with open(hotspot_path, "w", encoding="utf-8") as f:
        json.dump(fc, f, indent=2)
    return fc


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--depth", default=os.path.join(script_dir, "data", "scenarios", "depth_i050_d2.tif"))
    args = parser.parse_args()

    print("1. Hotspot locations")
    fc = geocode_hotspots()

    print(f"\n2. Scoring against {os.path.basename(args.depth)}")
    with rasterio.open(args.depth) as src:
        depth = src.read(1)
        transform = src.transform
    H, W = depth.shape
    cell_m = abs(transform.a) * 111320.0
    win = int(round(2 * RADIUS_M / cell_m)) | 1

    flooded = (depth > FLOOD_DEPTH_M).astype(np.float32)
    frac = uniform_filter(flooded, size=win, mode="constant")
    # Reference distribution: every window fully inside the grid
    m = win // 2
    ref = frac[m:H - m, m:W - m].ravel()
    city_median = float(np.median(ref))

    rows = []
    for f in fc["features"]:
        lon, lat = f["geometry"]["coordinates"]
        c, r = ~transform * (lon, lat)
        r, c = int(r), int(c)
        name = f["properties"]["name"]
        if not (0 <= r < H and 0 <= c < W):
            print(f"  {name:30s} outside model grid - skipped")
            continue
        v = float(frac[r, c])
        pct = float((ref < v).mean() * 100)
        rows.append((name, f["properties"]["mechanism"], v, pct))

    print(f"\n  City median flooded share within {RADIUS_M} m: {city_median * 100:.2f}%\n")
    print(f"  {'Hotspot':30s} {'Mechanism':26s} {'Flooded':>8s} {'Percentile':>10s}")
    for name, mech, v, pct in rows:
        print(f"  {name:30s} {mech:26s} {v * 100:7.2f}% {pct:9.1f}")

    if rows:
        pcts = np.array([r[3] for r in rows])
        print(f"\n  Hotspots above city median : {(pcts > 50).sum()}/{len(rows)}")
        print(f"  Mean percentile rank       : {pcts.mean():.1f} (50 = no better than random)")

    out = os.path.join(val_dir, f"validation_{os.path.splitext(os.path.basename(args.depth))[0]}.json")
    with open(out, "w", encoding="utf-8") as fh:
        json.dump([{"name": n, "mechanism": m_, "flooded_share": v, "percentile": p} for n, m_, v, p in rows], fh, indent=2)
    print(f"\n  Saved: {out}")


if __name__ == "__main__":
    main()
