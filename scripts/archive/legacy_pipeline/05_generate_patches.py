import os
import glob
import numpy as np
import rasterio
from rasterio.warp import reproject, Resampling

script_dir = os.path.dirname(os.path.abspath(__file__))
base_dir = os.path.dirname(script_dir)
raw_dir = os.path.join(base_dir, "scripts", "data", "raw")
aligned_dir = os.path.join(base_dir, "scripts", "data", "aligned")
patches_dir = os.path.join(base_dir, "scripts", "data", "patches")
os.makedirs(patches_dir, exist_ok=True)

def align_chirps_to_master(src_path, master_profile):
    """Dynamically aligns a raw CHIRPS raster to the 30m DEM resolution in memory."""
    with rasterio.open(src_path) as src:
        aligned_data = np.empty((master_profile['height'], master_profile['width']), dtype='float32')
        reproject(
            source=rasterio.band(src, 1),
            destination=aligned_data,
            src_transform=src.transform,
            src_crs=src.crs,
            dst_transform=master_profile['transform'],
            dst_crs=master_profile['crs'],
            resampling=Resampling.bilinear
        )
    return aligned_data

print("1. Loading Static Topography (DEM, ESA, Flow, Target)...")
with rasterio.open(os.path.join(aligned_dir, "aligned_dem.tif")) as src: 
    master_profile = src.profile
    dem = src.read(1)
with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif")) as src: esa = src.read(1)
with rasterio.open(os.path.join(aligned_dir, "aligned_flow.tif")) as src: flow = src.read(1)
with rasterio.open(os.path.join(aligned_dir, "flood_target.tif")) as src: target = src.read(1)

y_stack = target.astype('uint8')
patch_size = 128
stride = 64
h, w = target.shape
patch_idx = 0

chirps_files = glob.glob(os.path.join(raw_dir, "chirps_*.tif"))
print(f"2. Found {len(chirps_files)} historical storm events. Processing patches...")

for chirps_path in chirps_files:
    event_name = os.path.basename(chirps_path).replace('.tif', '')
    print(f"  -> Slicing patches for {event_name}...")
    
    chirps = align_chirps_to_master(chirps_path, master_profile)
    X_stack = np.stack([dem, esa, flow, chirps], axis=0).astype('float32')
    
    # Min-Max Normalization
    for i in range(4):
        b_min, b_max = np.min(X_stack[i]), np.max(X_stack[i])
        if b_max - b_min > 0:
            X_stack[i] = (X_stack[i] - b_min) / (b_max - b_min)
        else:
            X_stack[i] = 0

    # Slice Patches
    for y in range(0, h - patch_size + 1, stride):
        for x in range(0, w - patch_size + 1, stride):
            y_patch = y_stack[y:y+patch_size, x:x+patch_size]
            flood_ratio = np.sum(y_patch) / (patch_size ** 2)
            
            # Keep patch if it has > 1% flood pixels OR randomly keep 10% of dry patches
            if flood_ratio > 0.01 or np.random.rand() < 0.10:
                X_patch = X_stack[:, y:y+patch_size, x:x+patch_size]
                np.save(os.path.join(patches_dir, f"X_{patch_idx}.npy"), X_patch)
                np.save(os.path.join(patches_dir, f"y_{patch_idx}.npy"), y_patch)
                patch_idx += 1

print(f"Success! Generated a massive dataset of {patch_idx} extreme-weather training tensors.")