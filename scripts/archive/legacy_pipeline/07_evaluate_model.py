import os
import joblib
import pandas as pd
import numpy as np
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score
from sklearn.metrics import precision_score, recall_score

print("=======================================================")
print("STARTING PHASE 7: 10CM HAZARD EVALUATION")
print("=======================================================")

os.makedirs("outputs", exist_ok=True)
models = joblib.load("models/flood_hurdle_model.joblib")
clf = models["gatekeeper"]
reg = models["depth_gauge"]

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

print(f"1. Running Dual-Inference across {len(X_test):,} unseen pixels...")

# 1. Gatekeeper predictions (Standard 50% threshold)
flood_probs = clf.predict_proba(X_test)[:, 1]
is_flooded = (flood_probs > 0.50).astype(int)

# 2. Depth Gauge predicts physical depth
raw_depths = reg.predict(X_test)

# 3. Combine Hurdle output
final_predicted_depths = is_flooded * raw_depths

# Define ground truth Hazard vs Safe
y_test_binary = (y_test > 0.1).astype(int)
flooded_mask = y_test > 0.1

# -------------------------------------------------------------------------
# METRIC 1: HAZARD ROUTING (Gatekeeper)
# -------------------------------------------------------------------------
print("\n2. GATEKEEPER METRICS (Detecting >10cm Hazards):")
precision = precision_score(y_test_binary, is_flooded, zero_division=0)
recall = recall_score(y_test_binary, is_flooded, zero_division=0)

print(f"   Precision: {precision:.3f} (True hazard rate when flagged)")
print(f"   Recall:    {recall:.3f} (Percentage of actual hazards found)")

if flooded_mask.sum() > 0:
    # -------------------------------------------------------------------------
    # METRIC 2: ISOLATED PHYSICS ENGINE (The Regressor Alone)
    # -------------------------------------------------------------------------
    print("\n3. PURE PHYSICS ACCURACY (Inside Actual Hazards):")
    # How well does the Regressor do when we look purely at the flooded areas?
    pure_rmse = np.sqrt(mean_squared_error(y_test[flooded_mask], raw_depths[flooded_mask]))
    pure_r2 = r2_score(y_test[flooded_mask], raw_depths[flooded_mask])
    
    print(f"   RMSE (Pure Regressor): {pure_rmse:.4f} meters")
    print(f"   R2 Score (Pure Regressor):  {pure_r2:.4f}")

    # -------------------------------------------------------------------------
    # METRIC 3: COMBINED HURDLE MODEL
    # -------------------------------------------------------------------------
    print("\n4. FINAL COMBINED PIPELINE (Gatekeeper + Regressor):")
    comb_rmse = np.sqrt(mean_squared_error(y_test[flooded_mask], final_predicted_depths[flooded_mask]))
    comb_r2 = r2_score(y_test[flooded_mask], final_predicted_depths[flooded_mask])
    
    print(f"   RMSE (Combined System): {comb_rmse:.4f} meters")
    print(f"   R2 Score (Combined System):  {comb_r2:.4f}")

print("=======================================================")
print("[SUCCESS] Phase 7 Complete.")
print("=======================================================")