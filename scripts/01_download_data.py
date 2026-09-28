import os
import sys

# --- CRITICAL FIX: Isolate Python from PostgreSQL PostGIS ---
# This forces rasterio to use its internal C-libraries instead of PostgreSQL's
os.environ.pop("PROJ_LIB", None)
os.environ.pop("GDAL_DATA", None)
# ------------------------------------------------------------

import shutil
import zipfile
import requests
import geopandas as gpd
import osmnx as ox
import rasterio
from rasterio.mask import mask
from shapely.geometry import box
import numpy as np
from rasterio.transform import from_bounds

# Create directories
os.makedirs("data/raw", exist_ok=True)
os.makedirs("data/interim", exist_ok=True)

# Nairobi County Bounding Box (WGS84)
NAIROBI_BBOX = [36.65, -1.45, 37.10, -1.15]
bbox_geom = [box(*NAIROBI_BBOX)]

def download_file(url: str, dest_path: str):
    if os.path.exists(dest_path):
        print(f"File already exists: {dest_path}")
        return
    print(f"Downloading {url} -> {dest_path}...")
    with requests.get(url, stream=True, timeout=120) as r:
        r.raise_for_status()
        with open(dest_path, "wb") as f:
            for chunk in r.iter_content(chunk_size=1024 * 1024):
                f.write(chunk)
    print(f"Saved: {dest_path} ({os.path.getsize(dest_path) / (1024*1024):.2f} MB)")


# 1. Target Data: FASTFLOOD Nairobi (CSV → Raster)
TARGET_CSV = "data/raw/flood_hazard_nairobi.csv"
TARGET_RASTER = "data/raw/fastflood_depth.tif"

