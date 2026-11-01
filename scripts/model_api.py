"""
Nairobi Flood DSS - model server (FastAPI, port 8001, called only by the Laravel backend)

Runs the trained Attention U-Net surrogate (Flood_Surrogate_Training.ipynb) on the 30 m grid
and serves everything the dashboard shows:

  GET /health                    model, metrics and vehicle limits
  GET /predict                   storm summary: sub-county ranking, hotspots, exposed buildings/facilities
  GET /overlay.png               flood depth map (light blue -> red), transparency = flood probability
  GET /zones                     hazard zones: hexagons (~10 ha) coloured by the deepest water covering 10% of them
  GET /extent                    flood outlines as polygons, one layer per depth band
  GET /point                     values at one location + which vehicles can pass
  GET /explain                   which inputs drove the prediction at one location (ablation)
  GET /route                     vehicle-aware safe route on the OpenStreetMap road network
  GET /subcounties               sub-county outlines

Every endpoint takes the storm (intensity mm/hr, duration hr) as parameters, so results never
depend on what another user ran. Predictions are cached per storm.

Inputs : scripts/models/unet_surrogate.pth + unet_surrogate_config.json  (from Colab)
         scripts/data/surrogate/static_inputs.npz                         (09_build_surrogate_dataset.py)
         scripts/data/dashboard/*                                          (10_build_dashboard_layers.py)
Run    : uvicorn model_api:app --host 127.0.0.1 --port 8001
"""
import os
import json
import math
import threading
from collections import OrderedDict
from contextlib import asynccontextmanager

import cv2
import numpy as np
import rasterio
import torch
import torch.nn as nn
from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.gzip import GZipMiddleware
from fastapi.responses import Response
from scipy import ndimage
from scipy.sparse import csr_matrix
from scipy.sparse.csgraph import dijkstra
from scipy.spatial import cKDTree

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_DIR = os.path.join(BASE_DIR, "models")
DATA_DIR = os.path.join(BASE_DIR, "data")
DASH_DIR = os.path.join(DATA_DIR, "dashboard")

CACHE_STORMS = 8
EXPOSURE_DEPTH_M = 0.3          # buildings/facilities counted as exposed above this depth
CAUTION_TIME_FACTOR = 3.0       # passable but flooded roads cost 3x their travel time (assumption)
MAX_SNAP_M = 1500.0             # start/end must be within this distance of a road
EXPLAIN_CROP = 256
HOTSPOT_BLOCK = 33              # cells per side of a hotspot square (~1 km)
BRIDGE_APPROACH_CELLS = 2       # ~60 m either side of an OSM bridge treated as its approach
LONG_DETOUR_RATIO = 2.0         # safe route taking more than 2x the direct time gets a "consider waiting" advisory
PERMANENT_WATER_CLASS = 80      # ESA WorldCover: dams/lakes are not shown as flooding
ZONE_RADIUS_M = 200.0           # hexagon centre-to-corner distance: ~10 ha, ~118 cells
ZONE_MIN_SHARE = 0.10           # a depth band sets a zone's level once it covers 10% of the zone
ZONE_LEVELS = ["minor", "moderate", "high", "very_high", "severe"]   # minor = some flooding, under 10%
EXTENT_UPSAMPLE = 3             # outlines are traced on a 10 m grid ...
EXTENT_SIGMA_CELLS = 0.7        # ... after a light blur of each depth band
EXTENT_SIMPLIFY_M = 15.0        # then simplified to 15 m (half a cell); the browser rounds the corners

# Used when the model config predates the vehicle table (same values as the notebook, cell 12b)
DEFAULT_VEHICLES = {
    "boda_boda": dict(label="Boda boda (motorcycle)", depth_m=0.15, dv_m2s=None,
                      basis="Assumption: no published criterion; avoids any predicted flooding"),
    "saloon": dict(label="Saloon car", depth_m=0.3, dv_m2s=0.3,
                   basis="ARR Project 10 Table 3: small passenger vehicle"),
    "large_car": dict(label="Large car / matatu", depth_m=0.4, dv_m2s=0.45,
                      basis="ARR Project 10 Table 3: large passenger vehicle"),
    "suv_4x4": dict(label="SUV / 4x4", depth_m=0.5, dv_m2s=0.6,
                    basis="ARR Project 10 Table 3: large 4WD"),
    "lorry_bus": dict(label="Lorry / bus", depth_m=0.5, dv_m2s=0.6,
                      basis="Assumption: no published criterion for heavy vehicles; capped at the large 4WD limit"),
}

# Depth colour ramp (m -> RGB): light blue, yellow, orange, red, dark red
RAMP_DEPTH = np.array([0.15, 0.30, 0.60, 1.20, 2.00])
RAMP_RGB = np.array([[158, 202, 225], [255, 237, 111], [253, 141, 60], [215, 25, 28], [103, 0, 13]], dtype=np.float32)


# ---------------------------------------------------------------------------
# Network (identical to Flood_Surrogate_Training.ipynb, cell 5)
# ---------------------------------------------------------------------------
class DoubleConv(nn.Module):
    def __init__(self, in_ch, out_ch):
        super().__init__()
        self.net = nn.Sequential(
            nn.Conv2d(in_ch, out_ch, 3, padding=1, bias=False), nn.BatchNorm2d(out_ch), nn.ReLU(inplace=True),
            nn.Conv2d(out_ch, out_ch, 3, padding=1, bias=False), nn.BatchNorm2d(out_ch), nn.ReLU(inplace=True))

    def forward(self, x):
        return self.net(x)


