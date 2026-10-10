import os
import torch
import numpy as np
import rasterio
from rasterio.warp import reproject, Resampling
from rasterio.transform import from_origin
import torch.nn as nn

# =====================================================================
# 1. PASTE YOUR PYTORCH CLASSES HERE
# (Paste DoubleConv, AttentionBlock, and AttentionUNet definitions here)
# =====================================================================
class DoubleConv(nn.Module):
    def __init__(self, in_ch, out_ch):
        super().__init__()
        self.net = nn.Sequential(
            nn.Conv2d(in_ch, out_ch, kernel_size=3, padding=1, bias=False),
            nn.BatchNorm2d(out_ch),
            nn.ReLU(inplace=True),
            nn.Conv2d(out_ch, out_ch, kernel_size=3, padding=1, bias=False),
            nn.BatchNorm2d(out_ch),
            nn.ReLU(inplace=True)
        )

    def forward(self, x):
        return self.net(x)

class AttentionBlock(nn.Module):
    def __init__(self, F_g, F_l, F_int):
        super().__init__()
        self.W_g = nn.Sequential(
            nn.Conv2d(F_g, F_int, kernel_size=1, stride=1, padding=0, bias=True),
            nn.BatchNorm2d(F_int)
        )
        self.W_x = nn.Sequential(
            nn.Conv2d(F_l, F_int, kernel_size=1, stride=1, padding=0, bias=True),
            nn.BatchNorm2d(F_int)
        )
        self.psi = nn.Sequential(
            nn.Conv2d(F_int, 1, kernel_size=1, stride=1, padding=0, bias=True),
            nn.BatchNorm2d(1),
            nn.Sigmoid()
        )
        self.relu = nn.ReLU(inplace=True)

    def forward(self, g, x):
        g1 = self.W_g(g)
        x1 = self.W_x(x)
        psi = self.relu(g1 + x1)
        psi = self.psi(psi)
        return x * psi

# ----------------------------------------------------
# 4. Attention U-Net with Spatial Dropout
# ----------------------------------------------------
class AttentionUNet(nn.Module):
    def __init__(self, in_channels=4, out_channels=1):
        super().__init__()
        # Encoder
        self.enc1 = DoubleConv(in_channels, 32)
        self.pool1 = nn.MaxPool2d(2)
        self.enc2 = DoubleConv(32, 64)
        self.pool2 = nn.MaxPool2d(2)
        self.enc3 = DoubleConv(64, 128)
        self.pool3 = nn.MaxPool2d(2)
        
        # Bottleneck + Spatial Dropout
        self.bottleneck = DoubleConv(128, 256)
        self.dropout = nn.Dropout2d(p=0.3)
        
        # Decoder with Attention Gates
        self.up3 = nn.ConvTranspose2d(256, 128, kernel_size=2, stride=2)
        self.att3 = AttentionBlock(F_g=128, F_l=128, F_int=64)
        self.dec3 = DoubleConv(256, 128)
        
        self.up2 = nn.ConvTranspose2d(128, 64, kernel_size=2, stride=2)
        self.att2 = AttentionBlock(F_g=64, F_l=64, F_int=32)
        self.dec2 = DoubleConv(128, 64)
        
        self.up1 = nn.ConvTranspose2d(64, 32, kernel_size=2, stride=2)
        self.att1 = AttentionBlock(F_g=32, F_l=32, F_int=16)
        self.dec1 = DoubleConv(64, 32)
        
        # Output Head
        self.final_conv = nn.Conv2d(32, out_channels, kernel_size=1)

    def forward(self, x):
        e1 = self.enc1(x)
        e2 = self.enc2(self.pool1(e1))
        e3 = self.enc3(self.pool2(e2))
        
        b = self.bottleneck(self.pool3(e3))
        b = self.dropout(b)
        
        # Decoder 3
        d3 = self.up3(b)
        x3 = self.att3(g=d3, x=e3)
        d3 = self.dec3(torch.cat([d3, x3], dim=1))
        
        # Decoder 2
        d2 = self.up2(d3)
        x2 = self.att2(g=d2, x=e2)
        d2 = self.dec2(torch.cat([d2, x2], dim=1))
        
        # Decoder 1
        d1 = self.up1(d2)
        x1 = self.att1(g=d1, x=e1)
        d1 = self.dec1(torch.cat([d1, x1], dim=1))
        
        return self.final_conv(d1)

