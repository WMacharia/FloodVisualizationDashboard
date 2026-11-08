import os
import gzip
import requests
import rasterio
from rasterio.mask import mask
from shapely.geometry import box
import geopandas as gpd

# Target directories
os.makedirs("data/raw", exist_ok=True)
os.makedirs("data/interim", exist_ok=True)

# CHIRPS v2.0 Africa Monthly Archive (May 2025)
CHIRPS_URL = "https://data.chc.ucsb.edu/products/CHIRPS-2.0/africa_monthly/tifs/chirps-v2.0.2025.05.tif.gz"
GZ_PATH = "data/interim/chirps_africa_may2025.tif.gz"
TIF_PATH = "data/interim/chirps_africa_uncompressed.tif"
FINAL_PATH = "data/raw/chirps_may2025.tif"

if not os.path.exists(FINAL_PATH):
    print(f"Downloading CHIRPS Africa grid from UC Santa Barbara...")
    with requests.get(CHIRPS_URL, stream=True) as r:
        r.raise_for_status()
        with open(GZ_PATH, 'wb') as f:
            for chunk in r.iter_content(chunk_size=8192):
                f.write(chunk)
                
    print("Decompressing GZ archive...")
    with gzip.open(GZ_PATH, 'rb') as f_in:
        with open(TIF_PATH, 'wb') as f_out:
            f_out.write(f_in.read())

    print("Clipping to Nairobi County boundaries...")
    nairobi_bbox = box(36.65, -1.45, 37.10, -1.15)
    geo = gpd.GeoDataFrame({'geometry': [nairobi_bbox]}, crs="EPSG:4326")

    with rasterio.open(TIF_PATH) as src:
        out_image, out_transform = mask(src, geo.geometry, crop=True)
        out_meta = src.meta.copy()

    out_meta.update({
        "driver": "GTiff",
        "height": out_image.shape[1],
        "width": out_image.shape[2],
        "transform": out_transform
    })

    with rasterio.open(FINAL_PATH, "w", **out_meta) as dest:
        dest.write(out_image)

    # Cleanup heavy continental files
    os.remove(GZ_PATH)
    os.remove(TIF_PATH)
    print(f"Saved optimized target to {FINAL_PATH}")
else:
    print(f"CHIRPS data already exists at {FINAL_PATH}")