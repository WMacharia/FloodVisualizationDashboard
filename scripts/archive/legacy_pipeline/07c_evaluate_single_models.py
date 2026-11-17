import os
import joblib
import pandas as pd
import numpy as np
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score

print("=======================================================")
print("STARTING PHASE 7C: SINGLE-STAGE EVALUATION")
print("=======================================================")

models = joblib.load("models/single_stage_models.joblib")
rf_reg = models["rf_single"]
xgb_reg = models["xgb_single"]

test_df = pd.read_parquet("data/processed/test_data.parquet")

FEATURES = [
    "flow_acc", 
    "slope_deg", 
    "twi", 
    "hand", 
    "mannings_n", 
    "rainfall_mm", 
    "building_density"
]
X_test = test_df[FEATURES]
y_test = test_df["flood_depth"]

print(f"1. Running Inference across {len(X_test):,} unseen pixels...")
rf_preds = rf_reg.predict(X_test)
xgb_preds = xgb_reg.predict(X_test)

# We evaluate performance inside the actual hazard zones (>10cm) 
# to keep the comparison fair against the Two-Stage models.
flooded_mask = y_test > 0.1

if flooded_mask.sum() > 0:
    print("\n2. RANDOM FOREST (Single-Stage) ACCURACY:")
    rf_rmse = np.sqrt(mean_squared_error(y_test[flooded_mask], rf_preds[flooded_mask]))
    rf_r2 = r2_score(y_test[flooded_mask], rf_preds[flooded_mask])
    print(f"   RMSE: {rf_rmse:.4f} meters")
    print(f"   R2 Score:  {rf_r2:.4f}")

    print("\n3. XGBOOST (Single-Stage) ACCURACY:")
    xgb_rmse = np.sqrt(mean_squared_error(y_test[flooded_mask], xgb_preds[flooded_mask]))
    xgb_r2 = r2_score(y_test[flooded_mask], xgb_preds[flooded_mask])
    print(f"   RMSE: {xgb_rmse:.4f} meters")
    print(f"   R2 Score:  {xgb_r2:.4f}")

print("=======================================================")
print("[SUCCESS] Phase 7C Complete.")
print("=======================================================")