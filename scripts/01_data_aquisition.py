# ============================================================
# PHASE 1: DATA ACQUISITION (Earth Engine to Google Drive)
# ============================================================
import ee

print("1. Authenticating Google Earth Engine...")
# Replace 'your-project-id' with your actual Google Cloud Project ID (a string, in quotes)
PROJECT_ID = 'flood-prediction-nairobi' 

try:
    # Initialize with your specific project
    ee.Initialize(project=PROJECT_ID)
except Exception as e:
    print("Authentication needed. Opening browser...")
    ee.Authenticate()
    # Initialize again after authentication
    ee.Initialize(project=PROJECT_ID)

print("2. Defining Nairobi Bounding Box...")
# Precise spatial bounding box for Nairobi County
nairobi_roi = ee.Geometry.Rectangle([36.65, -1.45, 37.10, -1.15])

print("3. Fetching High-Res Spatial Matrices...")
# ALOS Global Digital Surface Model (30m resolution for Elevation)
dem = ee.ImageCollection("JAXA/ALOS/AW3D30/V4_1").select("DSM").mosaic().clip(nairobi_roi)

# ESA WorldCover Land Use (10m resolution for impermeable surfaces)
lulc = ee.ImageCollection("ESA/WorldCover/v200").first().clip(nairobi_roi)

# MERIT Hydro (90m resolution for Upstream Flow Accumulation)
flow_acc = ee.Image("MERIT/Hydro/v1_0_1").select("upa").clip(nairobi_roi)

print("4. Dispatching Exports to Google Drive...")
# Export Elevation
task_dem = ee.batch.Export.image.toDrive(
    image=dem,
    description='Nairobi_ALOS_DEM',
    folder='Flood_Project',
    fileNamePrefix='alos_dem_nairobi',
    region=nairobi_roi,
    scale=30,
    crs='EPSG:4326',
    maxPixels=1e9
)
task_dem.start()

# Export Land Cover
task_lulc = ee.batch.Export.image.toDrive(
    image=lulc,
    description='Nairobi_ESA_WorldCover',
    folder='Flood_Project',
    fileNamePrefix='esa_worldcover_nairobi',
    region=nairobi_roi,
    scale=10,
    crs='EPSG:4326',
    maxPixels=1e9
)
task_lulc.start()

# Export Flow Accumulation
task_flow = ee.batch.Export.image.toDrive(
    image=flow_acc,
    description='Nairobi_Flow_Accumulation',
    folder='Flood_Project',
    fileNamePrefix='flow_acc_nairobi',
    region=nairobi_roi,
    scale=90,
    crs='EPSG:4326',
    maxPixels=1e9
)
task_flow.start()

print("\n[SUCCESS] Tasks sent to Earth Engine servers.")
print("Check the 'Flood_Project' folder in your Google Drive in about 5-10 minutes.")