class AttentionBlock(nn.Module):
    def __init__(self, F_g, F_l, F_int):
        super().__init__()
        self.W_g = nn.Sequential(nn.Conv2d(F_g, F_int, 1), nn.BatchNorm2d(F_int))
        self.W_x = nn.Sequential(nn.Conv2d(F_l, F_int, 1), nn.BatchNorm2d(F_int))
        self.psi = nn.Sequential(nn.Conv2d(F_int, 1, 1), nn.BatchNorm2d(1), nn.Sigmoid())
        self.relu = nn.ReLU(inplace=True)

    def forward(self, g, x):
        return x * self.psi(self.relu(self.W_g(g) + self.W_x(x)))


class AttentionUNet(nn.Module):
    def __init__(self, in_channels, out_channels):
        super().__init__()
        self.enc1 = DoubleConv(in_channels, 32); self.pool1 = nn.MaxPool2d(2)
        self.enc2 = DoubleConv(32, 64);          self.pool2 = nn.MaxPool2d(2)
        self.enc3 = DoubleConv(64, 128);         self.pool3 = nn.MaxPool2d(2)
        self.bottleneck = DoubleConv(128, 256)
        self.dropout = nn.Dropout2d(p=0.3)
        self.up3 = nn.ConvTranspose2d(256, 128, 2, stride=2)
        self.att3 = AttentionBlock(128, 128, 64);  self.dec3 = DoubleConv(256, 128)
        self.up2 = nn.ConvTranspose2d(128, 64, 2, stride=2)
        self.att2 = AttentionBlock(64, 64, 32);    self.dec2 = DoubleConv(128, 64)
        self.up1 = nn.ConvTranspose2d(64, 32, 2, stride=2)
        self.att1 = AttentionBlock(32, 32, 16);    self.dec1 = DoubleConv(64, 32)
        self.final_conv = nn.Conv2d(32, out_channels, 1)

    def forward(self, x):
        e1 = self.enc1(x)
        e2 = self.enc2(self.pool1(e1))
        e3 = self.enc3(self.pool2(e2))
        b = self.dropout(self.bottleneck(self.pool3(e3)))
        d3 = self.up3(b);  d3 = self.dec3(torch.cat([d3, self.att3(d3, e3)], 1))
        d2 = self.up2(d3); d2 = self.dec2(torch.cat([d2, self.att2(d2, e2)], 1))
        d1 = self.up1(d2); d1 = self.dec1(torch.cat([d1, self.att1(d1, e1)], 1))
        return self.final_conv(d1)


# ---------------------------------------------------------------------------
# State loaded once at start-up (read-only afterwards)
# ---------------------------------------------------------------------------
class State:
    pass


S = State()
_infer_lock = threading.Lock()
_cache_lock = threading.Lock()
_cache = OrderedDict()   # (intensity, duration) -> dict of maps


def load_everything():
    with open(os.path.join(MODEL_DIR, "unet_surrogate_config.json"), encoding="utf-8") as f:
        S.cfg = json.load(f)
    S.net = AttentionUNet(S.cfg["in_channels"], S.cfg["out_channels"])
    S.net.load_state_dict(torch.load(os.path.join(MODEL_DIR, "unet_surrogate.pth"), map_location="cpu"))
    S.net.eval()
    S.has_dv = S.cfg["out_channels"] >= 3
    S.dv_scale = float(S.cfg.get("dv_scale", 10.0))
    S.vehicles = S.cfg.get("vehicles") or DEFAULT_VEHICLES
    S.temperature = float(S.cfg["temperature"])
    S.threshold = float(S.cfg["threshold"])
    S.flood_depth = float(S.cfg["flood_depth_m"])

    d = np.load(os.path.join(DATA_DIR, "surrogate", "static_inputs.npz"))
    channels = [str(c) for c in d["channels"]]
    if channels != S.cfg["static_channels"]:
        raise RuntimeError(f"static_inputs.npz channels {channels} do not match the model {S.cfg['static_channels']}")
    if not np.allclose(d["stats_mean"], S.cfg["stats_mean"], rtol=1e-3, atol=1e-4):
        raise RuntimeError("static_inputs.npz was normalised differently from the model's training data - rebuild with 09")
    S.static = torch.from_numpy(d["static"].astype(np.float32))
    S.cn = d["cn"].astype(np.float32)
    S.H, S.W = S.cn.shape
    a, b, c, dd, e, f = d["transform"]
    S.T = (a, c, e, f)   # pixel width, west, pixel height (negative), north
    S.inner = d["split"] != 255   # grid border drains artificially in the simulator

    with rasterio.open(os.path.join(DATA_DIR, "aligned", "aligned_esa.tif")) as src:
        S.perm_water = src.read(1) == PERMANENT_WATER_CLASS
    S.show = S.inner & ~S.perm_water

    L = np.load(os.path.join(DASH_DIR, "layers.npz"))
    S.bcount = L["bcount"].astype(np.int64)
    S.subcounty = L["subcounty"]
    S.subcounty_names = [str(n) for n in L["subcounty_names"]]
    S.county = (S.subcounty > 0) & S.show
    S.cell_km2 = abs(a) * 111320.0 * math.cos(math.radians(f + e * S.H / 2)) * abs(e) * 110574.0 / 1e6

    with open(os.path.join(DASH_DIR, "facilities.json"), encoding="utf-8") as fh:
        S.facilities = json.load(fh)
    S.fac_cells = cells_of(np.array([p["lng"] for p in S.facilities]), np.array([p["lat"] for p in S.facilities]))
    with open(os.path.join(DASH_DIR, "places.json"), encoding="utf-8") as fh:
        S.places = json.load(fh)
    S.place_tree = cKDTree(to_metres(np.array([p["lng"] for p in S.places]), np.array([p["lat"] for p in S.places])))
    with open(os.path.join(DASH_DIR, "subcounties.geojson"), encoding="utf-8") as fh:
        S.subcounty_geojson = json.load(fh)

    g = np.load(os.path.join(DASH_DIR, "road_graph.npz"))
    S.g = {k: g[k] for k in g.files}
    S.n_nodes = len(S.g["node_xy"])
    main = np.flatnonzero(S.g["node_main"])
    S.node_tree = cKDTree(to_metres(S.g["node_xy"][main, 0], S.g["node_xy"][main, 1]))
    S.node_tree_ids = main
    cp = S.g["cell_ptr"]
    S.edge_ncells = np.diff(cp)
    S.edge_starts = cp[:-1]
    S.approach_exempt = bridge_approach_exemptions()
    build_zone_grid()
    torch.set_num_threads(max(1, os.cpu_count() or 1))


