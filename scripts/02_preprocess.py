import os
import sys
import warnings

# --- CRITICAL FIX: Bypass Windows PostgreSQL / PostGIS PROJ Conflict ---
os.environ.pop("PROJ_LIB", None)
os.environ.pop("GDAL_DATA", None)
# -----------------------------------------------------------------------

warnings.filterwarnings("ignore", category=UserWarning)

import numpy as np
import rasterio
from rasterio.enums import Resampling
from rasterio.features import rasterize
import rioxarray
import xarray as xr
import geopandas as gpd
from scipy.ndimage import sobel, distance_transform_edt, uniform_filter

# Ensure processed output directory exists
os.makedirs("data/processed", exist_ok=True)

TARGET_CRS = "EPSG:32637"  # UTM Zone 37N (metric coordinate system)

# -------------------------------------------------------------------------
# STEP 1: Process Master DEM (Reference Grid)
# -------------------------------------------------------------------------
print("1. Processing Master DEM...")
MASTER_DEM_PATH = "data/raw/nairobi_elevation.tif"

if not os.path.exists(MASTER_DEM_PATH):
    print(f"ERROR: {MASTER_DEM_PATH} not found. Please ensure your DEM is located here.")
    sys.exit(1)

master_dem = rioxarray.open_rasterio(MASTER_DEM_PATH)
assert isinstance(master_dem, xr.DataArray), "Expected a DataArray"

# Reproject to UTM Zone 37N (30m cell resolution) if currently in degrees
if master_dem.rio.crs != TARGET_CRS:
    print("   Reprojecting DEM to EPSG:32637 (30m resolution)...")
    master_dem = master_dem.rio.reproject(
        TARGET_CRS, resolution=30.0, resampling=Resampling.bilinear
    )

master_dem.rio.write_nodata(-9999.0, inplace=True)
master_dem.rio.to_raster("data/processed/master_dem.tif")
print(f"   [OK] Master DEM shape: {master_dem.shape} | Resolution: 30.0m | CRS: {master_dem.rio.crs}")

# Extract reference transform and dimensions for manual rasterization steps
ref_transform = master_dem.rio.transform()
ref_shape = master_dem.shape[-2:]  # (height, width)

# -------------------------------------------------------------------------
# STEP 2: Align Flood Depth Target directly from CSV
# -------------------------------------------------------------------------
print("2. Aligning Flood Depth Target...")
import pandas as pd
from rasterio.features import rasterize

target_csv = "data/raw/flood_hazard_nairobi.csv"
df = pd.read_csv(target_csv)

# 1. Convert CSV to spatial points and reproject to match Master DEM (UTM 37N)
target_gdf = gpd.GeoDataFrame(
    df,
    geometry=gpd.points_from_xy(df['longitude'], df['latitude']),
    crs="EPSG:4326"
).to_crs(TARGET_CRS)

# 2. Map the FASTFLOOD hazard classes (0, 1, 2) to physical depths (meters)
# Based on standard urban hazard models: Class 1 = ~0.5m, Class 2 = ~1.5m
depth_map = {0: 0.0, 1: 0.5, 2: 1.5}
target_gdf['depth_m'] = target_gdf['flood_hazard'].map(depth_map).fillna(0.0)

# 3. Filter to only flooded areas to speed up rasterization
flooded_gdf = target_gdf[target_gdf['depth_m'] > 0]

# 4. Burn the points directly into the exact pixels of the master grid
shapes = ((geom, val) for geom, val in zip(flooded_gdf.geometry, flooded_gdf['depth_m']))
target_data = rasterize(
    shapes=shapes,
    out_shape=ref_shape,
    transform=ref_transform,
    fill=0.0,
    dtype=np.float32
)

master_dem.copy(data=target_data[np.newaxis, ...]).rio.to_raster("data/processed/target_depth.tif")
print(f"   [OK] Target depth aligned. Non-zero flood pixels: {np.count_nonzero(target_data > 0.0):,}")

# -------------------------------------------------------------------------
# STEP 3: Align CHIRPS Rainfall
# -------------------------------------------------------------------------
print("3. Aligning CHIRPS Rainfall Grid...")
chirps_path = "data/raw/chirps_may2025.tif"
chirps_ds = rioxarray.open_rasterio(chirps_path)

# CHIRPS is native ~5km resolution; bilinear interpolation upsamples smoothly to 30m
rain_aligned = chirps_ds.rio.reproject_match(master_dem, resampling=Resampling.bilinear)
rain_aligned.rio.to_raster("data/processed/feature_rainfall.tif")
print(f"   [OK] CHIRPS rainfall aligned. Range: {float(rain_aligned.min()):.2f} to {float(rain_aligned.max()):.2f} mm")

