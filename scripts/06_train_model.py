import os
import time
import joblib
import pandas as pd
from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor

print("=======================================================")
print("STARTING PHASE 6: 10CM HAZARD HURDLE MODEL TRAINING")
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
# FIX: Ignore puddles. 0 = Safe/Runoff (<10cm), 1 = Hazard (>10cm)
y_train_binary = (y_train > 0.1).astype(int)

clf = RandomForestClassifier(
    n_estimators=100,
    max_depth=20,
    class_weight="balanced",
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
# FIX: Train the physics engine strictly on actual hazard floods (>10cm)
wet_mask = y_train > 0.1
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