def build_zone_grid():
    """Assign every cell to a pointy-top hexagon (axial coordinates, metres) and store each hexagon's outline."""
    a, west, e, north = S.T
    rr, cc = np.mgrid[0:S.H, 0:S.W]
    xy = to_metres((west + (cc.ravel() + 0.5) * a), (north + (rr.ravel() + 0.5) * e))
    x, y = xy[:, 0] / ZONE_RADIUS_M, xy[:, 1] / ZONE_RADIUS_M
    q, r = math.sqrt(3) / 3 * x - y / 3, 2 / 3 * y
    s = -q - r
    rq, rr_, rs = np.round(q), np.round(r), np.round(s)
    dq, dr, ds = abs(rq - q), abs(rr_ - r), abs(rs - s)
    fix_q = (dq > dr) & (dq > ds)
    fix_r = ~fix_q & (dr > ds)
    rq = np.where(fix_q, -rr_ - rs, rq)
    rr_ = np.where(fix_r, -rq - rs, rr_)
    key = rq.astype(np.int64) * 1_000_000 + rr_.astype(np.int64)
    uniq, first, S.zone_id = np.unique(key, return_index=True, return_inverse=True)
    S.n_zones = len(uniq)
    S.zone_cells = np.bincount(S.zone_id, weights=S.show.ravel(), minlength=S.n_zones)
    zq, zr = rq[first], rr_[first]
    lat0 = S.T[3] + S.T[2] * S.H / 2
    kx = 111320.0 * math.cos(math.radians(lat0))
    ang = np.radians(30 + 60 * np.arange(7))   # closed ring
    cx = ZONE_RADIUS_M * math.sqrt(3) * (zq + zr / 2)
    cy = ZONE_RADIUS_M * 1.5 * zr
    S.zone_ring = np.stack([(cx[:, None] + ZONE_RADIUS_M * np.cos(ang)) / kx,
                            (cy[:, None] + ZONE_RADIUS_M * np.sin(ang)) / 110574.0], -1).round(5)


def bridge_approach_exemptions():
    """
    OSM tags only the bridge deck (median 33 m here), but a river's flood band at 30 m resolution is
    usually wider, so the approach segments either side of every bridge would read as flooded. For road
    segments that join a bridge or tunnel, ignore cells within BRIDGE_APPROACH_CELLS of it.
    Returns a boolean per (segment, cell) entry, aligned with cell_idx.
    """
    g = S.g
    bridge = g["edge_bridge"]
    ci = g["cell_idx"]
    edge_of_entry = np.repeat(np.arange(len(bridge)), S.edge_ncells)
    near = np.zeros(S.H * S.W, bool)
    near[ci[bridge[edge_of_entry]]] = True
    k = 2 * BRIDGE_APPROACH_CELLS + 1
    near = ndimage.binary_dilation(near.reshape(S.H, S.W), structure=np.ones((k, k), bool)).ravel()
    bridge_nodes = np.zeros(S.n_nodes, bool)
    bridge_nodes[g["edge_u"][bridge]] = True
    bridge_nodes[g["edge_v"][bridge]] = True
    approach = (bridge_nodes[g["edge_u"]] | bridge_nodes[g["edge_v"]]) & ~bridge
    return approach[edge_of_entry] & near[ci]


def to_metres(lng, lat):
    lat0 = S.T[3] + S.T[2] * S.H / 2
    return np.c_[np.asarray(lng) * 111320.0 * math.cos(math.radians(lat0)), np.asarray(lat) * 110574.0]


def cells_of(lng, lat):
    a, west, e, north = S.T
    col = np.floor((np.asarray(lng) - west) / a).astype(np.int64)
    row = np.floor((np.asarray(lat) - north) / e).astype(np.int64)
    ok = (row >= 0) & (row < S.H) & (col >= 0) & (col < S.W)
    return np.where(ok, row * S.W + col, -1)


def cell_center(idx):
    a, west, e, north = S.T
    r, c = divmod(int(idx), S.W)
    return north + (r + 0.5) * e, west + (c + 0.5) * a


# ---------------------------------------------------------------------------
# Inference
# ---------------------------------------------------------------------------
def normalise_storm(intensity, duration):
    notes = []
    i = float(np.clip(intensity, 0.0, 120.0))
    dh = float(np.clip(duration, 1.0, 6.0))
    if i != intensity:
        notes.append("Intensity limited to 0-120 mm/hr, the range of the simulated storms.")
    if dh != duration:
        notes.append("Duration limited to 1-6 h, the range of the simulated storms.")
    if 0 < i < 10:
        notes.append("Below 10 mm/hr (the lightest simulated storm) the model is extrapolating.")
    return round(i), round(dh * 4) / 4, notes


def scs_runoff_mm(p_mm):
    s = 25400.0 / S.cn - 254.0
    ia = 0.2 * s
    return np.where(p_mm > ia, (p_mm - ia) ** 2 / (p_mm + 0.8 * s), 0.0).astype(np.float32)


