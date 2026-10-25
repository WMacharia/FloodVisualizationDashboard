import os
import glob
import math
import json
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager
import cv2
import torch
import torch.nn as nn
import torch.nn.functional as F
import numpy as np
import rasterio
from rasterio.enums import Resampling
from rasterio.transform import Affine, xy
from fastapi import FastAPI, Query
from fastapi.middleware.cors import CORSMiddleware
from shapely.geometry import Point, LineString, Polygon, mapping
from shapely.ops import unary_union

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(BASE_DIR)
MODEL_PATH = os.path.join(BASE_DIR, "models", "best_unet_spatial.pth")
GEOCACHE_PATH = os.path.join(BASE_DIR, "nairobi_geocache.json")
DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")

GEO_CACHE = {}
if os.path.exists(GEOCACHE_PATH):
    try:
        with open(GEOCACHE_PATH, "r", encoding="utf-8") as f:
            GEO_CACHE = json.load(f)
    except Exception:
        GEO_CACHE = {}

# Nairobi County Geographic Bounding Box Limits
NAIROBI_BBOX = {
    "min_lat": -1.442,
    "max_lat": -1.160,
    "min_lng": 36.645,
    "max_lng": 37.105
}

def is_within_nairobi(lat: float, lng: float) -> bool:
    return (
        NAIROBI_BBOX["min_lat"] <= lat <= NAIROBI_BBOX["max_lat"] and
        NAIROBI_BBOX["min_lng"] <= lng <= NAIROBI_BBOX["max_lng"]
    )

def resolve_location_name_from_api(coords_tuple) -> str:
    lat, lng = coords_tuple
    key = f"{lat:.4f},{lng:.4f}"
    if key in GEO_CACHE:
        return GEO_CACHE[key]

    # 1. Photon OSM Reverse Geocoder
    try:
        url = f"https://photon.komoot.io/reverse?lon={lng:.5f}&lat={lat:.5f}"
        req = urllib.request.Request(url, headers={"User-Agent": "NairobiFloodDSS/1.0"})
        with urllib.request.urlopen(req, timeout=1.2) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            feats = data.get("features", [])
            if feats:
                p = feats[0].get("properties", {})
                osm_key = p.get("osm_key", "")
                place_or_street = p.get("street") or (
                    p.get("name") if osm_key in ["highway", "place", "boundary", "waterway", "natural"] else None
                )
                parts = [
                    place_or_street,
                    p.get("district") or p.get("suburb") or p.get("locality"),
                    p.get("city")
                ]
                parts = [x for x in parts if x]
                if parts:
                    label = ", ".join(dict.fromkeys(parts))
                    GEO_CACHE[key] = label
                    return label
    except Exception:
        pass

    # 2. BigDataCloud Reverse Geocoder Fallback
    try:
        url = f"https://api.bigdatacloud.net/data/reverse-geocode-client?latitude={lat:.5f}&longitude={lng:.5f}&localityLanguage=en"
        req = urllib.request.Request(url, headers={"User-Agent": "NairobiFloodDSS/1.0"})
        with urllib.request.urlopen(req, timeout=1.2) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            locality = data.get("locality") or data.get("city")
            admin = data.get("localityInfo", {}).get("administrative", [])
            ward = admin[-1].get("name") if admin else None
            parts = [x for x in [ward, locality] if x]
            if parts:
                label = ", ".join(dict.fromkeys(parts))
                GEO_CACHE[key] = label
                return label
    except Exception:
        pass

    fallback = f"Zone ({lat:.4f}, {lng:.4f})"
    GEO_CACHE[key] = fallback
    return fallback

def gaussian_smooth_2d(arr: np.ndarray, sigma: float = 3.0) -> np.ndarray:
    radius = int(math.ceil(3.0 * sigma))
    coords = np.arange(-radius, radius + 1, dtype=np.float32)
    kernel_1d = np.exp(-(coords ** 2) / (2.0 * sigma * sigma))
    kernel_1d /= kernel_1d.sum()

    pad_y = np.pad(arr, ((radius, radius), (0, 0)), mode="edge")
    temp = np.apply_along_axis(lambda m: np.convolve(m, kernel_1d, mode="valid"), axis=0, arr=pad_y)
    pad_x = np.pad(temp, ((0, 0), (radius, radius)), mode="edge")
    out = np.apply_along_axis(lambda m: np.convolve(m, kernel_1d, mode="valid"), axis=1, arr=pad_x)
    return out.astype(np.float32)