# -------------------------------------------------------------------------
# STEP 4: Rasterize Building Footprints into Building Density (0.0 to 1.0)
# -------------------------------------------------------------------------
print("4. Calculating Building Density...")
buildings_path = "data/interim/nairobi_buildings.gpkg"
bldg_gdf = gpd.read_file(buildings_path).to_crs(TARGET_CRS)
bldg_gdf = bldg_gdf[bldg_gdf.is_valid & ~bldg_gdf.is_empty]

# Burn building polygons into binary occupancy grid (1 = building present)
shapes = ((geom, 1) for geom in bldg_gdf.geometry)
binary_bldg = rasterize(
    shapes=shapes,
    out_shape=ref_shape,
    transform=ref_transform,
    fill=0,
    dtype=np.uint8
)

# Smooth using a 3x3 uniform moving window (~90x90m neighborhood density fraction)
bldg_density = uniform_filter(binary_bldg.astype(np.float32), size=3)
master_dem.copy(data=bldg_density[np.newaxis, ...]).rio.to_raster("data/processed/feature_building_density.tif")
print(f"   [OK] Building density generated. Mean urban density: {float(bldg_density.mean()):.4f}")

# -------------------------------------------------------------------------
# STEP 5: Calculate Distance to Drainage AND H.A.N.D.
# -------------------------------------------------------------------------
print("5. Calculating Distance to Drainage and H.A.N.D...")
drain_path = "data/interim/nairobi_drainage.gpkg"
drain_gdf = gpd.read_file(drain_path).to_crs(TARGET_CRS)
drain_gdf = drain_gdf[drain_gdf.is_valid & ~drain_gdf.is_empty]

drain_shapes = ((geom, 1) for geom in drain_gdf.geometry)
binary_drain = rasterize(
    shapes=drain_shapes,
    out_shape=ref_shape,
    transform=ref_transform,
    fill=0,
    dtype=np.uint8
)

distances, indices = distance_transform_edt(binary_drain == 0, return_indices=True)
dist_to_drain = distances * 30.0
master_dem.copy(data=dist_to_drain[np.newaxis, ...].astype(np.float32)).rio.to_raster("data/processed/feature_dist_drainage.tif")

# FIX: Clean the DEM to prevent -32767 NoData from creating 34km high artifacts
dem_elev = master_dem.values.squeeze().astype(np.float32)
clean_dem = np.where(dem_elev < 0, np.nan, dem_elev)
nearest_drain_elev = clean_dem[indices[0], indices[1]]

hand = clean_dem - nearest_drain_elev
hand = np.nan_to_num(hand, nan=0.0)
hand[hand < 0] = 0.0
master_dem.copy(data=hand[np.newaxis, ...].astype(np.float32)).rio.to_raster("data/processed/feature_hand.tif")
print(f"   [OK] H.A.N.D. generated. Max height above drainage: {float(hand.max()):.1f}m")

# -------------------------------------------------------------------------
# STEP 6: Reclassify WorldCover to Manning's Roughness Coefficient (n)
# -------------------------------------------------------------------------
# STEP 6: Convert WorldCover to Manning's Roughness (with NoData handling)
print("6. Converting Land Cover to Manning's Roughness (n)...")
wc_path = "data/interim/nairobi_landcover_10m.tif"
wc_ds = rioxarray.open_rasterio(wc_path)

# Reproject using NEAREST NEIGHBOR to preserve discrete categorical classes
wc_aligned = wc_ds.rio.reproject_match(master_dem, resampling=Resampling.nearest)
wc_data = wc_aligned.values.squeeze().astype(np.float32)

# ESA WorldCover classes -> Manning's n coefficient
mannings_map = {
    10: 0.100,  # Tree cover
    20: 0.070,  # Shrubland
    30: 0.050,  # Grassland
    40: 0.040,  # Cropland
    50: 0.015,  # Built-up
    60: 0.030,  # Bare soil
    80: 0.025,  # Open water
    90: 0.080,  # Herbaceous wetland
    95: 0.120,  # Mangroves
    100: 0.035  # Moss/lichens
}

# Default for unknown/missing classes
mannings_n = np.full(wc_data.shape, 0.045, dtype=np.float32)  # 0.045 = default roughness

# Apply mapping where valid (10-100)
valid_classes = list(mannings_map.keys())
for class_code, n_val in mannings_map.items():
    mannings_n[wc_data == class_code] = n_val

# Optional: Save valid class coverage
valid_cover = (wc_data >= 10) & (wc_data <= 100)
if valid_cover.sum() == 0:
    print("   [WARNING] No valid WorldCover classes found! Check the raster.")
else:
    print(f"   [OK] Manning's n created. {valid_cover.sum():,} valid pixels.")

master_dem.copy(data=mannings_n[np.newaxis, ...]).rio.to_raster(
    "data/processed/feature_mannings_n.tif"
)
# -------------------------------------------------------------------------
# STEP 7: Calculate Slope Gradient (Degrees) from DEM
# -------------------------------------------------------------------------
print("7. Calculating Slope using Sobel Operator...")
dem_elev = master_dem.values.squeeze().astype(np.float32)

