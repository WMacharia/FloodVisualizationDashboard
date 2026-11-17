"""
PHASE 8a: DOWNLOAD FABDEM (bare-earth terrain) AND ALIGN IT TO THE MASTER GRID

ALOS AW3D30 'DSM' includes building roofs and tree canopy, which creates artificial
pits that the flood simulator fills with water. FABDEM (Hawker et al., 2022,
Environmental Research Letters 17, 024016) is Copernicus GLO-30 with buildings and
forests removed, designed for flood modelling.

Source: Earth Engine community catalog, projects/sat-io/open-datasets/FABDEM
Output: data/aligned/aligned_fabdem.tif (same grid as aligned_dem.tif)
"""
import os
import io
import ee
import numpy as np
import requests
import rasterio
from rasterio.warp import reproject, Resampling

PROJECT_ID = 'flood-prediction-nairobi'

script_dir = os.path.dirname(os.path.abspath(__file__))
raw_dir = os.path.join(script_dir, "data", "raw")
aligned_dir = os.path.join(script_dir, "data", "aligned")
raw_path = os.path.join(raw_dir, "fabdem_nairobi.tif")
out_path = os.path.join(aligned_dir, "aligned_fabdem.tif")

print("1. Initialising Earth Engine...")
try:
    ee.Initialize(project=PROJECT_ID)
except Exception:
    ee.Authenticate()
    ee.Initialize(project=PROJECT_ID)

# Slightly larger than the master grid so edges are covered after resampling
roi = ee.Geometry.Rectangle([36.64, -1.46, 37.11, -1.14])

print("2. Downloading FABDEM for Nairobi...")
fabdem = ee.ImageCollection("projects/sat-io/open-datasets/FABDEM").mosaic().clip(roi)
url = fabdem.getDownloadURL({
    'scale': 30,
    'crs': 'EPSG:4326',
    'region': roi,
    'format': 'GEO_TIFF'
})
resp = requests.get(url, timeout=300)
resp.raise_for_status()
with open(raw_path, 'wb') as f:
    f.write(resp.content)
print(f"   Saved: {raw_path}")

print("3. Aligning to the 30 m master grid...")
with rasterio.open(os.path.join(aligned_dir, "aligned_dem.tif")) as master:
    profile = master.profile
with rasterio.open(raw_path) as src:
    aligned = np.empty((profile['height'], profile['width']), dtype='float32')
    reproject(
        source=rasterio.band(src, 1),
        destination=aligned,
        src_transform=src.transform,
        src_crs=src.crs,
        dst_transform=profile['transform'],
        dst_crs=profile['crs'],
        resampling=Resampling.bilinear
    )

bad = ~np.isfinite(aligned) | (aligned < 500) | (aligned > 5000)
print(f"   Invalid cells: {int(bad.sum())}  range {np.nanmin(aligned[~bad]):.1f}-{np.nanmax(aligned[~bad]):.1f} m")

profile.update(dtype=rasterio.float32, count=1, compress='lzw', nodata=None)
with rasterio.open(out_path, 'w', **profile) as dst:
    dst.write(aligned, 1)
print(f"Success! Bare-earth DEM saved to: {out_path}")