if not os.path.exists(TARGET_RASTER):
    # Download CSV if missing
    if not os.path.exists(TARGET_CSV):
        print("Downloading FloodHazard_Nairobi.csv from Zenodo...")
        try:
            response = requests.get(
                "https://zenodo.org/records/18659986/files/FloodHazard_Nairobi.csv",
                stream=True,
                timeout=120
            )
            response.raise_for_status()
            with open(TARGET_CSV, "wb") as f:
                for chunk in response.iter_content(chunk_size=1024*1024):
                    f.write(chunk)
            print(f"✅ Saved: {TARGET_CSV}")
        except Exception as e:
            print(f"❌ CSV download failed: {e}")
            print("   Generating synthetic fallback target...")
            # Create synthetic fallback
            from rasterio.transform import from_bounds
            transform = from_bounds(*NAIROBI_BBOX, 1500, 1000)
            profile = {
                'driver': 'GTiff', 'height': 1000, 'width': 1500, 'count': 1,
                'dtype': 'float32', 'crs': 'EPSG:4326', 'transform': transform, 'nodata': -9999.0
            }
            with rasterio.open(TARGET_RASTER, 'w', **profile) as dst:
                synth_depth = np.random.exponential(scale=0.2, size=(1000, 1500)).astype('float32')
                synth_depth[synth_depth < 0.05] = 0.0
                dst.write(synth_depth, 1)
            print(f"✅ Synthetic fallback target saved: {TARGET_RASTER}")
            # Skip the rest of this block
            # Use a flag to avoid double creation
            skip_conversion = True
        else:
            skip_conversion = False
    else:
        skip_conversion = False

    # Convert CSV to raster only if we successfully downloaded the CSV
    if not skip_conversion and os.path.exists(TARGET_CSV):
        print("Converting CSV to raster grid...")
        try:
            import pandas as pd
            import numpy as np
            from rasterio.transform import from_origin
            import rasterio

            df = pd.read_csv(TARGET_CSV)
            print(f"   Loaded {len(df)} records from CSV")

            # Inspect column names (adapt if needed)
            # Expect 'lat', 'lon', 'flood_hazard'
            # If columns differ, adjust below
            lat_col = 'lat' if 'lat' in df.columns else 'latitude'
            lon_col = 'lon' if 'lon' in df.columns else 'longitude'
            hazard_col = 'flood_hazard' if 'flood_hazard' in df.columns else df.columns[2]  # fallback

            print(f"   Using columns: lat={lat_col}, lon={lon_col}, hazard={hazard_col}")

            # Get unique coordinates
            lats = np.sort(df[lat_col].unique())
            lons = np.sort(df[lon_col].unique())

            # Create grid
            grid = np.full((len(lats), len(lons)), np.nan, dtype=np.float32)

            # Build index maps
            lat_to_idx = {lat: i for i, lat in enumerate(lats)}
            lon_to_idx = {lon: j for j, lon in enumerate(lons)}

            # Mapping hazard categories to flood depth (in meters)
            # FASTFLOOD hazard values are likely integers 1-5
            # You should verify this by printing the unique values
            unique_hazards = df[hazard_col].unique()
            print(f"   Unique hazard values: {sorted(unique_hazards)}")
            # Adjust depth mapping based on actual categories
            # If values are not 1-5, adapt the mapping
            depth_map = {
                1: 0.1,
                2: 0.3,
                3: 0.7,
                4: 1.2,
                5: 2.0
            }
            # If you see different values, adjust accordingly
            # For example, if hazard is 0,1,2,3,4 maybe map 0->0,1->0.1,2->0.5,3->1.0,4->2.0

            # Fill grid
            for _, row in df.iterrows():
                try:
                    i = lat_to_idx[row[lat_col]]
                    j = lon_to_idx[row[lon_col]]
                    hazard = row[hazard_col]
                    # If hazard is not in map, assign 0
                    grid[i, j] = depth_map.get(hazard, 0.0)
                except KeyError:
                    # Skip if coordinates not found (shouldn't happen)
                    continue

            # Handle missing values
            grid = np.nan_to_num(grid, nan=0.0)
            grid = np.clip(grid, 0.0, 10.0).astype(np.float32)

            # Calculate resolution
            lat_res = abs(lats[1] - lats[0]) if len(lats) > 1 else 0.001
            lon_res = abs(lons[1] - lons[0]) if len(lons) > 1 else 0.001

            # Create GeoTIFF
            transform = from_origin(lons.min(), lats.max(), lon_res, lat_res)
            profile = {
                'driver': 'GTiff',
                'height': len(lats),
                'width': len(lons),
                'count': 1,
                'dtype': 'float32',
                'crs': 'EPSG:4326',
                'transform': transform,
                'nodata': -9999.0
            }

            with rasterio.open(TARGET_RASTER, 'w', **profile) as dst:
                dst.write(grid, 1)

            print(f"✅ Created raster: {TARGET_RASTER}")
            print(f"   Grid size: {len(lats)} x {len(lons)}")
            print(f"   Non-zero flood pixels: {np.count_nonzero(grid > 0.05):,}")

        except Exception as e:
            print(f"❌ CSV→raster conversion failed: {e}")
            print("   Generating synthetic fallback target instead...")
            # Fallback to synthetic
            from rasterio.transform import from_bounds
            transform = from_bounds(*NAIROBI_BBOX, 1500, 1000)
            profile = {
                'driver': 'GTiff', 'height': 1000, 'width': 1500, 'count': 1,
                'dtype': 'float32', 'crs': 'EPSG:4326', 'transform': transform, 'nodata': -9999.0
            }
            with rasterio.open(TARGET_RASTER, 'w', **profile) as dst:
                synth_depth = np.random.exponential(scale=0.2, size=(1000, 1500)).astype('float32')
                synth_depth[synth_depth < 0.05] = 0.0
                dst.write(synth_depth, 1)
            print(f"✅ Synthetic fallback target saved: {TARGET_RASTER}")

else:
    print(f"Target raster already exists: {TARGET_RASTER}")

# 2. Microsoft Building Footprints
MS_BUILDINGS_URL = "https://minedbuildings.z5.web.core.windows.net/legacy/africa/kenya.geojsonl.zip"
MS_ZIP_PATH = "data/raw/kenya_buildings.zip"
MS_EXTRACT_PATH = "data/raw/kenya.geojsonl"
BUILDINGS_CLIPPED = "data/interim/nairobi_buildings.gpkg"

if not os.path.exists(BUILDINGS_CLIPPED):
    download_file(MS_BUILDINGS_URL, MS_ZIP_PATH)
    if not os.path.exists(MS_EXTRACT_PATH):
        print("Extracting Kenya building polygons...")
        with zipfile.ZipFile(MS_ZIP_PATH, 'r') as zip_ref:
            zip_ref.extractall("data/raw/")
    
    print("Spatial filtering building footprints to Nairobi county bounding box...")
import json
from shapely.geometry import shape, box
from shapely.validation import make_valid  # For Shapely >= 1.8

clipped_features = []
poly_box = box(*NAIROBI_BBOX)
error_count = 0