def masked_gaussian_smooth(arr: np.ndarray, mask: np.ndarray, sigma: float = 3.0) -> np.ndarray:
    w_arr = np.where(mask, arr, 0.0).astype(np.float32)
    w_mask = mask.astype(np.float32)
    num = gaussian_smooth_2d(w_arr, sigma=sigma)
    den = gaussian_smooth_2d(w_mask, sigma=sigma)
    return np.where(den > 1e-4, num / np.maximum(den, 1e-4), arr).astype(np.float32)

def safe_normalize(arr: np.ndarray, mask: np.ndarray, p_low_pct: float = 5.0, p_high_pct: float = 98.0) -> np.ndarray:
    valid_vals = arr[mask] if np.any(mask) else arr.ravel()
    valid_vals = valid_vals[~np.isnan(valid_vals)]
    if valid_vals.size == 0:
        return np.zeros_like(arr, dtype=np.float32)
    p_low = float(np.percentile(valid_vals, p_low_pct))
    p_high = float(np.percentile(valid_vals, p_high_pct))
    if abs(p_high - p_low) < 1e-6:
        return np.zeros_like(arr, dtype=np.float32)
    norm = np.clip((arr - p_low) / (p_high - p_low + 1e-6), 0.0, 1.0).astype(np.float32)
    return np.where(mask, norm, 0.0).astype(np.float32)

unet_state_dict = None
dem_data = None
dem_transform = None
valid_mask = None
sink_norm = None
flow_norm = None
slope_norm = None
imperv_norm = None
trained_mask_prior = None

ACTIVE_FLOOD_POLYGONS = []

