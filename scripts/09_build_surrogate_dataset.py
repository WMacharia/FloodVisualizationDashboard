"""
PHASE 9: BUILD THE U-NET SURROGATE DATASET

Packs everything the Colab notebook needs into ONE compressed file:
    data/surrogate/nairobi_surrogate_dataset.npz

Contents
  static      (C, H, W) float16  normalised terrain/land-cover channels (see STATIC_CHANNELS)
  cn          (H, W)    float16  SCS curve number (used to compute rainfall runoff per storm)
  depth       (S, H, W) float16  simulated max water depth (m) for each storm
  dv          (S, H, W) float16  simulated max depth x velocity (m2/s) for each storm
  storms      (S, 2)    float32  [intensity mm/hr, duration hr]
  split       (H, W)    uint8    0 = train, 1 = validation, 2 = test, 255 = border (ignored)
  holdout     (S,)      bool     storms never shown during training (unseen-storm test)
  stats_mean / stats_std (C,)    normalisation constants (computed on TRAIN cells only)
  transform   (6,)               affine transform of the grid (EPSG:4326)

Splits are geographic blocks: the grid is cut into 3 x 5 blocks and whole blocks are
assigned to train / validation / test, so no test pixel is ever seen during training.

Usage:
    python 09_build_surrogate_dataset.py            # requires all 28 storms
    python 09_build_surrogate_dataset.py --partial  # build from whatever storms exist (testing)
"""
import os
import re
import glob
import json
import argparse
import numpy as np
import rasterio
from scipy.ndimage import gaussian_filter
from pysheds.grid import Grid

# pysheds 0.5 still calls np.in1d, which was removed in NumPy 2.4
if not hasattr(np, "in1d"):
    np.in1d = lambda a, b, **kw: np.isin(np.ravel(a), b, **kw)

script_dir = os.path.dirname(os.path.abspath(__file__))
aligned_dir = os.path.join(script_dir, "data", "aligned")
scen_dir = os.path.join(script_dir, "data", "scenarios")
out_dir = os.path.join(script_dir, "data", "surrogate")
os.makedirs(out_dir, exist_ok=True)

STATIC_CHANNELS = [
    "tpi",          # topographic position: elevation minus smoothed neighbourhood (m)
    "slope",        # terrain slope (m/m)
    "hand",         # height above nearest drainage (m)
    "log_acc",      # log(1 + upstream contributing cells)
    "sink_depth",   # depth of closed depression at the cell (m)
    "cn",           # SCS curve number / 100 (composite with roofs)
    "bldg_frac",    # building coverage fraction (08c_building_fraction.py)
]
CHANNEL_THRESHOLD_CELLS = 1000   # ~0.9 km2 catchment defines a drainage channel for HAND
BORDER = 24                      # cells next to the grid edge drain artificially -> ignored
BLOCK_ROWS, BLOCK_COLS = 3, 5
# Block assignment (row-major, 15 blocks). Fixed so results are reproducible.
#   T = train, V = validation, X = test. Test blocks include the dense east/central city.
BLOCK_PLAN = [
    "TTVTT",
    "TXTTX",
    "TTXVT",
]
HOLDOUT_STORMS = [(35, 3), (90, 2)]   # (intensity, duration) never used for training

# Same mapping as 08_simulate_flood_scenarios.py
LANDCOVER_CN = {10: 73, 20: 70, 30: 79, 40: 85, 50: 94, 60: 91, 80: 100, 90: 98}
DEFAULT_CN = 79
ROOF_CN = 98
MAX_BUILDING_FRAC = 0.9


def terrain_features(dem_path):
    print("1. Hydrological conditioning with pysheds (fill, flats, D8)...")
    grid = Grid.from_raster(dem_path)
    dem = grid.read_raster(dem_path)
    pit_filled = grid.fill_pits(dem)
    filled = grid.fill_depressions(pit_filled)
    inflated = grid.resolve_flats(filled)
    fdir = grid.flowdir(inflated)
    acc = grid.accumulation(fdir)
    hand = grid.compute_hand(fdir, inflated, acc > CHANNEL_THRESHOLD_CELLS)

    z = np.asarray(dem, dtype=np.float64)
    hand = np.nan_to_num(np.asarray(hand, dtype=np.float64), nan=0.0, posinf=0.0)
    sink_depth = np.clip(np.asarray(filled, dtype=np.float64) - z, 0.0, None)
    log_acc = np.log1p(np.asarray(acc, dtype=np.float64))

    with rasterio.open(dem_path) as src:
        transform = src.transform
    lat_c = transform.f + transform.e * z.shape[0] / 2.0
    dx = abs(transform.a) * 111320.0 * np.cos(np.radians(lat_c))
    dy = abs(transform.e) * 110574.0
    gy, gx = np.gradient(z, dy, dx)
    slope = np.hypot(gx, gy)
    tpi = z - gaussian_filter(z, sigma=10)

    print(f"   HAND range 0-{hand.max():.1f} m, channel cells: {(acc > CHANNEL_THRESHOLD_CELLS).mean() * 100:.1f}%")
    return {"tpi": tpi, "slope": slope, "hand": hand, "log_acc": log_acc, "sink_depth": sink_depth}, transform


