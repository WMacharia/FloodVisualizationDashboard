"""
PHASE 8c: BUILDING COVERAGE FRACTION ON THE 30 m MASTER GRID

Buildings are smaller than a 30 m cell, so they cannot be represented as walls in the
terrain. Instead each cell gets the fraction of its area covered by building footprints.
08_simulate_flood_scenarios.py uses it as storage/conveyance porosity (Defina, 2000;
Sanders et al., 2008) and in a composite curve number; 09 adds it as a U-Net input.

Source : Microsoft Global ML Building Footprints (Kenya), clipped by 01_download_data.py
         -> data/interim/nairobi_buildings.gpkg
Method : rasterise footprints on a grid 10x finer (~3 m) than the master grid,
         then average each 10 x 10 block -> fraction 0..1 per 30 m cell.
Output : data/aligned/aligned_building_frac.tif
"""
import os
import numpy as np
import geopandas as gpd
import rasterio
from rasterio.features import rasterize
from affine import Affine

SUPERSAMPLE = 10

script_dir = os.path.dirname(os.path.abspath(__file__))
aligned_dir = os.path.join(script_dir, "data", "aligned")
buildings_path = os.path.join(script_dir, "data", "interim", "nairobi_buildings.gpkg")
out_path = os.path.join(aligned_dir, "aligned_building_frac.tif")

with rasterio.open(os.path.join(aligned_dir, "aligned_fabdem.tif")) as src:
    profile = src.profile
    transform = src.transform
    H, W = src.shape

print("1. Reading building footprints...")
gdf = gpd.read_file(buildings_path)
gdf = gdf[gdf.geometry.notna() & ~gdf.geometry.is_empty]
print(f"   {len(gdf):,} footprints")

print(f"2. Rasterising at 1/{SUPERSAMPLE} of a cell...")
fine_transform = transform * Affine.scale(1.0 / SUPERSAMPLE)
fine = rasterize(
    ((g, 1) for g in gdf.geometry),
    out_shape=(H * SUPERSAMPLE, W * SUPERSAMPLE),
    transform=fine_transform,
    fill=0,
    dtype="uint8",
)
frac = fine.reshape(H, SUPERSAMPLE, W, SUPERSAMPLE).mean(axis=(1, 3)).astype(np.float32)
del fine

lat_c = transform.f + transform.e * H / 2.0
cell_m2 = abs(transform.a) * 111320.0 * np.cos(np.radians(lat_c)) * abs(transform.e) * 110574.0
print(f"   Total building area: {frac.sum() * cell_m2 / 1e6:.1f} km2")
print(f"   Cells with any building: {(frac > 0).mean() * 100:.1f}%, mean fraction {frac.mean():.3f}, max {frac.max():.2f}")
for th in (0.2, 0.4, 0.6):
    print(f"   Cells > {th:.0%} covered: {(frac > th).mean() * 100:.2f}%")

profile.update(dtype=rasterio.float32, count=1, compress="lzw", nodata=None)
with rasterio.open(out_path, "w", **profile) as dst:
    dst.write(frac, 1)
    dst.update_tags(source="Microsoft Global ML Building Footprints (Kenya)", supersample=SUPERSAMPLE)
print(f"Saved {out_path}")
