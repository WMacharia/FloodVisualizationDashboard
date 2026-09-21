import sys
import rasterio
import geopandas as gpd
import sklearn

print(f"[OK] Python version: {sys.version.split()[0]}")
print(f"[OK] Rasterio version: {rasterio.__version__}")
print(f"[OK] GeoPandas version: {gpd.__version__}")
print(f"[OK] Scikit-Learn version: {sklearn.__version__}")
print("[SUCCESS] All core spatial and ML libraries linked properly.")