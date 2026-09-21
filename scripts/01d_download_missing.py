import os
import requests
import zipfile
import rasterio
from rasterio.mask import mask
from shapely.geometry import box
import geopandas as gpd

# --- CRITICAL FIX: Isolate Python from PostgreSQL PostGIS ---
os.environ.pop("PROJ_LIB", None)
os.environ.pop("GDAL_DATA", None)
# ------------------------------------------------------------

os.makedirs("data/raw", exist_ok=True)
os.makedirs("data/interim", exist_ok=True)

NAIROBI_BBOX = [36.65, -1.45, 37.10, -1.15]
poly_box = box(*NAIROBI_BBOX)

def download_file(url, dest_path):
    if os.path.exists(dest_path):
        print(f"File exists: {dest_path}")
        return dest_path
    print(f"Downloading {url}...")
    with requests.get(url, stream=True, timeout=120) as r:
        r.raise_for_status()
        with open(dest_path, "wb") as f:
            for chunk in r.iter_content(chunk_size=1024 * 1024):
                f.write(chunk)
    print(f"Saved: {dest_path}")
    return dest_path

# 1. Land Cover: ESA WorldCover 2021 (10m Resolution)
WORLDCOVER_URL = "https://esa-worldcover.s3.eu-central-1.amazonaws.com/v200/2021/map/ESA_WorldCover_10m_2021_v200_S03E036_Map.tif"
WC_RAW = "data/raw/esa_worldcover_nairobi.tif"
CLIPPED_WC = "data/interim/nairobi_landcover_10m.tif"

if not os.path.exists(CLIPPED_WC):
    download_file(WORLDCOVER_URL, WC_RAW)
    
    print("Clipping ESA WorldCover tile to Nairobi boundaries...")
    nairobi_geo = gpd.GeoDataFrame({'geometry': [poly_box]}, crs="EPSG:4326")
    with rasterio.open(WC_RAW) as src:
        out_image, out_transform = mask(src, nairobi_geo.geometry, crop=True)
        out_meta = src.meta.copy()
        
    out_meta.update({
        "driver": "GTiff",
        "height": out_image.shape[1],
        "width": out_image.shape[2],
        "transform": out_transform
    })
    
    with rasterio.open(CLIPPED_WC, "w", **out_meta) as dest:
        dest.write(out_image)
    print(f"Saved clipped 10m land cover to {CLIPPED_WC}")
    os.remove(WC_RAW)