def storm_channels(i, dh, window=None):
    q = scs_runoff_mm(i * dh) / S.cfg["q_scale"]
    if window is not None:
        q = q[window]
    h, w = q.shape
    return torch.stack([torch.full((h, w), i / S.cfg["i_max"]), torch.full((h, w), dh / S.cfg["d_max"]),
                        torch.from_numpy(q)])


def run_net(x):
    with torch.inference_mode():
        return S.net(x[None]).float()[0].numpy()


def decode(out):
    prob = 1.0 / (1.0 + np.exp(-out[0] / S.temperature))
    depth = np.expm1(np.clip(out[1], 0, None))
    dv = np.expm1(np.clip(out[2], 0, None)) / S.dv_scale if S.has_dv else None
    return prob, depth, dv


def predict_full(i, dh):
    """Whole-grid prediction with overlapping tiles (as in the notebook)."""
    x = torch.cat([S.static, storm_channels(i, dh)], 0)
    T, M = int(S.cfg["infer_tile"]), int(S.cfg["infer_margin"])
    H, W = S.H, S.W
    out_full = np.zeros((S.cfg["out_channels"], H, W), np.float32)
    ys = list(range(0, H - T, T - 2 * M)) + [H - T]
    xs = list(range(0, W - T, T - 2 * M)) + [W - T]
    for y0 in ys:
        for x0 in xs:
            out = run_net(x[:, y0:y0 + T, x0:x0 + T])
            ya, yb = y0 + (M if y0 > 0 else 0), y0 + T - (M if y0 + T < H else 0)
            xa, xb = x0 + (M if x0 > 0 else 0), x0 + T - (M if x0 + T < W else 0)
            out_full[:, ya:yb, xa:xb] = out[:, ya - y0:yb - y0, xa - x0:xb - x0]
    prob, depth, dv = decode(out_full)
    prob = np.where(S.show, prob, 0.0).astype(np.float32)
    flooded = prob >= S.threshold
    return {
        "prob": prob,
        "depth": np.where(S.show, depth, 0.0).astype(np.float32),
        "dv": np.where(S.show, dv, 0.0).astype(np.float32) if dv is not None else None,
        "flooded": flooded,
    }


def cached_keys():
    with _cache_lock:
        return [list(k) for k in _cache.keys()]


def get_maps(i, dh):
    key = (i, dh)
    with _cache_lock:
        if key in _cache:
            _cache.move_to_end(key)
            return _cache[key]
    with _infer_lock:   # one heavy inference at a time; re-check in case another request just made it
        with _cache_lock:
            if key in _cache:
                return _cache[key]
        if i == 0:
            z = np.zeros((S.H, S.W), np.float32)
            maps = {"prob": z, "depth": z, "dv": z if S.has_dv else None, "flooded": z > 0}
        else:
            maps = predict_full(i, dh)
        maps["edges"] = {}
        with _cache_lock:
            _cache[key] = maps
            while len(_cache) > CACHE_STORMS:
                _cache.popitem(last=False)
    return maps


# ---------------------------------------------------------------------------
# Rendering
# ---------------------------------------------------------------------------
def render_png(maps):
    depth = np.clip(maps["depth"], RAMP_DEPTH[0], RAMP_DEPTH[-1])
    rgb = np.stack([np.interp(depth, RAMP_DEPTH, RAMP_RGB[:, k]) for k in range(3)], -1)
    p = maps["prob"]
    fringe = 0.3
    alpha = np.where(p >= S.threshold, 0.85, np.clip((p - fringe) / (S.threshold - fringe), 0, 1) * 0.45)
    alpha = np.where(S.show, alpha, 0.0)
    bgra = np.dstack([rgb[..., 2], rgb[..., 1], rgb[..., 0], alpha * 255]).astype(np.uint8)
    ok, buf = cv2.imencode(".png", bgra)
    return buf.tobytes()


def zone_stats(maps):
    """Per hexagon: flooded share, deepest band covering ZONE_MIN_SHARE of it, buildings exposed."""
    if "zones" in maps:
        return maps["zones"]
    show = S.show.ravel()
    fl = maps["flooded"].ravel() & show
    d = maps["depth"].ravel()
    count = lambda m: np.bincount(S.zone_id, weights=m, minlength=S.n_zones)
    n = np.maximum(S.zone_cells, 1)
    need = ZONE_MIN_SHARE * n
    nf = count(fl)
    level = np.full(S.n_zones, -1, np.int8)
    level[nf >= 1] = 0
    for k, thr in enumerate(RAMP_DEPTH[:4], start=1):   # 0.15 / 0.3 / 0.6 / 1.2 m
        level[count(fl & (d >= thr)) >= need] = k
    exposed = fl & (d > EXPOSURE_DEPTH_M)
    max_d = np.zeros(S.n_zones)
    np.maximum.at(max_d, S.zone_id[fl], d[fl])
    maps["zones"] = {
        "level": level,
        "flooded_pct": 100.0 * nf / n,
        "flooded_ha": nf * S.cell_km2 * 100,
        "max_depth": max_d,
        "buildings": count(np.where(exposed, S.bcount.ravel(), 0)),
    }
    return maps["zones"]


def zone_props(z, k):
    lv = int(z["level"][k])
    return {"level": ZONE_LEVELS[lv] if lv >= 0 else "none",
            "flooded_pct": round(float(z["flooded_pct"][k]), 1),
            "flooded_ha": round(float(z["flooded_ha"][k]), 1),
            "max_depth_m": round(float(z["max_depth"][k]), 2),
            "buildings_exposed": int(z["buildings"][k])}


