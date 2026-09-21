import os
import numpy as np
import pandas as pd
import matplotlib.pyplot as plt

print("=======================================================")
print("STARTING PHASE 5: SPATIAL CHECKERBOARD SPLIT (THE FIX)")
print("=======================================================")

df = pd.read_parquet("data/processed/training_data.parquet")
print(f"1. Loaded full dataset: {len(df):,} pixels.")

# -------------------------------------------------------------------------
# SPATIAL CHECKERBOARD SPLITTING
# -------------------------------------------------------------------------
print("2. Generating 10x10 spatial grid over Nairobi...")
# Create a 10x10 grid by grouping X and Y coordinates
df['x_bin'] = pd.cut(df['x_coord'], bins=10, labels=False)
df['y_bin'] = pd.cut(df['y_coord'], bins=10, labels=False)

# Create a unique ID for each of the 100 blocks (e.g., "3_7")
df['grid_id'] = df['x_bin'].astype(str) + "_" + df['y_bin'].astype(str)

unique_grids = df['grid_id'].unique()
print(f"   [OK] Created {len(unique_grids)} unique spatial blocks.")

print("3. Randomly allocating 25% of grid blocks to the Test Set...")
# Randomly select 25% of the blocks for testing
np.random.seed(42)
test_grids = np.random.choice(unique_grids, size=int(0.25 * len(unique_grids)), replace=False)

train_df = df[~df['grid_id'].isin(test_grids)].copy()
test_df = df[df['grid_id'].isin(test_grids)].copy()

# Drop the temporary grid columns
train_df.drop(columns=['x_bin', 'y_bin', 'grid_id'], inplace=True)
test_df.drop(columns=['x_bin', 'y_bin', 'grid_id'], inplace=True)

print(f"   [OK] Training Set: {len(train_df):,} pixels")
print(f"   [OK] Testing Set:  {len(test_df):,} pixels")

# -------------------------------------------------------------------------
# VISUAL VERIFICATION
# -------------------------------------------------------------------------
print("4. Generating checkerboard verification map...")
plot_train = train_df.sample(n=min(50000, len(train_df)), random_state=42)
plot_test = test_df.sample(n=min(15000, len(test_df)), random_state=42)

plt.figure(figsize=(10, 8))
plt.scatter(plot_train["x_coord"], plot_train["y_coord"], 
            c="forestgreen", s=1.0, alpha=0.5, label="Training Blocks")
plt.scatter(plot_test["x_coord"], plot_test["y_coord"], 
            c="crimson", s=1.0, alpha=0.5, label="Testing Blocks (Unseen)")

plt.title("Stratified Spatial Checkerboard Split")
plt.xlabel("Easting (m) - UTM 37N")
plt.ylabel("Northing (m) - UTM 37N")
plt.legend(loc="lower right")
plt.tight_layout()

os.makedirs("outputs", exist_ok=True)
plt.savefig("outputs/spatial_split_validation.png", dpi=200)
plt.close()

# -------------------------------------------------------------------------
# SAVE SPLITS TO DISK
# -------------------------------------------------------------------------
print("5. Saving split datasets to disk...")
train_df.to_parquet("data/processed/train_data.parquet", index=False, engine="pyarrow")
test_df.to_parquet("data/processed/test_data.parquet", index=False, engine="pyarrow")

print("=======================================================")
print("[SUCCESS] Phase 5 Complete. Ready for Two-Tiered Training!")
print("=======================================================")