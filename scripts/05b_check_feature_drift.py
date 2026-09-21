import pandas as pd

print("=======================================================")
print("PHASE 5b: TRAIN vs TEST FEATURE RANGE / DRIFT CHECK")
print("=======================================================")
print("Random forests cannot extrapolate beyond the feature ranges")
print("they were trained on. This checks whether the North (test)")
print("block asks the model to predict outside its training range.\n")

train_df = pd.read_parquet("data/processed/train_data.parquet")
test_df = pd.read_parquet("data/processed/test_data.parquet")

FEATURES = [
    "elevation", "rainfall_mm", "building_density",
    "dist_drainage_m", "mannings_n", "slope_deg", "twi"
]

header = f"{'feature':<18}{'train_min':>12}{'train_max':>12}{'test_min':>12}{'test_max':>12}{'out_of_range':>14}"
print(header)
print("-" * len(header))

flagged = []
for f in FEATURES:
    tr_min, tr_max = train_df[f].min(), train_df[f].max()
    te_min, te_max = test_df[f].min(), test_df[f].max()
    out_of_range_pct = ((test_df[f] < tr_min) | (test_df[f] > tr_max)).mean() * 100
    print(f"{f:<18}{tr_min:>12.3f}{tr_max:>12.3f}{te_min:>12.3f}{te_max:>12.3f}{out_of_range_pct:>13.1f}%")
    if out_of_range_pct > 5.0:
        flagged.append((f, out_of_range_pct))

print("\n" + "=" * 55)
if flagged:
    print("[WARNING] The following features are significantly out of")
    print("the training range in the test block -> the model is being")
    print("asked to EXTRAPOLATE, which tree-based models handle poorly:")
    for f, pct in flagged:
        print(f"   - {f}: {pct:.1f}% of test pixels out of training range")
    print("\nConsider a checkerboard / multi-region spatial split instead")
    print("of one contiguous holdout, or add training data that covers")
    print("this feature range.")
else:
    print("[OK] No major out-of-range drift detected on these features.")
    print("Spatial extrapolation is unlikely to be the dominant issue —")
    print("focus on the train/test distribution mismatch instead.")
print("=" * 55)