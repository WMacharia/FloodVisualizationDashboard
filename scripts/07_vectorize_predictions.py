import os
import numpy as np
import rasterio
from rasterio.features import shapes
import geopandas as gpd
from shapely.geometry import shape

def vectorize_predictions():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    output_dir = os.path.join(script_dir, "data", "output")
    input_tif = os.path.join(output_dir, "nairobi_flood_prediction.tif")
    output_geojson = os.path.join(output_dir, "flood_hazard_zones.geojson")

    print(f"Reading prediction raster: {input_tif}")
    with rasterio.open(input_tif) as src:
        prob_map = src.read(1)
        transform = src.transform
        crs = src.crs

    # 1. Bucket the floating-point probabilities into integer hazard levels
    # 0 = Safe (< 0.50)
    # 1 = Low Hazard / Warning (0.50 - 0.65)
    # 2 = High Hazard (0.66 - 0.85)
    # 3 = Critical Flash Flood (> 0.85)
    print("Categorizing hazard levels...")
    hazard_classes = np.zeros_like(prob_map, dtype=np.int32)
    hazard_classes[(prob_map >= 0.50) & (prob_map < 0.66)] = 1
    hazard_classes[(prob_map >= 0.66) & (prob_map < 0.86)] = 2
    hazard_classes[prob_map >= 0.86] = 3

    # 2. Extract vector polygons for all areas where hazard > 0
    print("Extracting vector polygons...")
    mask = hazard_classes > 0
    results = (
        {'properties': {'hazard_level': int(v)}, 'geometry': s}
        for i, (s, v) 
        in enumerate(shapes(hazard_classes, mask=mask, transform=transform))
    )

    # 3. Convert to a GeoDataFrame and save as GeoJSON
    print("Converting to GeoDataFrame...")
    geometries = list(results)
    
    if not geometries:
        print("No flood zones detected above the 0.5 threshold.")
        return

    gdf = gpd.GeoDataFrame.from_features(geometries, crs=crs)
    
    print(f"Saving {len(gdf)} hazard polygons to GeoJSON...")
    # Optional: Dissolve adjacent polygons of the same hazard level to optimize database performance
    gdf = gdf.dissolve(by='hazard_level').reset_index()
    
    gdf.to_file(output_geojson, driver='GeoJSON')
    print(f"Success! Vector data saved to: {output_geojson}")

if __name__ == "__main__":
    vectorize_predictions()