import os
import numpy as np
import rasterio
from rasterio.warp import reproject, Resampling
from scipy.ndimage import gaussian_filter

# 1. Set up dynamic directory paths
script_dir = os.path.dirname(os.path.abspath(__file__))
base_dir = os.path.dirname(script_dir)
raw_dir = os.path.join(base_dir, "scripts", "data", "raw")
aligned_dir = os.path.join(base_dir, "scripts", "data", "aligned")

os.makedirs(aligned_dir, exist_ok=True)

dem_path = os.path.join(raw_dir, "alos_dem_nairobi.tif")
flow_path = os.path.join(raw_dir, "flow_acc_nairobi.tif")
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

print("1. Loading Master 30m Elevation Grid...")
with rasterio.open(dem_path) as master:
    master_profile = master.profile
    dem_array = master.read(1).astype('float32')

print("2. Resampling and Snapping Land Cover and Flow Accumulation...")
flow_array = align_raster(flow_path, master_profile, Resampling.bilinear)
esa_array = align_raster(esa_path, master_profile, Resampling.nearest)

print("3. Conditioning DEM and Calculating Terrain Slope...")
# THE FIX: Clip out extreme float32 NoData (-3.4e38) to prevent Gaussian overflow
dem_array = np.clip(dem_array, 0, 5000) 
flow_array = np.maximum(flow_array, 0)

dem_smooth = gaussian_filter(dem_array, sigma=1.0)
dy, dx = np.gradient(dem_smooth, 30, 30)
slope = np.arctan(np.sqrt(dx**2 + dy**2))
slope = np.maximum(slope, 0.05)

print("4. Computing Conditioned TWI...")
catchment_area = (flow_array + 1) * 900 

with np.errstate(divide='ignore', invalid='ignore'):
    twi = np.log(catchment_area / np.tan(slope))
    twi = np.nan_to_num(twi, nan=0.0)

print("5. Generating Ground-Truth Hazard Target...")
# Isolate active flow channels to prevent flat plains from being flagged as floods
flow_mask = flow_array > np.percentile(flow_array[flow_array > 0], 50)

# Calculate threshold only on valid channels
hazard_threshold = np.nanpercentile(twi[flow_mask], 90)
target_array = np.where((twi >= hazard_threshold) & flow_mask, 1, 0).astype('uint8')

print("6. Saving Unified U-Net Tensors...")
master_profile.update(dtype=rasterio.float32, count=1, compress='lzw')
with rasterio.open(os.path.join(aligned_dir, "aligned_dem.tif"), 'w', **master_profile) as dst: dst.write(dem_array, 1)
with rasterio.open(os.path.join(aligned_dir, "aligned_flow.tif"), 'w', **master_profile) as dst: dst.write(flow_array, 1)

master_profile.update(dtype=rasterio.uint8)
with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif"), 'w', **master_profile) as dst: dst.write(esa_array, 1)
with rasterio.open(os.path.join(aligned_dir, "flood_target.tif"), 'w', **master_profile) as dst: dst.write(target_array, 1)

print("Success! All 4 matrices are perfectly stacked and ready for the CNN.")