def extent_polygons(maps):
    """
    Flood outlines for display. Each depth band (>= 0.15, 0.3, 0.6, 1.2 m) is blurred slightly (sigma 0.7 cells),
    upsampled to a 10 m grid and traced at the 0.5 level, which removes the 30 m staircase and isolated single
    cells. Rings are simplified to 15 m; the dashboard rounds the remaining corners (Chaikin) before drawing.
    Blurring keeps the bands nested (a deeper band's mask is inside the shallower one). Statistics never use this.
    """
    if "extent" in maps:
        return maps["extent"]
    a, west, e, north = S.T
    U = EXTENT_UPSAMPLE
    fl, d = maps["flooded"], maps["depth"]
    features = []
    for k, thr in enumerate(RAMP_DEPTH[:4]):
        m = (fl & (d >= thr)).astype(np.float32)
        if not m.any():
            continue
        field = cv2.resize(cv2.GaussianBlur(m, (0, 0), EXTENT_SIGMA_CELLS), (S.W * U, S.H * U), interpolation=cv2.INTER_CUBIC)
        contours, hier = cv2.findContours((field > 0.5).astype(np.uint8), cv2.RETR_CCOMP, cv2.CHAIN_APPROX_SIMPLE)
        if hier is None:
            continue
        hier = hier[0]
        # pixel corners -> centres of the upsampled grid -> lng/lat
        to_ll = lambda c: np.c_[west + (c[:, 0, 0] + 0.5) / U * a, north + (c[:, 0, 1] + 0.5) / U * e].round(5).tolist()
        eps = EXTENT_SIMPLIFY_M / 30.0 * U
        polys = []
        for j, c in enumerate(contours):
            if hier[j][3] != -1 or len(c) < 3:   # holes are added to their outer ring below
                continue
            c = cv2.approxPolyDP(c, eps, True)
            if len(c) < 3:
                continue
            rings = [to_ll(c)]
            h = hier[j][2]
            while h != -1:
                hc = cv2.approxPolyDP(contours[h], eps, True)
                if len(hc) >= 3:
                    rings.append(to_ll(hc))
                h = hier[h][0]
            for ring in rings:
                ring.append(ring[0])
            polys.append(rings)
        features.append({"type": "Feature", "geometry": {"type": "MultiPolygon", "coordinates": polys},
                         "properties": {"band": k, "min_depth_m": float(thr)}})
    maps["extent"] = {"type": "FeatureCollection", "features": features}
    return maps["extent"]


# ---------------------------------------------------------------------------
# Summaries
# ---------------------------------------------------------------------------
def nearest_place(lat, lng, max_m=2500.0):
    d, k = S.place_tree.query(to_metres([lng], [lat])[0])
    return S.places[k]["name"] if d <= max_m else None


def depth_label(m):
    return ">2 m" if m > 2 else f"{m:.2f} m"


def summarise(maps, i, dh):
    flooded, depth = maps["flooded"], maps["depth"]
    exposed = flooded & (depth > EXPOSURE_DEPTH_M)
    in_c = S.county

    subs = []
    for k, name in enumerate(S.subcounty_names, start=1):
        m = (S.subcounty == k) & S.show
        f = flooded & m
        n = int(m.sum())
        subs.append({
            "id": k, "name": name,
            "flooded_km2": round(float(f.sum()) * S.cell_km2, 2),
            "flooded_pct": round(100.0 * f.sum() / max(n, 1), 1),
            "deep_km2": round(float((f & (depth > 0.6)).sum()) * S.cell_km2, 2),
            "buildings_exposed": int(S.bcount[exposed & m].sum()),
            "p90_depth_m": round(float(np.percentile(depth[f], 90)), 2) if f.any() else 0.0,
        })
    subs.sort(key=lambda s: (s["buildings_exposed"], s["flooded_km2"]), reverse=True)

    # Hotspots: 1 km x 1 km squares (33 x 33 cells) inside the county, ranked by buildings in water
    # deeper than EXPOSURE_DEPTH_M, then by flooded area. Squares avoid one connected flood plain
    # swallowing a whole district.
    B = HOTSPOT_BLOCK
    hb, wb = S.H // B, S.W // B
    crop = (slice(0, hb * B), slice(0, wb * B))
    blk = lambda a: a[crop].reshape(hb, B, wb, B).sum(axis=(1, 3))
    ex_c = exposed & in_c
    b_build = blk(np.where(ex_c, S.bcount, 0))
    b_area = blk(ex_c.astype(np.int64))
    score = b_build * 1000 + b_area
    hotspots = []
    for flat in np.argsort(-score.ravel())[:15]:
        bi, bj = divmod(int(flat), wb)
        if b_area[bi, bj] < 4:
            break
        win = (slice(bi * B, (bi + 1) * B), slice(bj * B, (bj + 1) * B))
        m = ex_c[win]
        rr, cc = np.nonzero(m)
        r0, c0 = bi * B + int(rr.mean()), bj * B + int(cc.mean())
        lat, lng = cell_center(r0 * S.W + c0)
        sc = int(np.bincount(S.subcounty[win][m]).argmax())
        dvals = depth[win][m]
        p90 = float(np.percentile(dvals, 90))
        hotspots.append({
            "name": nearest_place(lat, lng) or (S.subcounty_names[sc - 1] if sc else "Unnamed area"),
            "subcounty": S.subcounty_names[sc - 1] if sc else None,
            "lat": round(lat, 5), "lng": round(lng, 5),
            "area_ha": round(int(b_area[bi, bj]) * S.cell_km2 * 100, 1),
            "p90_depth_m": round(p90, 2), "depth_label": depth_label(p90),
            "buildings_exposed": int(b_build[bi, bj]),
        })

    fac = []
    fc = S.fac_cells
    hit = np.flatnonzero((fc >= 0) & exposed.ravel()[np.maximum(fc, 0)])
    for j in hit:
        p = S.facilities[j]
        dj = float(depth.ravel()[fc[j]])
        fac.append({**p, "depth_m": round(dj, 2), "depth_label": depth_label(dj)})
    fac.sort(key=lambda p: -p["depth_m"])

    zl = zone_stats(maps)["level"]
    zone_counts = {name: int((zl == k).sum()) for k, name in enumerate(ZONE_LEVELS) if k >= 1}

    a, west, e, north = S.T
    return {
        "storm": {"intensity_mmhr": i, "duration_hr": dh, "rain_total_mm": i * dh},
        "zones": zone_counts,
        "overlay": {"bounds": [[north + e * S.H, west], [north, west + a * S.W]]},
        "summary": {
            "flooded_km2": round(float((flooded & in_c).sum()) * S.cell_km2, 1),
            "deep_km2": round(float((flooded & in_c & (depth > 0.6)).sum()) * S.cell_km2, 1),
            "county_km2": round(float(in_c.sum()) * S.cell_km2, 1),
            "buildings_exposed": int(S.bcount[exposed & in_c].sum()),
            "facilities_exposed": len(fac),
            "exposure_depth_m": EXPOSURE_DEPTH_M,
        },
        "subcounties": subs,
        "hotspots": hotspots,
        "facilities": fac[:60],
    }


