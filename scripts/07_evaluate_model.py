import os
import joblib
import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
from sklearn.metrics import mean_squared_error, mean_absolute_error, r2_score
from sklearn.metrics import precision_score, recall_score

print("=======================================================")
print("STARTING PHASE 7: TWO-STAGE EVALUATION")
print("=======================================================")

os.makedirs("outputs", exist_ok=True)
models = joblib.load("models/flood_hurdle_model.joblib")
clf = models["gatekeeper"]
reg = models["depth_gauge"]

test_df = pd.read_parquet("data/processed/test_data.parquet")
FEATURES = ["elevation", "rainfall_mm", "building_density", "dist_drainage_m", "mannings_n", "slope_deg", "twi"]
X_test = test_df[FEATURES]
y_test = test_df["flood_depth"]

print(f"1. Running Dual-Inference across {len(X_test):,} unseen pixels...")

# 1. Gatekeeper predicts PROBABILITY of flood. 
# We require 75% confidence to call it a flood, killing false-positive noise.
flood_probs = clf.predict_proba(X_test)[:, 1]
is_flooded = (flood_probs > 0.75).astype(int)

# 2. Depth Gauge predicts physical depth for ALL pixels
raw_depths = reg.predict(X_test)

# 3. Combine: If Gatekeeper says Safe (0), depth is 0. If Flooded (1), keep the depth.
final_predicted_depths = is_flooded * raw_depths

# -------------------------------------------------------------------------
# METRIC 1: HAZARD ROUTING (Did we find the floods without false alarms?)
# -------------------------------------------------------------------------
print("\n2. ROUTING ENGINE METRICS (Hazard Detection):")
y_test_binary = (y_test > 0).astype(int)
precision = precision_score(y_test_binary, is_flooded, zero_division=0)
recall = recall_score(y_test_binary, is_flooded, zero_division=0)

print(f"   Precision: {precision:.3f} (When it alerts, it is correct {precision*100:.1f}% of the time)")
print(f"   Recall:    {recall:.3f} (It successfully mapped {recall*100:.1f}% of actual flood zones)")

# -------------------------------------------------------------------------
# METRIC 2: PHYSICAL DEPTH ACCURACY (In Meters)
# -------------------------------------------------------------------------
print("\n3. CONTINUOUS DEPTH METRICS (Physical Accuracy):")
# Now that zero-inflation is handled cleanly by the Gatekeeper, 
# we can look at the physical error inside the wet zones.
flooded_mask = y_test > 0
if flooded_mask.sum() > 0:
    rmse = np.sqrt(mean_squared_error(y_test[flooded_mask], final_predicted_depths[flooded_mask]))
    mae = mean_absolute_error(y_test[flooded_mask], final_predicted_depths[flooded_mask])
    r2 = r2_score(y_test[flooded_mask], final_predicted_depths[flooded_mask])
    
    print(f"   RMSE (Inside Floods): {rmse:.4f} meters")
    print(f"   MAE (Inside Floods):  {mae:.4f} meters")
    print(f"   R2 Score (Inside Floods):  {r2:.4f}")

print("=======================================================")
print("[SUCCESS] Phase 7 Complete.")
print("=======================================================")