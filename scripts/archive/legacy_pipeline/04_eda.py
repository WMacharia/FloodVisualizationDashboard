import os
import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
import seaborn as sns

print("=======================================================")
print("STARTING PHASE 4: EXPLORATORY DATA ANALYSIS (EDA)")
print("=======================================================")

os.makedirs("outputs", exist_ok=True)
PARQUET_FILE = "data/processed/training_data.parquet"

print("1. Loading Parquet dataset...")
df = pd.read_parquet(PARQUET_FILE)

# --- CRITICAL CLEANUP ---
# Remove the -32767.0 elevation NoData artifacts
invalid_elev_count = (df["elevation"] < 0).sum()
if invalid_elev_count > 0:
    print(f"   [FIX] Removing {invalid_elev_count:,} corrupted NoData elevation pixels...")
    df = df[df["elevation"] > 0].copy()
    # Overwrite the parquet file with the clean data
    df.to_parquet(PARQUET_FILE, index=False)
    print("   [OK] Clean dataset re-saved to disk.")
# ------------------------

print("2. Generating Spatial Flood Distribution Map...")
# Subsample to 200,000 pixels to prevent matplotlib from freezing
sample_df = df.sample(n=min(200000, len(df)), random_state=42)

plt.figure(figsize=(10, 8))
# Plot non-flooded areas first in light grey
safe_mask = sample_df["flood_depth"] == 0
plt.scatter(sample_df[safe_mask]["x_coord"], sample_df[safe_mask]["y_coord"], 
            c="#e0e0e0", s=1.0, alpha=0.5, label="Safe (0m)")

# Plot flooded areas on top in blue/navy
flood_mask = sample_df["flood_depth"] > 0
sc = plt.scatter(sample_df[flood_mask]["x_coord"], sample_df[flood_mask]["y_coord"], 
                 c=sample_df[flood_mask]["flood_depth"], 
                 cmap="Blues", s=2.0, alpha=0.9, vmin=0.1, vmax=1.5)

plt.colorbar(sc, label="Flood Water Depth (m)")
plt.title("Spatial Distribution of FASTFLOOD Target (Nairobi Grid)")
plt.xlabel("Easting (m) - UTM 37N")
plt.ylabel("Northing (m) - UTM 37N")
plt.legend(loc="lower right")
plt.tight_layout()
plt.savefig("outputs/eda_spatial_flood_map.png", dpi=200)
plt.close()
print("   [OK] Saved outputs/eda_spatial_flood_map.png")

print("3. Generating Multivariate Correlation Matrix...")
# Exclude pure coordinates from correlation math
features = [c for c in df.columns if c not in ["x_coord", "y_coord", "longitude", "latitude"]]
corr = df[features].corr()

plt.figure(figsize=(9, 7))
sns.heatmap(corr, annot=True, fmt=".2f", cmap="coolwarm", cbar=True, square=True, vmin=-1, vmax=1)
plt.title("Pearson Correlation Matrix: Features vs Flood Depth")
plt.tight_layout()
plt.savefig("outputs/eda_correlation_matrix.png", dpi=200)
plt.close()
print("   [OK] Saved outputs/eda_correlation_matrix.png")

print("4. Generating Feature Distribution Histograms...")
fig, axes = plt.subplots(3, 3, figsize=(15, 12))
axes = axes.flatten()
for i, col in enumerate(features):
    # Use a log scale for highly skewed features like distance and depth
    log_scale = col in ["flood_depth", "dist_drainage_m", "building_density"]
    axes[i].hist(df[col], bins=50, color="teal", edgecolor="black", alpha=0.7, log=log_scale)
    axes[i].set_title(f"{col} Distribution")
    if log_scale:
        axes[i].set_ylabel("Pixel Count (Log Scale)")

# Hide the empty 9th subplot
axes[8].set_visible(False)
plt.tight_layout()
plt.savefig("outputs/eda_feature_distributions.png", dpi=200)
plt.close()
print("   [OK] Saved outputs/eda_feature_distributions.png")

print("=======================================================")
print("[SUCCESS] Phase 4 Complete. Diagnostic plots are in the 'outputs/' folder.")
print("=======================================================")