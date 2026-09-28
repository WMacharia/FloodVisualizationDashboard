import os
import time
import joblib
import pandas as pd
import xgboost as xgb

print("=======================================================")
print("STARTING PHASE 6B: XGBOOST HURDLE MODEL TRAINING")
print("=======================================================")

os.makedirs("models", exist_ok=True)
print("1. Loading FULL spatial training data...")
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
# STAGE 1: THE GATEKEEPER (CLASSIFIER)
# -------------------------------------------------------------------------
print("\n2. Training STAGE 1: Hazard Detection (>10cm)...")
y_train_binary = (y_train > 0.1).astype(int)

# XGBoost Superpower: Mathematically weight the rare floods so it doesn't ignore them
num_safe = (y_train_binary == 0).sum()
num_hazard = (y_train_binary == 1).sum()
scale_weight = num_safe / num_hazard if num_hazard > 0 else 1.0

clf = xgb.XGBClassifier(
    n_estimators=200,
    max_depth=8,
    learning_rate=0.1,
    scale_pos_weight=scale_weight, # This forces it to respect the floods!
    tree_method="hist",            # Highly optimized for spatial data
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
clf.fit(X_train, y_train_binary)
print(f"   [OK] XGBoost Gatekeeper trained in {time.time() - t0:.1f} seconds.")

# -------------------------------------------------------------------------
# STAGE 2: THE DEPTH GAUGE (REGRESSOR)
# -------------------------------------------------------------------------
print("\n3. Training STAGE 2: Depth Estimation (Regressor)...")
wet_mask = y_train > 0.1
X_train_wet = X_train[wet_mask]
y_train_wet = y_train[wet_mask]

reg = xgb.XGBRegressor(
    n_estimators=200,
    max_depth=8,
    learning_rate=0.1,
    tree_method="hist",
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
reg.fit(X_train_wet, y_train_wet)
print(f"   [OK] XGBoost Depth Gauge trained in {time.time() - t0:.1f} seconds.")

print("\n4. Saving Dual-Model Architecture...")
model_dict = {"gatekeeper": clf, "depth_gauge": reg}
joblib.dump(model_dict, "models/xgboost_hurdle_model.joblib", compress=3)
print("   [OK] Saved -> models/xgboost_hurdle_model.joblib")
print("=======================================================")