def vehicle_verdicts(depth, dv, flooded):
    out = {}
    for key, v in S.vehicles.items():
        if not flooded:
            state = "safe"
        elif v["dv_m2s"] is None:
            state = "unsafe"
        else:
            too_deep = depth > v["depth_m"]
            too_fast = dv is not None and dv > v["dv_m2s"]
            state = "unsafe" if (too_deep or too_fast) else "caution"
        out[key] = state
    return out


# ---------------------------------------------------------------------------
# Routing
# ---------------------------------------------------------------------------
def edge_hazard(maps):
    """Per road segment: max flooded depth and DV along it (bridges/tunnels assumed above the water)."""
    if "max_depth" in maps["edges"]:
        return maps["edges"]
    fl = maps["flooded"].ravel()
    dep = np.where(fl, maps["depth"].ravel(), 0.0)
    ci = S.g["cell_idx"]
    empty = S.edge_ncells == 0
    bridge = S.g["edge_bridge"]

    def per_edge(vals):
        per_entry = np.where(S.approach_exempt, 0.0, vals[ci])
        # trailing 0 keeps every start index valid; segments with no cells are masked below
        v = np.maximum.reduceat(np.append(per_entry, 0.0), S.edge_starts)
        v[empty | bridge] = 0.0
        return v

    maps["edges"]["max_depth"] = per_edge(dep)
    maps["edges"]["any_flood"] = per_edge(fl.astype(np.float32)) > 0
    maps["edges"]["max_dv"] = per_edge(np.where(fl, maps["dv"].ravel(), 0.0)) if maps["dv"] is not None else None
    return maps["edges"]


def edge_states(hz, v):
    if v["dv_m2s"] is None:
        unsafe = hz["any_flood"].copy()
    else:
        unsafe = hz["max_depth"] > v["depth_m"]
        if hz["max_dv"] is not None:
            unsafe |= hz["max_dv"] > v["dv_m2s"]
    caution = hz["any_flood"] & ~unsafe
    return unsafe, caution


def directed_graph(weight, allowed):
    g = S.g
    u, v, ow = g["edge_u"], g["edge_v"], g["edge_oneway"]
    eid = np.arange(len(u))
    fwd = allowed & (ow != "T")
    bwd = allowed & (ow != "F")
    src = np.r_[u[fwd], v[bwd]]
    dst = np.r_[v[fwd], u[bwd]]
    w = np.r_[weight[fwd], weight[bwd]]
    ids = np.r_[eid[fwd], eid[bwd]]
    # keep the cheapest edge for each (src, dst) pair
    order = np.lexsort((w, dst, src))
    src, dst, w, ids = src[order], dst[order], w[order], ids[order]
    first = np.r_[True, (src[1:] != src[:-1]) | (dst[1:] != dst[:-1])]
    src, dst, w, ids = src[first], dst[first], w[first], ids[first]
    mat = csr_matrix((w, (src, dst)), shape=(S.n_nodes, S.n_nodes))
    lookup = csr_matrix((ids + 1, (src, dst)), shape=(S.n_nodes, S.n_nodes))
    return mat, lookup


def shortest_path(a, b, weight, allowed):
    mat, lookup = directed_graph(weight, allowed)
    dist, pred = dijkstra(mat, directed=True, indices=a, return_predecessors=True)
    if not np.isfinite(dist[b]):
        return None
    nodes = [b]
    while nodes[-1] != a:
        nodes.append(pred[nodes[-1]])
    nodes.reverse()
    return [(int(lookup[p, q]) - 1, p, q) for p, q in zip(nodes[:-1], nodes[1:])]


def path_geometry(path):
    g = S.g
    coords = []
    for e, p, q in path:
        seg = g["geom_xy"][g["geom_ptr"][e]:g["geom_ptr"][e + 1]]
        if g["edge_u"][e] != p:
            seg = seg[::-1]
        coords.extend(seg.tolist() if not coords else seg[1:].tolist())
    return coords


def path_stats(path, hz, unsafe, caution):
    g = S.g
    e = np.array([p[0] for p in path], dtype=np.int64)
    return {
        "distance_km": round(float(g["edge_len_m"][e].sum()) / 1000, 2),
        "duration_min": round(float(g["edge_time_s"][e].sum()) / 60, 1),
        "unsafe_segments": int(unsafe[e].sum()),
        "flooded_length_m": int(g["edge_len_m"][e][caution[e] | unsafe[e]].sum()),
        "max_depth_m": round(float(hz["max_depth"][e].max()), 2) if len(e) else 0.0,
    }