def min_max_normalize(band):
    b_min, b_max = np.min(band), np.max(band)
    if b_max - b_min > 0:
        return (band - b_min) / (b_max - b_min)
    return np.zeros_like(band)

def align_chirps_to_master(src_path, master_profile):
    """Dynamically aligns a raw CHIRPS raster to the 30m DEM resolution in memory."""
    with rasterio.open(src_path) as src:
        aligned_data = np.empty((master_profile['height'], master_profile['width']), dtype='float32')
        reproject(
            source=rasterio.band(src, 1),
            destination=aligned_data,
            src_transform=src.transform,
            src_crs=src.crs,
            dst_transform=master_profile['transform'],
            dst_crs=master_profile['crs'],
            resampling=Resampling.bilinear
        )
    return aligned_data

def run_inference():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    # NOTE: Ensure these paths point to where your data actually lives!
    # If your data is inside the scripts folder, change these to match your previous setup.
    base_dir = os.path.dirname(script_dir)
    aligned_dir = os.path.join(base_dir, "scripts", "data", "aligned") # Adjusted to your previous path structure
    raw_dir = os.path.join(base_dir, "scripts", "data", "raw")
    output_dir = os.path.join(base_dir, "scripts", "data", "output")
    models_dir = os.path.join(base_dir, "scripts", "models")
    os.makedirs(output_dir, exist_ok=True)
    os.makedirs(models_dir, exist_ok=True)

    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(f"Running inference on: {device}")

    # 2. Load the trained weights
    model = AttentionUNet(in_channels=4, out_channels=1).to(device)
    model_path = os.path.join(models_dir, "best_unet_flood.pth")
    
    if not os.path.exists(model_path):
        raise FileNotFoundError(f"Model not found at {model_path}. Did you download it from Colab/Drive?")
        
    model.load_state_dict(torch.load(model_path, map_location=device, weights_only=True))
    model.eval()

    # 3. Load the full Nairobi tensors
    print("Loading geographic layers...")
    with rasterio.open(os.path.join(aligned_dir, "aligned_dem.tif")) as src:
        dem = src.read(1)
        profile = src.profile
    with rasterio.open(os.path.join(aligned_dir, "aligned_esa.tif")) as src:
        esa = src.read(1)
    with rasterio.open(os.path.join(aligned_dir, "aligned_flow.tif")) as src:
        flow = src.read(1)
        
    # Dynamically align the April 24th CHIRPS data on the fly
    target_chirps_path = os.path.join(raw_dir, "chirps_20240424.tif")
    print(f"Aligning historical rainfall event: {target_chirps_path}")
    chirps = align_chirps_to_master(target_chirps_path, profile)

    # Normalize the full maps
    dem = min_max_normalize(dem)
    esa = min_max_normalize(esa)
    flow = min_max_normalize(flow)
    chirps = min_max_normalize(chirps)

    X_full = np.stack([dem, esa, flow, chirps], axis=0).astype('float32')
    _, h, w = X_full.shape
    
    prediction_map = np.zeros((h, w), dtype='float32')
    patch_size = 128
    stride = 128

    print("Running sliding window prediction...")
    with torch.no_grad():
        for y in range(0, h - patch_size + 1, stride):
            for x in range(0, w - patch_size + 1, stride):
                patch = X_full[:, y:y+patch_size, x:x+patch_size]
                patch_tensor = torch.tensor(patch).unsqueeze(0).to(device)
                
                logits = model(patch_tensor)
                probs = torch.sigmoid(logits).squeeze().cpu().numpy()
                
                # FIXED: Output the raw probability for the Leaflet dashboard heatmap
                prediction_map[y:y+patch_size, x:x+patch_size] = probs

    # 4. Export the final GeoTIFF
    output_path = os.path.join(output_dir, "nairobi_flood_prediction.tif")
    profile.update(dtype=rasterio.float32, count=1, compress='lzw')
    
    with rasterio.open(output_path, 'w', **profile) as dst:
        dst.write(prediction_map, 1)

    print(f"Success! Prediction map exported to: {output_path}")

if __name__ == "__main__":
    run_inference()