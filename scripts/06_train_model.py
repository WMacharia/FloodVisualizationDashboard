import os
import time
import joblib
import pandas as pd
from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor

print("=======================================================")
print("STARTING PHASE 6: TWO-STAGE HURDLE MODEL ARCHITECTURE")
print("=======================================================")

os.makedirs("models", exist_ok=True)
print("1. Loading FULL spatial training data...")
train_df = pd.read_parquet("data/processed/train_data.parquet")

FEATURES = [
    "elevation", 
    "rainfall_mm", 
    "building_density", 
    "dist_drainage_m", 
    "mannings_n", 
    "slope_deg", 
    "twi"
    ]
TARGET = "flood_depth"

X_train = train_df[FEATURES]
y_train = train_df[TARGET]

# -------------------------------------------------------------------------
# STAGE 1: THE GATEKEEPER (CLASSIFIER)
# -------------------------------------------------------------------------
print("\n2. Training STAGE 1: Flood Detection (Classifier)...")
# Create binary target: 0 = Safe, 1 = Flooded
y_train_binary = (y_train > 0.0).astype(int)

clf = RandomForestClassifier(
    n_estimators=100,
    max_depth=20,
    class_weight="balanced", # Neutralizes the 99% dry land bias
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
clf.fit(X_train, y_train_binary)
print(f"   [OK] Gatekeeper trained in {time.time() - t0:.1f} seconds.")

# -------------------------------------------------------------------------
# STAGE 2: THE DEPTH GAUGE (REGRESSOR)
# -------------------------------------------------------------------------
print("\n3. Training STAGE 2: Depth Estimation (Regressor)...")
# FILTER: We ONLY train this model on actual water!
wet_mask = y_train > 0.0
X_train_wet = X_train[wet_mask]
y_train_wet = y_train[wet_mask]

reg = RandomForestRegressor(
    n_estimators=100,
    max_depth=20,
    n_jobs=-1,
    random_state=42
)
t0 = time.time()
reg.fit(X_train_wet, y_train_wet)
print(f"   [OK] Depth Gauge trained in {time.time() - t0:.1f} seconds.")

print("\n4. Saving Dual-Model Architecture...")
model_dict = {"gatekeeper": clf, "depth_gauge": reg}
joblib.dump(model_dict, "models/flood_hurdle_model.joblib", compress=3)
print("   [OK] Saved -> models/flood_hurdle_model.joblib")
print("=======================================================")