def snap(lat, lng):
    d, k = S.node_tree.query(to_metres([lng], [lat])[0])
    if d > MAX_SNAP_M:
        return None, d
    return int(S.node_tree_ids[k]), d


# ---------------------------------------------------------------------------
# API
# ---------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app):
    load_everything()
    print(f"[OK] U-Net surrogate loaded ({S.cfg['in_channels']} inputs, {S.cfg['out_channels']} outputs, "
          f"DV head: {S.has_dv}); grid {S.H}x{S.W}; {len(S.g['edge_u']):,} road segments")
    threading.Thread(target=get_maps, args=(30, 2.0), daemon=True).start()   # warm the dashboard default
    yield


app = FastAPI(title="Nairobi Flood DSS model server", lifespan=lifespan)
app.add_middleware(GZipMiddleware, minimum_size=2000)


@app.get("/health")
def health():
    m = S.cfg.get("metrics", {})
    return {
        "status": "ok",
        "model": {
            "inputs": S.cfg["static_channels"] + S.cfg["storm_channels"],
            "outputs": ["flood probability", "depth (m)"] + (["depth x velocity (m2/s)"] if S.has_dv else []),
            "threshold": S.threshold, "temperature": S.temperature,
            "test_iou": m.get("test_blocks_training_storms", {}).get("IoU"),
            "test_iou_unseen_storms": m.get("test_blocks_heldout_storms", {}).get("IoU"),
            "baselines": m.get("baselines"), "calibration": m.get("calibration"),
            "vehicle_metrics": m.get("vehicles"),
            "resolution_m": 30, "training_storms": "10-120 mm/hr, 1-6 h",
        },
        "dv_available": S.has_dv,
        "vehicles": S.vehicles,
        "cached_storms": cached_keys(),
    }


