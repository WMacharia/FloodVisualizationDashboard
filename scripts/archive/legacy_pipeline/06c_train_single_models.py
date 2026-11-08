import os
import time
import joblib
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
import xgboost as xgb

print("=======================================================")
print("STARTING PHASE 6C: SINGLE-STAGE REGRESSOR TRAINING")
print("=======================================================")

os.makedirs("models", exist_ok=True)
print("1. Loading FULL spatial training data (Dry + Wet land)...")
train_df = pd.read_parquet("data/processed/train_data.parquet")

FEATURES = [
    "flow_acc", 
    "slope_deg", 
    "twi", 
    "hand", 
    "mannings_n", 
    "rainfall_mm", 
    "building_density"
]
TARGET = "flood_depth"

X_train = train_df[FEATURES]
y_train = train_df[TARGET]

# -------------------------------------------------------------------------
# 1. SINGLE-STAGE RANDOM FOREST
# -------------------------------------------------------------------------
print("\n2. Training Single-Stage Random Forest Regressor...")
rf_reg = RandomForestRegressor(
    n_estimators=100,
    max_depth=20,
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
rf_reg.fit(X_train, y_train)
print(f"   [OK] RF trained in {time.time() - t0:.1f} seconds.")

# -------------------------------------------------------------------------
# 2. SINGLE-STAGE XGBOOST
# -------------------------------------------------------------------------
print("\n3. Training Single-Stage XGBoost Regressor...")
xgb_reg = xgb.XGBRegressor(
    n_estimators=200,
    max_depth=8,
    learning_rate=0.1,
    tree_method="hist",  # Handles the zero-inflation heavily optimized
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
xgb_reg.fit(X_train, y_train)
print(f"   [OK] XGBoost trained in {time.time() - t0:.1f} seconds.")

print("\n4. Saving Single-Stage Models...")
model_dict = {"rf_single": rf_reg, "xgb_single": xgb_reg}
joblib.dump(model_dict, "models/single_stage_models.joblib", compress=3)
print("   [OK] Saved -> models/single_stage_models.joblib")
print("=======================================================")