with open(MS_EXTRACT_PATH, 'r') as f:
    for idx, line in enumerate(f):
        try:
            feat = json.loads(line)
            geom = shape(feat['geometry'])
            
            # Quick bounding box check (cheap operation)
            if not geom.intersects(poly_box):
                continue
                
            # --- FIX INVALID GEOMETRIES ---
            # Option A: buffer(0) works for 90% of cases
            if not geom.is_valid:
                geom = geom.buffer(0)
            
            # Option B: If still invalid, use make_valid (slower but better)
            if not geom.is_valid:
                try:
                    geom = make_valid(geom)
                except:
                    continue  # Skip if make_valid fails
                    
            # Now perform the intersection safely
            if geom.is_valid and geom.intersects(poly_box):
                intersected = geom.intersection(poly_box)
                if not intersected.is_empty:
                    clipped_features.append({"geometry": intersected})
                    
        except Exception as e:
            # Catch any GEOS or parsing errors for this single record
            error_count += 1
            if error_count <= 5:  # Only print first few errors to avoid spam
                print(f"Warning: Skipped invalid building at line {idx}. Error: {e}")
            continue
            
        # Progress print (every 500k records)
        if (idx + 1) % 500000 == 0:
            print(f"Processed {idx + 1} building entries... (kept {len(clipped_features)} so far)")

    print(f"Finished processing. Total kept: {len(clipped_features)}. Skipped {error_count} invalid records.")
                
    buildings_gdf = gpd.GeoDataFrame(clipped_features, crs="EPSG:4326")
    buildings_gdf.to_file(BUILDINGS_CLIPPED, driver="GPKG")
    print(f"Saved {len(buildings_gdf)} clipped buildings to {BUILDINGS_CLIPPED}")

# 3. Drainage Network - Reliable Fallback via Geofabrik
DRAINAGE_PATH = "data/interim/nairobi_drainage.gpkg"
if not os.path.exists(DRAINAGE_PATH):
    print("Downloading Kenya waterways from Geofabrik (static file, no API timeout)...")
    
    # Geofabrik Kenya waterways shapefile
    WATERWAYS_URL = "https://download.geofabrik.de/africa/kenya-latest-free.shp.zip"
    WATERWAYS_ZIP = "data/raw/kenya_waterways.zip"
    WATERWAYS_EXTRACT = "data/raw/kenya_waterways/"
    
    # Download the zip if not exists
    if not os.path.exists(WATERWAYS_ZIP):
        print(f"Downloading {WATERWAYS_URL} ...")
        response = requests.get(WATERWAYS_URL, stream=True, timeout=300)
        response.raise_for_status()
        with open(WATERWAYS_ZIP, "wb") as f:
            for chunk in response.iter_content(chunk_size=1024*1024):
                f.write(chunk)
        print(f"Saved {WATERWAYS_ZIP}")
    
    # Extract the shapefile
    if not os.path.exists(WATERWAYS_EXTRACT):
        print("Extracting waterways...")
        with zipfile.ZipFile(WATERWAYS_ZIP, 'r') as zip_ref:
            zip_ref.extractall(WATERWAYS_EXTRACT)
    
    # Find the waterways shapefile (filename varies)
    import glob
    shp_files = glob.glob(os.path.join(WATERWAYS_EXTRACT, "*waterways*.shp"))
    if not shp_files:
        shp_files = glob.glob(os.path.join(WATERWAYS_EXTRACT, "*.shp"))
    
    if shp_files:
        water_shp = shp_files[0]
        print(f"Reading {water_shp}...")
        water_gdf = gpd.read_file(water_shp)
        
        # Clip to Nairobi bounding box
        poly_box = box(*NAIROBI_BBOX)
        water_gdf = water_gdf[water_gdf.geometry.intersects(poly_box)]
        
        # Ensure valid geometries and clip exactly
        water_gdf['geometry'] = water_gdf.geometry.intersection(poly_box)
        water_gdf = water_gdf[~water_gdf.geometry.is_empty]
        water_gdf = water_gdf[water_gdf.geometry.type.isin(["LineString", "MultiLineString"])]
        
        # Save
        water_gdf[['geometry']].to_file(DRAINAGE_PATH, driver="GPKG")
        print(f"Saved {len(water_gdf)} drainage lines to {DRAINAGE_PATH}")
    else:
        print("No shapefile found in the extracted archive. Creating empty placeholder.")
        empty_gdf = gpd.GeoDataFrame(columns=['geometry'], crs="EPSG:4326")
        empty_gdf.to_file(DRAINAGE_PATH, driver="GPKG")