@app.get("/predict")
def predict(intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    i, dh, notes = normalise_storm(intensity, duration)
    res = summarise(get_maps(i, dh), i, dh)
    res["notes"] = notes
    res["dv_available"] = S.has_dv
    return res


@app.get("/overlay.png")
def overlay(intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    i, dh, _ = normalise_storm(intensity, duration)
    maps = get_maps(i, dh)
    if "png" not in maps:
        maps["png"] = render_png(maps)
    return Response(maps["png"], media_type="image/png", headers={"Cache-Control": "public, max-age=3600"})


@app.get("/zones")
def zones(intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    i, dh, _ = normalise_storm(intensity, duration)
    maps = get_maps(i, dh)
    if "zones_geojson" not in maps:
        z = zone_stats(maps)
        features = [{"type": "Feature", "geometry": {"type": "Polygon", "coordinates": [S.zone_ring[k].tolist()]},
                     "properties": zone_props(z, k)}
                    for k in np.flatnonzero(z["level"] >= 1)]   # minor zones (<10% flooded) are not drawn
        maps["zones_geojson"] = {
            "type": "FeatureCollection", "features": features,
            "properties": {"zone_area_ha": round(1.5 * math.sqrt(3) * ZONE_RADIUS_M ** 2 / 1e4, 1),
                           "min_share": ZONE_MIN_SHARE, "levels": ZONE_LEVELS},
        }
    return maps["zones_geojson"]


@app.get("/extent")
def extent(intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    i, dh, _ = normalise_storm(intensity, duration)
    return extent_polygons(get_maps(i, dh))


@app.get("/subcounties")
def subcounties():
    return S.subcounty_geojson


@app.get("/point")
def point(lat: float, lng: float, intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    i, dh, _ = normalise_storm(intensity, duration)
    c = int(cells_of([lng], [lat])[0])
    if c < 0 or not S.inner.ravel()[c]:
        raise HTTPException(404, "Location is outside the modelled area")
    maps = get_maps(i, dh)
    prob = float(maps["prob"].ravel()[c])
    depth = float(maps["depth"].ravel()[c])
    dv = float(maps["dv"].ravel()[c]) if maps["dv"] is not None else None
    flooded = bool(maps["flooded"].ravel()[c])
    sc = int(S.subcounty.ravel()[c])
    return {
        "lat": lat, "lng": lng,
        "place": nearest_place(lat, lng),
        "subcounty": S.subcounty_names[sc - 1] if sc else None,
        "permanent_water": bool(S.perm_water.ravel()[c]),
        "flood_probability": round(prob, 3),
        "flooded": flooded,
        "depth_m": round(depth, 2) if flooded else 0.0,
        "depth_label": depth_label(depth) if flooded else "dry",
        "dv_m2s": round(dv, 3) if (dv is not None and flooded) else (0.0 if dv is not None else None),
        "buildings_in_cell": int(S.bcount.ravel()[c]),
        "vehicles": vehicle_verdicts(depth, dv, flooded),
        "zone": zone_props(zone_stats(maps), int(S.zone_id[c])),
    }


@app.get("/explain")
def explain(lat: float, lng: float, intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    """
    Ablation (occlusion) attribution: re-run the network on a 256x256 window around the point with
    one group of inputs replaced by a reference value, and report how much the prediction at the point
    changes. Terrain/land-cover groups are replaced by their training average (0 after normalisation);
    rainfall by the lightest simulated storm (10 mm/hr for 1 h).
    """
    i, dh, _ = normalise_storm(intensity, duration)
    c = int(cells_of([lng], [lat])[0])
    if c < 0 or not S.inner.ravel()[c]:
        raise HTTPException(404, "Location is outside the modelled area")
    r, col = divmod(c, S.W)
    half = EXPLAIN_CROP // 2
    y0 = int(np.clip(r - half, 0, S.H - EXPLAIN_CROP))
    x0 = int(np.clip(col - half, 0, S.W - EXPLAIN_CROP))
    win = (slice(y0, y0 + EXPLAIN_CROP), slice(x0, x0 + EXPLAIN_CROP))
    ry, rx = r - y0, col - x0

    static = S.static[:, win[0], win[1]]
    rain = storm_channels(i, dh, window=win)
    rain_ref = storm_channels(10.0, 1.0, window=win)
    ch = S.cfg["static_channels"]
    groups = {
        "Rainfall (this storm)": None,
        "Terrain shape (TPI, slope, depressions)": ["tpi", "slope", "sink_depth"],
        "Drainage position (HAND, upstream area)": ["hand", "log_acc"],
        "Land cover runoff (curve number)": ["cn"],
        "Buildings (coverage)": ["bldg_frac"],
    }

    def at_point(x):
        out = run_net(x)
        prob = 1.0 / (1.0 + math.exp(-out[0, ry, rx] / S.temperature))
        return prob, float(np.expm1(max(out[1, ry, rx], 0.0)))

    with _infer_lock:
        base_p, base_d = at_point(torch.cat([static, rain], 0))
        contrib = []
        for name, chans in groups.items():
            if chans is None:
                x = torch.cat([static, rain_ref], 0)
            else:
                s2 = static.clone()
                for cname in chans:
                    if cname in ch:
                        s2[ch.index(cname)] = 0.0
                x = torch.cat([s2, rain], 0)
            p, dpt = at_point(x)
            contrib.append({"group": name, "delta_probability": round(base_p - p, 3),
                            "delta_depth_m": round(base_d - dpt, 2)})
    contrib.sort(key=lambda g: -abs(g["delta_probability"]))
    return {
        "lat": lat, "lng": lng, "flood_probability": round(base_p, 3), "depth_m": round(base_d, 2),
        "method": "Ablation: change in the U-Net output when one input group is replaced by a reference "
                  "(training average for terrain/land cover/buildings; a 10 mm/hr x 1 h storm for rainfall). "
                  "Positive = this input raises the flood prediction here.",
        "contributions": contrib,
    }


@app.get("/route")
def route(start_lat: float, start_lng: float, end_lat: float, end_lng: float,
          vehicle: str = Query("saloon"),
          intensity: float = Query(..., ge=0), duration: float = Query(..., gt=0)):
    if vehicle not in S.vehicles:
        raise HTTPException(400, f"Unknown vehicle '{vehicle}'. Options: {list(S.vehicles)}")
    v = S.vehicles[vehicle]
    i, dh, notes = normalise_storm(intensity, duration)
    a, da = snap(start_lat, start_lng)
    b, db = snap(end_lat, end_lng)
    if a is None or b is None:
        raise HTTPException(400, "Start or destination is more than 1.5 km from any mapped road")
    if a == b:
        raise HTTPException(400, "Start and destination are at the same place")

    maps = get_maps(i, dh)
    hz = edge_hazard(maps)
    unsafe, caution = edge_states(hz, v)
    t = S.g["edge_time_s"].astype(np.float64)
    all_edges = np.ones(len(t), dtype=bool)

    direct = shortest_path(a, b, t, all_edges)
    if direct is None:
        raise HTTPException(404, "No road connection between these points")
    safe = shortest_path(a, b, np.where(caution, t * CAUTION_TIME_FACTOR, t), ~unsafe)

    d_stats = path_stats(direct, hz, unsafe, caution)
    features = []
    advisory = None
    if safe is None:
        status = "no_safe_route"
        advisory = (f"No route avoids water that is unsafe for this vehicle ({v['label']}). Do not travel "
                    "until the water recedes, or use the direct route only if conditions on the ground allow.")
    else:
        s_stats = path_stats(safe, hz, unsafe, caution)
        status = "clear" if d_stats["unsafe_segments"] == 0 else "detour"
        if status == "detour" and s_stats["duration_min"] > LONG_DETOUR_RATIO * d_stats["duration_min"]:
            advisory = (f"The safe route takes {s_stats['duration_min']:.0f} min instead of "
                        f"{d_stats['duration_min']:.0f} min. Consider waiting for the water to recede.")
        features.append({"type": "Feature",
                         "geometry": {"type": "LineString", "coordinates": path_geometry(safe)},
                         "properties": {"route_type": "safe", **s_stats}})
    if status != "clear":
        features.append({"type": "Feature",
                         "geometry": {"type": "LineString", "coordinates": path_geometry(direct)},
                         "properties": {"route_type": "direct", **d_stats}})
        for e, p, q in direct:
            if unsafe[e]:
                features.append({"type": "Feature",
                                 "geometry": {"type": "LineString", "coordinates": path_geometry([(e, p, q)])},
                                 "properties": {"route_type": "hazard",
                                                "road": str(S.g["edge_name"][e]) or str(S.g["edge_class"][e]),
                                                "max_depth_m": round(float(hz["max_depth"][e]), 2),
                                                "max_dv_m2s": round(float(hz["max_dv"][e]), 2) if hz["max_dv"] is not None else None}})
    if not S.has_dv and v["dv_m2s"] is not None:
        notes.append("Water speed is not available in this model version: only the depth limit is applied.")
    return {
        "type": "FeatureCollection",
        "features": features,
        "properties": {
            "status": status, "vehicle": vehicle, "vehicle_label": v["label"],
            "limits": {"depth_m": v["depth_m"], "dv_m2s": v["dv_m2s"], "basis": v["basis"]},
            "snap_m": [round(da), round(db)], "storm": {"intensity_mmhr": i, "duration_hr": dh},
            "direct": d_stats, "notes": notes, "advisory": advisory,
            "assumptions": "Bridges and tunnels, and their approaches within about 60 m, are treated as above "
                           f"the water. Flooded but passable roads cost {CAUTION_TIME_FACTOR:g}x their normal "
                           "travel time. Speeds are free-flow estimates (traffic is not modelled).",
        },
    }
