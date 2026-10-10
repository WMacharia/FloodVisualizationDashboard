import ee
import os
import requests
import zipfile
import io

script_dir = os.path.dirname(os.path.abspath(__file__))
base_dir = os.path.dirname(script_dir)
raw_dir = os.path.join(base_dir, "scripts", "data", "raw")
os.makedirs(raw_dir, exist_ok=True)

# 1. Authenticate and Initialize
try:
    ee.Initialize(project='flood-prediction-nairobi')
except Exception as e:
    ee.Authenticate()
    ee.Initialize(project='flood-prediction-nairobi')

nairobi_geom = ee.Geometry.Rectangle([36.65, -1.45, 37.10, -1.15])

# 5 of the most devastating flash flood dates in recent Nairobi history
extreme_dates = ['2024-04-24', '2023-11-23', '2019-12-11', '2018-03-15', '2015-05-13']

for date_str in extreme_dates:
    print(f"Fetching CHIRPS data for {date_str}...")
    dataset = ee.ImageCollection('UCSB-CHG/CHIRPS/DAILY') \
                .filterDate(ee.Date(date_str), ee.Date(date_str).advance(1, 'day'))
    
    if dataset.size().getInfo() == 0:
        print(f"  No data found for {date_str}")
        continue
        
    img = dataset.first().clip(nairobi_geom)
    
    url = img.getDownloadURL({
        'scale': 5566, # Native CHIRPS resolution (~5.5km)
        'crs': 'EPSG:4326',
        'region': nairobi_geom,
        'format': 'GEO_TIFF'
    })
    
    response = requests.get(url)
    
    # Write the raw .tif binary directly to disk
    final_path = os.path.join(raw_dir, f"chirps_{date_str.replace('-', '')}.tif")
    with open(final_path, 'wb') as f:
        f.write(response.content)
        
    print(f"  Saved: {final_path}")

print("All historical precipitation data downloaded!")