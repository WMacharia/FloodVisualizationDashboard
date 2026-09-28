# Machine Learning and Backend Scripts

This directory contains the machine learning pipeline and backend development scripts for the **Flood Visualization Dashboard**.

## Language and Stack
- **Language**: Python 3.x
- **Key Libraries**: Listed in the root `requirements.txt` file (typically includes `pandas`, `scikit-learn`, `xgboost`, `geopandas`, etc.).

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
- `models/`: Stores the trained and serialized machine learning models (e.g., `.pkl` or `.joblib` files) for later use in inference.
- `outputs/`: Contains generated outputs such as plots, evaluation metrics, logs, and predictions.
- `cache/`: Used for caching intermediate processing steps or downloaded files to speed up reruns.
- `nairobi_flood_env/`: The Python virtual environment.

## Script Workflow & Contents

The scripts follow a numerical prefix convention to indicate the order of execution for the machine learning pipeline:

### 1. Data Collection & Setup
- `01_download_data.py`: Main script for downloading required datasets (e.g., weather, elevation, historical flood data).
- `01b_download_chirps.py`: Downloads specific CHIRPS precipitation data.
- `01c_verify_env.py`: A quick utility to verify that the environment, dependencies, and paths are correctly configured.
- `01d_download_missing.py`: Fetches any remaining or missing data that failed in initial downloads.

### 2. Data Processing & Engineering
- `02_preprocess.py`: Cleans raw data, handles missing values, and normalizes formats.
- `03_feature_engineering.py`: Creates new predictive features (e.g., rolling averages for rainfall, slope calculation from elevation) to improve model accuracy.
- `04_eda.py`: Exploratory Data Analysis (EDA) script used to generate insights, statistical summaries, and visual plots of the dataset.

### 3. Model Preparation
- `05_spatial_split.py`: Splits the data into training and testing sets, ensuring spatial considerations are respected (e.g., to prevent data leakage across adjacent regions).
- `05b_check_feature_drift.py`: Checks for data drift between historical training data and newer validation data.

### 4. Model Training
- `06_train_model.py`: General training script, potentially used for baseline models or ensemble training.
- `06b_train_xgboost.py`: Trains an XGBoost specific model for flood prediction.
- `06c_train_single_models.py`: Trains standalone individual models for comparison.

### 5. Model Evaluation
- `07_evaluate_model.py`: General evaluation script to calculate metrics like Accuracy, Precision, Recall, and F1-Score.
- `07b_evaluate_xgboost.py`: Evaluates the performance of the XGBoost model specifically.
- `07c_evaluate_single_models.py`: Evaluates the baseline individual models to compare against the ensemble or primary models.

## Usage
To run the full pipeline, activate the virtual environment and execute the scripts in numerical order:
```cmd
python 01_download_data.py
python 02_preprocess.py
python 03_feature_engineering.py
...
```
