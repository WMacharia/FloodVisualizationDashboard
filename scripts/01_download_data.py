"""
PHASE 1: DOWNLOAD VECTOR DATA FOR NAIROBI

Microsoft building footprints (-> data/interim/nairobi_buildings.gpkg, used by 08c) and the OSM drainage
network. The FastFlood target raster this script used to build is no longer used: the U-Net now learns
from our own simulator (08). The original script is in archive/legacy_pipeline/.
"""
import os
import sys

# --- CRITICAL FIX: Isolate Python from PostgreSQL PostGIS ---
# This forces rasterio to use its internal C-libraries instead of PostgreSQL's
os.environ.pop("PROJ_LIB", None)
os.environ.pop("GDAL_DATA", None)
# ------------------------------------------------------------

import zipfile
import requests
import geopandas as gpd
import osmnx as ox
from shapely.geometry import box

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


# 1. Microsoft Building Footprints (used by 08c_building_fraction.py)
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

# 2. Drainage Network - Reliable Fallback via Geofabrik
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