"""
PHASE 2: THE 30 m MASTER GRID

Defines the grid every later step uses (1114 x 1671 cells over Nairobi): the ALOS 30 m DEM is the master,
and ESA WorldCover is snapped onto it.

  data/raw/alos_dem_nairobi.tif          -> data/aligned/aligned_dem.tif  (grid definition; 08a puts FABDEM on it)
  data/raw/esa_worldcover_nairobi.tif    -> data/aligned/aligned_esa.tif  (land cover: curve number, roughness)

Raw files come from 01_data_aquisition.py (Earth Engine) or 01d_download_missing.py.
This script used to build a synthetic flood label from TWI as well; that label is no longer used (the
U-Net now learns from the simulator, 08). The original is in archive/legacy_pipeline/.
"""
import os
import numpy as np
import rasterio
from rasterio.warp import reproject, Resampling

script_dir = os.path.dirname(os.path.abspath(__file__))
raw_dir = os.path.join(script_dir, "data", "raw")
aligned_dir = os.path.join(script_dir, "data", "aligned")
os.makedirs(aligned_dir, exist_ok=True)

dem_path = os.path.join(raw_dir, "alos_dem_nairobi.tif")
esa_path = os.path.join(raw_dir, "esa_worldcover_nairobi.tif")


def align_raster(src_path, master_profile, resampling_method):
    with rasterio.open(src_path) as src:
        aligned_data = np.empty((master_profile['height'], master_profile['width']), dtype=master_profile['dtype'])
        reproject(
            source=rasterio.band(src, 1),
            destination=aligned_data,
            src_transform=src.transform,
            src_crs=src.crs,
            dst_transform=master_profile['transform'],
            dst_crs=master_profile['crs'],
            resampling=resampling_method
        )
    return aligned_data


print("1. Loading master 30 m elevation grid...")
with rasterio.open(dem_path) as master:
    master_profile = master.profile
    dem_array = master.read(1).astype('float32')

print("2. Snapping land cover to the grid...")
esa_array = align_raster(esa_path, master_profile, Resampling.nearest)

# Clip float32 NoData (-3.4e38) and spikes
dem_array = np.clip(dem_array, 0, 5000)

print("3. Saving aligned rasters...")
master_profile.update(dtype=rasterio.float32, count=1, compress='lzw')
with rasterio.open(os.path.join(aligned_dir, "aligned_dem.tif"), 'w', **master_profile) as dst:
    dst.write(dem_array, 1)

master_profile.update(dtype=rasterio.uint8)
with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif"), 'w', **master_profile) as dst:
    dst.write(esa_array, 1)

print(f"Done: grid {dem_array.shape[0]} x {dem_array.shape[1]}")