# Sobel gradient operator across 30m grid spacing
dx = sobel(dem_elev, axis=1) / (8.0 * 30.0)
dy = sobel(dem_elev, axis=0) / (8.0 * 30.0)
slope_deg = np.arctan(np.sqrt(dx**2 + dy**2)) * (180.0 / np.pi)
slope_deg = np.clip(slope_deg, 0.0, 89.0).astype(np.float32)
master_dem.copy(data=slope_deg[np.newaxis, ...]).rio.to_raster("data/processed/feature_slope.tif")
print(f"   [OK] Slope calculated. Mean slope: {float(slope_deg.mean()):.2f} degrees")

# -------------------------------------------------------------------------
# STEP 8: True Flow Accumulation and Topographic Wetness Index (TWI)
# -------------------------------------------------------------------------
print("8. Calculating TRUE Flow Accumulation and TWI via PySheds...")
from pysheds.grid import Grid
import warnings
warnings.filterwarnings("ignore")

# FIX: Add data_name='dem' to satisfy the updated PySheds API
grid = Grid.from_raster("data/processed/master_dem.tif", data_name="dem")
dem = grid.dem

print("   [INFO] Tracing water flow across the DEM (this takes ~30 seconds)...")
fdir = grid.flowdir(dem)
acc = grid.accumulation(fdir)
flow_acc = np.abs(acc).astype(np.float32)

master_dem.copy(data=flow_acc[np.newaxis, ...]).rio.to_raster("data/processed/feature_flow_acc.tif")
print(f"   [OK] Flow Accumulation generated. Max upstream pixels: {float(flow_acc.max()):,}")

acc_area = (flow_acc + 1.0) * 30.0
slope_rad = np.radians(slope_deg)
slope_rad[slope_rad < 0.001] = 0.001
twi_true = np.log(acc_area / np.tan(slope_rad)).astype(np.float32)
twi_true = np.nan_to_num(twi_true, nan=0.0)

master_dem.copy(data=twi_true[np.newaxis, ...]).rio.to_raster("data/processed/feature_twi.tif")
print(f"   [OK] True TWI generated. Mean TWI: {float(twi_true.mean()):.2f}")

# -------------------------------------------------------------------------
# STEP 9: Strict Shape, Bounds, and Coordinate Alignment Verification
# -------------------------------------------------------------------------
print("9. Running Strict Geometry Validation across all 8 rasters...")
processed_files = [
    "master_dem.tif", "target_depth.tif", "feature_rainfall.tif",
    "feature_building_density.tif", "feature_dist_drainage.tif",
    "feature_mannings_n.tif", "feature_slope.tif", "feature_twi.tif"
]

ref_meta = None
for fname in processed_files:
    fpath = os.path.join("data/processed", fname)
    with rasterio.open(fpath) as src:
        if ref_meta is None:
            ref_meta = (src.shape, src.crs, src.transform)
        else:
            assert src.shape == ref_meta[0], f"Mismatch in {fname}: shape {src.shape} != {ref_meta[0]}"
            assert src.crs == ref_meta[1], f"Mismatch in {fname}: CRS {src.crs} != {ref_meta[1]}"
            assert src.transform == ref_meta[2], f"Mismatch in {fname}: transform differs"

print("\n=======================================================")
print("[SUCCESS] All 8 rasters are aligned pixel-for-pixel!")
print(f"Dimensions: {ref_meta[0]} | CRS: {ref_meta[1]}")
print("=======================================================")

# -------------------------------------------------------------------------
# STEP 10: Create Unified Valid Pixel Mask
# -------------------------------------------------------------------------
print("10. Creating unified valid pixel mask...")
from rasterio import open as rasterio_open

valid_mask = None
for fname in processed_files:
    fpath = os.path.join("data/processed", fname)
    with rasterio_open(fpath) as src:
        data = src.read(1)
        nodata = src.nodata if src.nodata is not None else -9999.0
        if valid_mask is None:
            valid_mask = (data != nodata) & (~np.isnan(data))
        else:
            valid_mask &= (data != nodata) & (~np.isnan(data))

# Fix: Create a clean DataArray copy with uint8 dtype and clear float NoData flags
mask_da = master_dem.copy(data=valid_mask.astype(np.uint8)[np.newaxis, ...])
mask_da.rio.write_nodata(255, inplace=True)  # Use 255 as safe uint8 nodata value
mask_da.rio.to_raster("data/processed/valid_pixel_mask.tif")

valid_pct = 100 * valid_mask.sum() / valid_mask.size
print(f"   [OK] Valid pixels: {valid_mask.sum():,} / {valid_mask.size:,} ({valid_pct:.1f}%)")

# Quick visual summary
print("\n=======================================================")
print("[SUCCESS] All 8 rasters aligned and masked!")
print(f"Dimensions: {ref_meta[0]} | CRS: {ref_meta[1]}")
print(f"Valid pixel coverage: {valid_pct:.1f}%")
print("=======================================================")