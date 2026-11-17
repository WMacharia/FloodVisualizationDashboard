# Machine Learning and Backend Scripts

This directory contains the machine learning pipeline and backend development scripts for the **Flood Visualization Dashboard**.

## Language and Stack
- **Language**: Python 3.x
- **Key Libraries**: Listed in the root `requirements.txt` (PyTorch, FastAPI, NumPy/SciPy, Numba, rasterio, GeoPandas, pysheds).

## Virtual Environment (venv) Setup and Access
A virtual environment named `nairobi_flood_env` is located in this folder to manage dependencies and isolate the project environment.

### To Activate the Virtual Environment:
1. Open your terminal or command prompt.
2. Navigate to this `scripts` directory:
   ```cmd
   cd path\to\FloodVisualizationDashboard\scripts
   ```
3. Activate the environment (Windows):
   ```cmd
   nairobi_flood_env\Scripts\activate
   ```
   *(On Linux/macOS, it would be `source nairobi_flood_env/bin/activate`)*
4. Once activated, your terminal prompt should be prefixed with `(nairobi_flood_env)`.
5. To deactivate the environment when you are done, simply run:
   ```cmd
   deactivate
   ```

### Installing Dependencies
Ensure your environment is active, then install the required packages from the project root:
```cmd
pip install -r ../requirements.txt
```

## Directory Structure
- `data/`: Used for storing raw and processed datasets locally.
- `models/`: The trained U-Net (`unet_surrogate.pth`) and its config; earlier models are kept for comparison.
- `outputs/`: Contains generated outputs such as plots, evaluation metrics, logs, and predictions.
- `cache/`: Used for caching intermediate processing steps or downloaded files to speed up reruns.
- `nairobi_flood_env/`: The Python virtual environment.

## Pipeline

An Attention U-Net is trained to reproduce a physics-based flood simulator (a surrogate), so the dashboard
can map any storm in under a second. Scripts run in numerical order; the first project approach
(FastFlood / synthetic labels, Random Forest, XGBoost) is in `archive/legacy_pipeline/` and is not used.

| Step | Script | Output |
|---|---|---|
| Inputs | `01_data_aquisition.py` (Earth Engine) or `01d_download_missing.py` | raw DEM and land cover in `data/raw/` |
| | `01_download_data.py` | Microsoft building footprints, OSM drainage (`data/interim/`) |
| Grid | `02_align_master_grid.py` | 30 m master grid `data/aligned/aligned_dem.tif`, `aligned_esa.tif` |
| | `08a_download_fabdem.py` | bare-earth terrain on the grid (`aligned_fabdem.tif`) |
| | `08c_building_fraction.py` | building cover per cell (`aligned_building_frac.tif`) |
| Simulator | `08_simulate_flood_scenarios.py` | max depth and depth x velocity for 28 design storms (`data/scenarios/`) |
| Checks | `08b_validate_hotspots.py` | simulator vs reported April-May 2024 flood hotspots |
| | `08d_verify_analytical.py` | simulator code vs the analytical flat-plane flood wave |
| Dataset | `09_build_surrogate_dataset.py` | `data/surrogate/nairobi_surrogate_dataset.npz` (upload to Google Drive) |
| Training | `../Flood_Surrogate_Training.ipynb` (Colab, T4 GPU) | `models/unet_surrogate.pth` + `unet_surrogate_config.json` |
| Dashboard data | `10_build_dashboard_layers.py` | road graph, sub-counties, facilities (`data/dashboard/`) |
| Routing margins | `11_calibrate_vehicle_margins.py` | per-vehicle safety margins (`data/validation/vehicle_margins.json`) |
| Server | `model_api.py` | FastAPI on port 8001, called only by the Laravel app |

## Usage
```cmd
nairobi_flood_env\Scripts\activate
uvicorn model_api:app --host 127.0.0.1 --port 8001
```
Re-run `11_calibrate_vehicle_margins.py` after replacing the model; the server ignores margins fitted for a different model.
