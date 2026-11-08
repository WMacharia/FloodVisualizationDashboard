import os
import sys

# --- Bypass Windows PostgreSQL / PostGIS PROJ Conflict ---
os.environ.pop("PROJ_LIB", None)
os.environ.pop("GDAL_DATA", None)
# ---------------------------------------------------------

import rasterio
import numpy as np
import pandas as pd
from pyproj import Transformer

print("=======================================================")
print("STARTING PHASE 3: FEATURE ENGINEERING & TABLE CREATION")
print("=======================================================")

# Define input raster feature paths
FEATURE_FILES = {
    "flow_acc": "data/processed/feature_flow_acc.tif",     # Captures upstream volume
    "slope_deg": "data/processed/feature_slope.tif",       # Captures velocity
    "twi": "data/processed/feature_twi.tif",               # The REAL saturation index
    "hand": "data/processed/feature_hand.tif",             # Height above stream
    "mannings_n": "data/processed/feature_mannings_n.tif", # Roughness
    "rainfall_mm": "data/processed/feature_rainfall.tif",  # Input volume
    "building_density": "data/processed/feature_building_density.tif"
}
TARGET_FILE = "data/processed/target_depth.tif"
MASK_FILE = "data/processed/valid_pixel_mask.tif"
OUTPUT_PARQUET = "data/processed/training_data.parquet"

# 1. Load the Valid Pixel Mask
print("1. Loading spatial mask...")
with rasterio.open(MASK_FILE) as src:
    mask_array = src.read(1)
    ref_transform = src.transform
    ref_crs = src.crs

# Find indices where mask == 1 (Only within Nairobi study boundary)
valid_rows, valid_cols = np.where(mask_array == 1)
total_valid_pixels = len(valid_rows)
print(f"   [OK] Identified {total_valid_pixels:,} valid analysis pixels.")

# 2. Extract Metric Coordinates (Easting / Northing)
print("2. Generating coordinate locations...")
xs, ys = rasterio.transform.xy(ref_transform, valid_rows, valid_cols)
xs = np.array(xs, dtype=np.float32)
ys = np.array(ys, dtype=np.float32)

# Convert UTM 37N (EPSG:32637) coordinates to Latitude / Longitude (EPSG:4326) for GIS dashboard mapping
transformer = Transformer.from_crs("EPSG:32637", "EPSG:4326", always_xy=True)
lons, lats = transformer.transform(xs, ys)
lons = np.array(lons, dtype=np.float32)
lats = np.array(lats, dtype=np.float32)

# 3. Assemble Tabular Feature Dictionary
data_dict = {
    "x_coord": xs,
    "y_coord": ys,
    "longitude": lons,
    "latitude": lats
}

# 4. Extract Pixel Values for Every Feature
print("3. Extracting feature values at valid pixel coordinates...")
for feat_name, feat_path in FEATURE_FILES.items():
    print(f"   Reading {feat_name}...")
    with rasterio.open(feat_path) as src:
        layer_data = src.read(1)
        # Vectorized extraction directly on valid index locations
        data_dict[feat_name] = layer_data[valid_rows, valid_cols].astype(np.float32)

# 5. Extract Target Values (Flood Depth)
print("4. Extracting target variable (flood_depth)...")
with rasterio.open(TARGET_FILE) as src:
    target_data = src.read(1)
    data_dict["flood_depth"] = target_data[valid_rows, valid_cols].astype(np.float32)

# 6. Build and Clean DataFrame
print("5. Building Pandas DataFrame...")
df = pd.DataFrame(data_dict)

# Remove any residual NaN / Inf values if present
initial_len = len(df)
df = df.replace([np.inf, -np.inf], np.nan).dropna()
dropped_rows = initial_len - len(df)
if dropped_rows > 0:
    print(f"   [WARNING] Dropped {dropped_rows:,} rows containing NaN/Inf.")
else:
    print("   [OK] Zero NaN or Infinite values found.")

# 7. Print Summary Statistics
print("\n" + "="*55)
print("FEATURE MATRIX SUMMARY:")
print("="*55)
feature_cols = list(FEATURE_FILES.keys()) + ["flood_depth"]
print(df[feature_cols].describe().T[["mean", "std", "min", "50%", "max"]])

# 8. Save to Columnar Parquet
print("\n6. Serializing to Apache Parquet...")
df.to_parquet(OUTPUT_PARQUET, index=False, engine="pyarrow", compression="snappy")
file_size_mb = os.path.getsize(OUTPUT_PARQUET) / (1024 * 1024)
print(f"[SUCCESS] Dataset saved to: {OUTPUT_PARQUET} ({file_size_mb:.2f} MB)")
print("=======================================================")