def load_storms():
    paths = sorted(glob.glob(os.path.join(scen_dir, "depth_i*_d*.tif")))
    storms, depths, dvs = [], [], []
    for p in paths:
        m = re.search(r"depth_i(\d+)_d(\d+)\.tif$", os.path.basename(p))
        dv_path = os.path.join(scen_dir, os.path.basename(p).replace("depth_", "dv_"))
        if not os.path.exists(dv_path):
            continue
        with rasterio.open(p) as src:
            depths.append(src.read(1).astype(np.float32))
        with rasterio.open(dv_path) as src:
            dvs.append(src.read(1).astype(np.float32))
        storms.append((float(m.group(1)), float(m.group(2))))
    order = np.lexsort(([s[0] for s in storms], [s[1] for s in storms]))
    return np.array(storms, dtype=np.float32)[order], np.stack(depths)[order], np.stack(dvs)[order]


def build_split(H, W):
    split = np.full((H, W), 255, dtype=np.uint8)
    code = {"T": 0, "V": 1, "X": 2}
    r_edges = np.linspace(0, H, BLOCK_ROWS + 1).astype(int)
    c_edges = np.linspace(0, W, BLOCK_COLS + 1).astype(int)
    for bi in range(BLOCK_ROWS):
        for bj in range(BLOCK_COLS):
            split[r_edges[bi]:r_edges[bi + 1], c_edges[bj]:c_edges[bj + 1]] = code[BLOCK_PLAN[bi][bj]]
    split[:BORDER, :] = 255
    split[-BORDER:, :] = 255
    split[:, :BORDER] = 255
    split[:, -BORDER:] = 255
    return split


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--partial", action="store_true", help="allow fewer than 28 storms (for testing)")
    args = parser.parse_args()

    dem_path = os.path.join(aligned_dir, "aligned_fabdem.tif")
    feats, transform = terrain_features(dem_path)

    with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif")) as src:
        esa = src.read(1)
    cn = np.full(esa.shape, DEFAULT_CN, dtype=np.float64)
    for cls, c in LANDCOVER_CN.items():
        cn[esa == cls] = c
    with rasterio.open(os.path.join(aligned_dir, "aligned_building_frac.tif")) as src:
        bldg = np.clip(src.read(1).astype(np.float64), 0.0, MAX_BUILDING_FRAC)
    cn = (1.0 - bldg) * cn + bldg * ROOF_CN
    feats["cn"] = cn / 100.0
    feats["bldg_frac"] = bldg

    print("2. Loading simulated storms...")
    storms, depth, dv = load_storms()
    print(f"   {len(storms)} storms: " + ", ".join(f"{int(i)}mm/{int(d)}h" for i, d in storms))
    if len(storms) < 28 and not args.partial:
        raise SystemExit(f"Only {len(storms)}/28 storms found - wait for 08 to finish, or pass --partial.")
    holdout = np.array([(int(i), int(d)) in HOLDOUT_STORMS for i, d in storms])

    H, W = cn.shape
    split = build_split(H, W)

    print("3. Normalising with TRAIN-only statistics...")
    train_mask = split == 0
    static = np.stack([feats[c] for c in STATIC_CHANNELS]).astype(np.float64)
    mean = np.array([static[k][train_mask].mean() for k in range(len(STATIC_CHANNELS))])
    std = np.array([static[k][train_mask].std() + 1e-6 for k in range(len(STATIC_CHANNELS))])
    static = (static - mean[:, None, None]) / std[:, None, None]

    print("4. Summary")
    names = {0: "train", 1: "validation", 2: "test"}
    for k, nm in names.items():
        sel = split == k
        wet = (depth[:, sel] > 0.15).mean() * 100
        dv_wet = dv[:, sel][depth[:, sel] > 0.15]
        print(f"   {nm:10s}: {sel.mean() * 100:5.1f}% of grid, flooded share across storms {wet:.2f}%, "
              f"DV in flooded cells median {np.median(dv_wet):.3f} / p95 {np.percentile(dv_wet, 95):.2f} m2/s")
    print(f"   held-out storms: {[f'{int(i)}mm/{int(d)}h' for i, d in storms[holdout]]}")

    out = os.path.join(out_dir, "nairobi_surrogate_dataset.npz")
    np.savez_compressed(
        out,
        static=static.astype(np.float16),
        cn=cn.astype(np.float16),
        depth=depth.astype(np.float16),
        dv=dv.astype(np.float16),
        storms=storms,
        split=split,
        holdout=holdout,
        stats_mean=mean.astype(np.float32),
        stats_std=std.astype(np.float32),
        channels=np.array(STATIC_CHANNELS),
        transform=np.array(transform[:6], dtype=np.float64),
    )
    # Small file with just the model inputs, loaded by model_api.py at start-up
    np.savez_compressed(
        os.path.join(out_dir, "static_inputs.npz"),
        static=static.astype(np.float16),
        cn=cn.astype(np.float32),
        split=split,
        stats_mean=mean.astype(np.float32),
        stats_std=std.astype(np.float32),
        channels=np.array(STATIC_CHANNELS),
        transform=np.array(transform[:6], dtype=np.float64),
    )
    meta = {
        "static_channels": STATIC_CHANNELS,
        "stats_mean": mean.tolist(),
        "stats_std": std.tolist(),
        "storms": storms.tolist(),
        "holdout_storms": [list(s) for s in HOLDOUT_STORMS],
        "block_plan": BLOCK_PLAN,
        "border": BORDER,
        "channel_threshold_cells": CHANNEL_THRESHOLD_CELLS,
        "grid_shape": [H, W],
        "transform": list(transform[:6]),
    }
    with open(os.path.join(out_dir, "dataset_meta.json"), "w", encoding="utf-8") as f:
        json.dump(meta, f, indent=2)
    print(f"\nSaved {out} ({os.path.getsize(out) / 1e6:.0f} MB)")
    print("Upload it to Google Drive: MyDrive/Flood_Project/nairobi_surrogate_dataset.npz")


if __name__ == "__main__":
    main()
