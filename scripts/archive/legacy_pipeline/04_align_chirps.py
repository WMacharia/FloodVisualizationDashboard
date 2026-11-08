import os
import numpy as np
import rasterio
from rasterio.warp import reproject, Resampling

# Directory Setup
script_dir = os.path.dirname(os.path.abspath(__file__))
base_dir = os.path.dirname(script_dir)
raw_chirps_path = os.path.join(base_dir, "scripts", "data", "raw", "chirps_nairobi_20240424.tif")
master_dem_path = os.path.join(base_dir, "scripts", "data", "aligned", "aligned_dem.tif")
output_chirps_path = os.path.join(base_dir, "scripts", "data", "aligned", "aligned_chirps.tif")

print("1. Loading Master 30m Grid Profile...")
with rasterio.open(master_dem_path) as master:
    master_profile = master.profile

print("2. Resampling CHIRPS 5.5km Grid to 30m...")
with rasterio.open(raw_chirps_path) as src:
    aligned_chirps = np.empty((master_profile['height'], master_profile['width']), dtype='float32')
    
    # We use Bilinear resampling to smoothly interpolate the heavy rainfall across the city
    reproject(
        source=rasterio.band(src, 1),
        destination=aligned_chirps,
        src_transform=src.transform,
        src_crs=src.crs,
        dst_transform=master_profile['transform'],
        dst_crs=master_profile['crs'],
        resampling=Resampling.bilinear
    )

print("3. Saving Aligned Meteorological Tensor...")
# Handle any NoData edge pixels from Earth Engine clipping
aligned_chirps = np.nan_to_num(aligned_chirps, nan=0.0)

master_profile.update(dtype=rasterio.float32, count=1, compress='lzw')
with rasterio.open(output_chirps_path, 'w', **master_profile) as dst:
    dst.write(aligned_chirps, 1)

print("Success! The CHIRPS tensor is ready.")