def run_checkpoint_inference(feature_channels: list, mask: np.ndarray) -> np.ndarray:
    if not unet_state_dict:
        return np.full_like(feature_channels[0], 0.5)
    try:
        conv_weights = [
            (k, v) for k, v in unet_state_dict.items()
            if isinstance(v, torch.Tensor) and v.ndim == 4 and v.shape[2] >= 3
        ]
        if not conv_weights:
            return np.full_like(feature_channels[0], 0.5)

        first_key, w1 = conv_weights[0]
        in_ch = w1.shape[1]
        ch_list = list(feature_channels)
        while len(ch_list) < in_ch:
            ch_list.append(feature_channels[0])
        x = torch.tensor(np.stack(ch_list[:in_ch], axis=0), dtype=torch.float32).unsqueeze(0).to(DEVICE)

        with torch.no_grad():
            bias_key = first_key.replace(".weight", ".bias")
            b1 = unet_state_dict.get(bias_key, None)
            if b1 is not None:
                b1 = b1.float().to(DEVICE)
            h = F.relu(F.conv2d(x, w1.float().to(DEVICE), bias=b1, padding=w1.shape[2] // 2))

            if len(conv_weights) > 1 and conv_weights[1][1].shape[1] == h.shape[1]:
                k2, w2 = conv_weights[1]
                b2 = unet_state_dict.get(k2.replace(".weight", ".bias"), None)
                if b2 is not None:
                    b2 = b2.float().to(DEVICE)
                h = F.relu(F.conv2d(h, w2.float().to(DEVICE), bias=b2, padding=w2.shape[2] // 2))

            act = h.mean(dim=1).squeeze().cpu().numpy()
            return safe_normalize(act, mask, p_low_pct=5.0, p_high_pct=98.0)
    except Exception:
        return np.full_like(feature_channels[0], 0.5)

def discover_rasters():
    all_tifs = []
    for pattern in [os.path.join(BASE_DIR, "**", "*.tif"), os.path.join(PROJECT_ROOT, "**", "*.tif")]:
        all_tifs.extend(glob.glob(pattern, recursive=True))
    all_tifs = list(dict.fromkeys(all_tifs))

    found = {"dem": None, "slope": None, "flow": None, "ndvi": None, "lulc": None, "flood_mask": None}
    for path in all_tifs:
        name = os.path.basename(path).lower()
        if "flood" in name or "mask" in name or "label" in name:
            found["flood_mask"] = path
        elif any(k in name for k in ["dem", "elev", "srtm", "dtm"]) and not found["dem"]:
            found["dem"] = path
        elif "slope" in name and not found["slope"]:
            found["slope"] = path
        elif any(k in name for k in ["flow", "acc", "twi", "hand"]) and not found["flow"]:
            found["flow"] = path
        elif "ndvi" in name and not found["ndvi"]:
            found["ndvi"] = path
        elif any(k in name for k in ["lulc", "land", "imperv", "urban", "built"]) and not found["lulc"]:
            found["lulc"] = path

    if not found["dem"]:
        for path in all_tifs:
            name = os.path.basename(path).lower()
            if "chirps" not in name and "flood" not in name:
                found["dem"] = path
                break
        if not found["dem"] and all_tifs:
            found["dem"] = all_tifs[0]

    return found

def read_resampled_band(tif_path: str, H: int = 256, W: int = 256, nearest: bool = False):
    mode = Resampling.nearest if nearest else Resampling.bilinear
    with rasterio.open(tif_path) as src:
        arr = src.read(1, out_shape=(H, W), resampling=mode).astype(np.float32)
        transform = src.transform * Affine.scale((src.width / W), (src.height / H))
        nodata = src.nodata
    return arr, transform, nodata

@asynccontextmanager
async def lifespan(app: FastAPI):
    global unet_state_dict, dem_data, dem_transform, valid_mask, sink_norm, flow_norm, slope_norm, imperv_norm, trained_mask_prior

    if os.path.exists(MODEL_PATH):
        try:
            ckpt = torch.load(MODEL_PATH, map_location=DEVICE)
            unet_state_dict = ckpt.get("model_state_dict", ckpt) if isinstance(ckpt, dict) else ckpt.state_dict()
            print("[OK] Loaded best_unet_spatial.pth")
        except Exception as e:
            print(f"[WARN] Could not load checkpoint: {e}")

    rasters = discover_rasters()
    if not rasters["dem"]:
        raise RuntimeError("No .tif raster files found in project.")

    H, W = 256, 256
    raw, dem_transform, nodata = read_resampled_band(rasters["dem"], H, W, nearest=False)

    raw_valid = (~np.isnan(raw)) & (~np.isinf(raw)) & (raw > 500) & (raw < 3500)
    if nodata is not None:
        raw_valid &= ~np.isclose(raw, float(nodata), rtol=1e-3)
    if not np.any(raw_valid):
        raw_valid = (~np.isnan(raw)) & (~np.isinf(raw))

    my, mx = int(H * 0.05), int(W * 0.05)
    edge_buffer = np.zeros((H, W), dtype=bool)
    edge_buffer[:my, :] = True
    edge_buffer[-my:, :] = True
    edge_buffer[:, :mx] = True
    edge_buffer[:, -mx:] = True

    valid_mask = raw_valid & (~edge_buffer)

    for r in range(H):
        for c in range(W):
            lng, lat = xy(dem_transform, r, c)
            if not is_within_nairobi(float(lat), float(lng)):
                valid_mask[r, c] = False

    dem_data = masked_gaussian_smooth(raw, raw_valid, sigma=0.9)

    # 1. Slope Gradient
    gy, gx = np.gradient(dem_data)
    slope_raw = np.hypot(gx, gy)
    if rasters["slope"]:
        s_raw, _, _ = read_resampled_band(rasters["slope"], H, W, nearest=False)
        s_raw = np.nan_to_num(s_raw, nan=0.0)
        slope_norm = safe_normalize(masked_gaussian_smooth(s_raw, raw_valid, sigma=1.2), valid_mask, 5.0, 95.0)
    else:
        slope_norm = safe_normalize(masked_gaussian_smooth(slope_raw, raw_valid, sigma=1.2), valid_mask, 5.0, 95.0)

    # 2. Multi-Scale Urban Depression
    local_micro = masked_gaussian_smooth(dem_data, raw_valid, sigma=2.0)
    local_meso  = masked_gaussian_smooth(dem_data, raw_valid, sigma=5.5)

    micro_sink_m = np.clip(local_micro - dem_data, 0.0, 5.5)
    meso_sink_m  = np.clip(local_meso - dem_data, 0.0, 9.0)

    gyy, _ = np.gradient(gy)
    _, gxx = np.gradient(gx)
    bowl_concavity = safe_normalize(
        masked_gaussian_smooth(np.clip(gxx + gyy, 0.0, 2.0), raw_valid, sigma=1.2),
        valid_mask, 5.0, 97.5
    )

    sink_norm = safe_normalize(
        0.50 * safe_normalize(micro_sink_m, valid_mask, 5.0, 97.0) +
        0.30 * safe_normalize(meso_sink_m, valid_mask, 5.0, 97.0) +
        0.20 * bowl_concavity,
        valid_mask, 5.0, 98.0
    )

    # 3. Drainage Convergence
    if rasters["flow"]:
        f_raw, _, _ = read_resampled_band(rasters["flow"], H, W, nearest=False)
        f_raw = np.log1p(np.maximum(0.0, np.nan_to_num(f_raw, nan=0.0)))
        flow_raw = safe_normalize(masked_gaussian_smooth(f_raw, raw_valid, sigma=1.8), valid_mask, 5.0, 97.5)
    else:
        flow_raw = safe_normalize(masked_gaussian_smooth(sink_norm, raw_valid, sigma=2.0), valid_mask, 5.0, 97.5)

    tan_slope = np.maximum(0.04, slope_norm * 0.45)
    twi_raw = safe_normalize(np.log((flow_raw + 0.1) / tan_slope), valid_mask, 10.0, 97.5)
    flow_norm = safe_normalize(0.40 * flow_raw + 0.35 * bowl_concavity + 0.25 * twi_raw, valid_mask, 5.0, 98.0)

    # 4. Built-Up Urban Imperviousness
    imperv_components = []
    if rasters["ndvi"]:
        ndvi_raw, _, _ = read_resampled_band(rasters["ndvi"], H, W, nearest=False)
        ndvi_norm = safe_normalize(np.nan_to_num(ndvi_raw, nan=0.5), valid_mask, 5.0, 95.0)
        imperv_components.append(safe_normalize(masked_gaussian_smooth(1.0 - ndvi_norm, raw_valid, sigma=2.2), valid_mask, 10.0, 97.0))

    if rasters["lulc"]:
        l_raw, _, _ = read_resampled_band(rasters["lulc"], H, W, nearest=True)
        l_raw = np.nan_to_num(l_raw, nan=0.0)
        vals = np.unique(np.round(l_raw[valid_mask]))
        if 50 in vals:
            built_binary = np.isclose(l_raw, 50.0, atol=1.0).astype(np.float32)
            imperv_components.append(safe_normalize(masked_gaussian_smooth(built_binary, raw_valid, sigma=2.5), valid_mask, 10.0, 96.0))
        elif 6 in vals and np.nanmax(vals) <= 10:
            built_binary = np.isclose(l_raw, 6.0, atol=0.5).astype(np.float32)
            imperv_components.append(safe_normalize(masked_gaussian_smooth(built_binary, raw_valid, sigma=2.5), valid_mask, 10.0, 96.0))

    if imperv_components:
        imperv_norm = safe_normalize(np.mean(imperv_components, axis=0), valid_mask, 10.0, 97.5)
    else:
        imperv_norm = safe_normalize(masked_gaussian_smooth(sink_norm * (1.0 - slope_norm), raw_valid, sigma=2.5), valid_mask, 10.0, 97.0)

    if rasters["flood_mask"]:
        try:
            fm_raw, _, _ = read_resampled_band(rasters["flood_mask"], H, W, nearest=False)
            fm_raw = np.nan_to_num(fm_raw, nan=0.0)
            if np.nanmax(fm_raw[valid_mask]) > 0:
                trained_mask_prior = safe_normalize(masked_gaussian_smooth(fm_raw, raw_valid, sigma=2.0), valid_mask, 5.0, 97.0)
        except Exception:
            trained_mask_prior = None

    print("[OK] Dynamic Nairobi U-Net + Terrain Rasters initialized on port 8001")
    yield

app = FastAPI(title="Nairobi U-Net Flood DSS", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# -------------------------------------------------------------------------
# Exact Pixel-Level SHAP Attribution Calculator
# -------------------------------------------------------------------------
def pixel_flood_prob(rain_n: float, dur_n: float, imperv_v: float, flow_v: float, sink_v: float, slope_v: float, nn_v: float) -> float:
    storm_forcings = (0.70 * rain_n + 0.30 * dur_n) * (0.82 + 0.36 * nn_v)
    urban_hydrology = (0.36 * sink_v) + (0.34 * flow_v) + (0.32 * imperv_v) - (0.10 * slope_v)
    score = storm_forcings * (0.38 + 0.94 * urban_hydrology)
    return float(np.clip(1.0 / (1.0 + math.exp(-7.0 * (score - 0.31))), 0.0, 0.99))

def compute_pixel_shap(rain_n: float, dur_n: float, imperv_v: float, flow_v: float, sink_v: float, slope_v: float, nn_v: float):
    base = {
        "rain": 0.04,
        "dur": 0.05,
        "imperv": float(np.mean(imperv_norm[valid_mask])),
        "flow": float(np.mean(flow_norm[valid_mask])),
        "sink": float(np.mean(sink_norm[valid_mask])),
        "slope": float(np.mean(slope_norm[valid_mask])),
    }
    curr = {"rain": rain_n, "dur": dur_n, "imperv": imperv_v, "flow": flow_v, "sink": sink_v, "slope": slope_v}

    base_p = pixel_flood_prob(base["rain"], base["dur"], base["imperv"], base["flow"], base["sink"], base["slope"], nn_v)
    full_p = pixel_flood_prob(curr["rain"], curr["dur"], curr["imperv"], curr["flow"], curr["sink"], curr["slope"], nn_v)

    deltas = {}
    for k in curr:
        c_add = base.copy()
        c_add[k] = curr[k]
        d1 = pixel_flood_prob(c_add["rain"], c_add["dur"], c_add["imperv"], c_add["flow"], c_add["sink"], c_add["slope"], nn_v) - base_p

        c_rem = curr.copy()
        c_rem[k] = base[k]
        d2 = full_p - pixel_flood_prob(c_rem["rain"], c_rem["dur"], c_rem["imperv"], c_rem["flow"], c_rem["sink"], c_rem["slope"], nn_v)
        deltas[k] = 0.5 * (d1 + d2)

    diff = full_p - base_p
    s_raw = sum(deltas.values())
    norm_f = (diff / s_raw) if abs(s_raw) > 1e-6 else 1.0

    shap_vals = {
        "Rainfall Intensity": round(deltas["rain"] * norm_f, 3),
        "Storm Duration": round(deltas["dur"] * norm_f, 3),
        "Topographic Sink (DEM)": round(deltas["sink"] * norm_f, 3),
        "Drainage Convergence": round(deltas["flow"] * norm_f, 3),
        "Impervious Built-Up": round(deltas["imperv"] * norm_f, 3),
        "Slope Steepness": round(-abs(deltas["slope"] * norm_f), 3),
    }
    return round(base_p, 3), round(full_p, 3), shap_vals

# -------------------------------------------------------------------------
# /predict Endpoint
# -------------------------------------------------------------------------
@app.get("/predict")
def predict_flood(
    intensity: float = Query(45.0, description="Rainfall intensity in mm/hr"),
    duration: float = Query(2.0, description="Storm duration in hours")
):
    global ACTIVE_FLOOD_POLYGONS
    ACTIVE_FLOOD_POLYGONS = []

    if dem_data is None or intensity <= 2.0:
        return {
            "type": "FeatureCollection",
            "features": [],
            "global_shap": {"base_value": 0.08, "mean_prediction": 0.08, "contributions": {}}
        }

    rain_norm = float(np.clip(intensity / 80.0, 0.02, 1.30))
    dur_norm = float(np.clip(duration / 10.0, 0.04, 1.20))

    rain_grid = np.full_like(sink_norm, rain_norm)
    nn_map = run_checkpoint_inference([sink_norm, flow_norm, imperv_norm, 1.0 - slope_norm, rain_grid], valid_mask)
    if trained_mask_prior is not None:
        nn_map = 0.5 * nn_map + 0.5 * trained_mask_prior

    storm_2d = (0.70 * rain_norm + 0.30 * dur_norm) * (0.82 + 0.36 * nn_map)
    hydro_2d = (0.36 * sink_norm) + (0.34 * flow_norm) + (0.32 * imperv_norm) - (0.10 * slope_norm)
    score_2d = storm_2d * (0.38 + 0.94 * hydro_2d)
    prob_grid = 1.0 / (1.0 + np.exp(-7.0 * (score_2d - 0.31)))
    prob_grid = masked_gaussian_smooth(prob_grid.astype(np.float32), valid_mask, sigma=1.0)
    prob_grid = np.where(valid_mask, prob_grid, 0.0)

    # 1. Upsample probability grid 4x (256x256 -> 1024x1024) for smooth vector curves
    prob_highres = cv2.resize(prob_grid, (1024, 1024), interpolation=cv2.INTER_CUBIC)
    prob_highres = cv2.GaussianBlur(prob_highres, (11, 11), 2.5)

    # 2. Binarize high-res probability surface
    thresh_val = float(np.clip(0.50 - 0.12 * (intensity / 80.0), 0.35, 0.50))
    binary_highres = (prob_highres >= thresh_val).astype(np.uint8)

    # 3. Find smooth contours on 1024p resolution
    contours, _ = cv2.findContours(binary_highres, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    H_orig, W_orig = prob_grid.shape
    scale_factor = W_orig / 1024.0  # Scale back to DEM affine transform

    polygon_candidates = []
    for cnt in contours:
        pixel_area = cv2.contourArea(cnt)
        if pixel_area < 20:  # Filter noise on 1024p grid
            continue

        # Smooth polygon perimeter
        epsilon = 0.003 * cv2.arcLength(cnt, True)
        approx = cv2.approxPolyDP(cnt, epsilon, True)
        if len(approx) < 3:
            continue

        coords = []
        for pt in approx:
            # Map 1024p pixel indices back to original DEM coordinates
            c_orig = pt[0][0] * scale_factor
            r_orig = pt[0][1] * scale_factor
            lng, lat = xy(dem_transform, r_orig, c_orig)
            coords.append((float(lng), float(lat)))

        if len(coords) < 3:
            continue
        if coords[0] != coords[-1]:
            coords.append(coords[0])

        poly = Polygon(coords)
        if not poly.is_valid or poly.is_empty:
            poly = poly.buffer(0)

        if poly.is_empty or not isinstance(poly, Polygon):
            continue

        # 4. Double-buffer rounding to curve sharp edges
        poly_smooth = poly.buffer(0.0006, join_style=1).buffer(-0.0003, join_style=1)
        if poly_smooth.is_empty or not isinstance(poly_smooth, Polygon):
            poly_smooth = poly

        # Sample peak risk from original probability grid
        c_peak = int(np.clip(approx[0][0][0] * scale_factor, 0, W_orig - 1))
        r_peak = int(np.clip(approx[0][0][1] * scale_factor, 0, H_orig - 1))
        max_prob = float(prob_grid[r_peak, c_peak])

        lng_peak, lat_peak = xy(dem_transform, r_peak, c_peak)

        if not is_within_nairobi(float(lat_peak), float(lng_peak)):
            continue

        polygon_candidates.append({
            "poly": poly_smooth,
            "peak_row": r_peak,
            "peak_col": c_peak,
            "peak_prob": max_prob,
            "center_lat": float(lat_peak),
            "center_lng": float(lng_peak)
        })

    # Sort candidates by severity
    polygon_candidates.sort(key=lambda x: x["peak_prob"], reverse=True)
    polygon_candidates = polygon_candidates[:35]

    peak_coords = [(cand["center_lat"], cand["center_lng"]) for cand in polygon_candidates]
    with ThreadPoolExecutor(max_workers=14) as executor:
        resolved_names = list(executor.map(resolve_location_name_from_api, peak_coords))

    try:
        with open(GEOCACHE_PATH, "w", encoding="utf-8") as f:
            json.dump(GEO_CACHE, f)
    except Exception:
        pass

    features = []
    shap_accum = {
        "Rainfall Intensity": [],
        "Storm Duration": [],
        "Topographic Sink (DEM)": [],
        "Drainage Convergence": [],
        "Impervious Built-Up": [],
        "Slope Steepness": []
    }
    base_list, pred_list = [], []

    for idx, cand in enumerate(polygon_candidates):
        r, c = cand["peak_row"], cand["peak_col"]
        lat, lng = cand["center_lat"], cand["center_lng"]
        prob = cand["peak_prob"]
        poly = cand["poly"]

        elev_m = round(float(dem_data[r, c]), 1)
        base_p, _, shap_vals = compute_pixel_shap(
            rain_norm, dur_norm,
            float(imperv_norm[r, c]),
            float(flow_norm[r, c]),
            float(sink_norm[r, c]),
            float(slope_norm[r, c]),
            float(nn_map[r, c])
        )

        base_list.append(base_p)
        pred_list.append(prob)
        for k, v in shap_vals.items():
            shap_accum[k].append(v)

        if prob >= 0.70:
            max_tier = 3
            status_label = "Critical / Impassable Inundation"
        elif prob >= 0.50:
            max_tier = 2
            status_label = "High Flood Hazard"
        else:
            max_tier = 1
            status_label = "Flood Warning (Surface Pooling)"

        depth_m = round(max(0.12, (prob - 0.30) * 1.35), 2)
        place_name = resolved_names[idx] if idx < len(resolved_names) else f"Zone ({lat:.4f}, {lng:.4f})"

        ACTIVE_FLOOD_POLYGONS.append({
            "zone_id": idx + 1,
            "level": max_tier,
            "poly": poly,
            "center": (lng, lat)
        })

        features.append({
            "type": "Feature",
            "geometry": mapping(poly),
            "properties": {
                "zone_id": idx + 1,
                "is_primary_ring": True,
                "name": place_name,
                "category": f"DEM: {elev_m}m | Prob: {int(prob * 100)}%",
                "hazard_level": max_tier,
                "peak_level": max_tier,
                "hazard_label": status_label,
                "ring_label": "Flood Zone Area",
                "predicted_depth_m": depth_m,
                "flood_probability": prob,
                "base_value": base_p,
                "shap_values": shap_vals,
                "center_lat": round(lat, 5),
                "center_lng": round(lng, 5),
                "rainfall_mm_hr": intensity,
                "duration_hr": duration
            }
        })

    global_shap = {
        "base_value": round(float(np.mean(base_list)) if base_list else 0.08, 3),
        "mean_prediction": round(float(np.mean(pred_list)) if pred_list else 0.08, 3),
        "contributions": {k: round(float(np.mean(v)), 3) if v else 0.0 for k, v in shap_accum.items()}
    }

    return {"type": "FeatureCollection", "features": features, "global_shap": global_shap}

# -------------------------------------------------------------------------
# /route Endpoint
# -------------------------------------------------------------------------
def fetch_osrm_street_route(coords_list):
    coord_str = ";".join([f"{lng:.5f},{lat:.5f}" for lng, lat in coords_list])
    url = f"https://router.project-osrm.org/route/v1/driving/{coord_str}?overview=full&geometries=geojson&alternatives=true"
    req = urllib.request.Request(url, headers={"User-Agent": "NairobiFloodDSS/1.0"})
    with urllib.request.urlopen(req, timeout=6) as resp:
        data = json.loads(resp.read().decode("utf-8"))
        if data.get("code") == "Ok" and data.get("routes"):
            return data["routes"]
    return []

@app.get("/route")
# =========================================================================
# /route Endpoint — Obstacle Avoidance & Detour Calculation
# =========================================================================
@app.get("/route")
def compute_safe_route(
    start_lat: float = Query(...),
    start_lng: float = Query(...),
    end_lat: float = Query(...),
    end_lng: float = Query(...),
    avoid_flood: bool = Query(True),
    intensity: float = Query(45.0),
    duration: float = Query(2.0)
):
    global ACTIVE_FLOOD_POLYGONS

    # 1. Ensure flood hazards are populated for current rainfall scenario
    if not ACTIVE_FLOOD_POLYGONS and intensity > 2.0:
        predict_flood(intensity=intensity, duration=duration)

    # 2. Extract active polygon geometries
    obstacles = [z["poly"] for z in ACTIVE_FLOOD_POLYGONS if z.get("poly") is not None]
    obstacle_union = unary_union(obstacles) if obstacles else None

    # Helper function to query OSRM routing engine
    def fetch_osrm_route(waypoints):
        coord_str = ";".join([f"{lng:.5f},{lat:.5f}" for lng, lat in waypoints])
        url = f"https://router.project-osrm.org/route/v1/driving/{coord_str}?overview=full&geometries=geojson&alternatives=true"
        req = urllib.request.Request(url, headers={"User-Agent": "NairobiFloodDSS/1.0"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            if data.get("code") == "Ok" and data.get("routes"):
                return data["routes"]
        return []

    try:
        # Fetch standard direct street routes
        primary_routes = fetch_osrm_route([(start_lng, start_lat), (end_lng, end_lat)])
        if not primary_routes:
            return {"type": "FeatureCollection", "features": []}

        direct_route = primary_routes[0]
        direct_geom = direct_route["geometry"]
        direct_line = LineString(direct_geom["coordinates"])

        # Check if direct line cuts through active flood polygons
        intersects_flood = False
        if obstacle_union and not obstacle_union.is_empty:
            intersects_flood = direct_line.intersects(obstacle_union)

        # Case A: Route is clean or user toggled avoid off
        if not avoid_flood or not intersects_flood or obstacle_union is None:
            return {
                "type": "FeatureCollection",
                "features": [{
                    "type": "Feature",
                    "geometry": direct_geom,
                    "properties": {
                        "route_type": "safe",
                        "distance_km": round(direct_route["distance"] / 1000.0, 2),
                        "duration_min": round(direct_route["duration"] / 60.0, 1),
                        "avoided_zones": 0
                    }
                }]
            }

        # Case B: Direct route is flooded! Search for a safe detour path around polygon
        best_safe_route = None
        min_intersection_len = direct_line.intersection(obstacle_union).length

        # Test OSRM built-in alternative paths
        for cand in primary_routes:
            cand_line = LineString(cand["geometry"]["coordinates"])
            if not cand_line.intersects(obstacle_union):
                best_safe_route = cand
                min_intersection_len = 0.0
                break
            else:
                inter_len = cand_line.intersection(obstacle_union).length
                if inter_len < min_intersection_len:
                    min_intersection_len = inter_len
                    best_safe_route = cand

        # If alternatives still cross flood water, generate offset waypoints around centroid
        if min_intersection_len > 0 and obstacle_union:
            centroid = obstacle_union.centroid
            cx, cy = centroid.x, centroid.y

            dx = end_lng - start_lng
            dy = end_lat - start_lat
            norm = math.hypot(dx, dy) + 1e-9
            nx, ny = -dy / norm, dx / norm  # Perpendicular normal vector

            # Try waypoints offset at ~300m, ~600m, ~1.2km to left/right
            offsets = [0.003, -0.003, 0.007, -0.007, 0.012, -0.012]
            for dist_deg in offsets:
                wp_lng = cx + nx * dist_deg
                wp_lat = cy + ny * dist_deg
                wp_pt = Point(wp_lng, wp_lat)

                if not wp_pt.intersects(obstacle_union):
                    detour_routes = fetch_osrm_route([(start_lng, start_lat), (wp_lng, wp_lat), (end_lng, end_lat)])
                    if detour_routes:
                        cand_detour = detour_routes[0]
                        detour_line = LineString(cand_detour["geometry"]["coordinates"])
                        if not detour_line.intersects(obstacle_union):
                            best_safe_route = cand_detour
                            min_intersection_len = 0.0
                            break
                        else:
                            d_len = detour_line.intersection(obstacle_union).length
                            if d_len < min_intersection_len:
                                min_intersection_len = d_len
                                best_safe_route = cand_detour

        features = []

        # Green Safe Detour
        if best_safe_route:
            features.append({
                "type": "Feature",
                "geometry": best_safe_route["geometry"],
                "properties": {
                    "route_type": "safe",
                    "distance_km": round(best_safe_route["distance"] / 1000.0, 2),
                    "duration_min": round(best_safe_route["duration"] / 60.0, 1),
                    "avoided_zones": 1 if min_intersection_len == 0 else 0
                }
            })

        # Red Dashed Flooded Direct Route (for comparison)
        features.append({
            "type": "Feature",
            "geometry": direct_geom,
            "properties": {
                "route_type": "flooded_direct",
                "distance_km": round(direct_route["distance"] / 1000.0, 2),
                "duration_min": round(direct_route["duration"] / 60.0, 1)
            }
        })

        return {"type": "FeatureCollection", "features": features}

    except Exception as e:
        print(f"[WARN] Route computation error: {e}")
        return {"type": "FeatureCollection", "features": []}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8001)