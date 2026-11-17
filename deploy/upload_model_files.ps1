# Copies the model and the data files it reads at runtime (about 36 MB) from this laptop to the
# server. They are not in git (scripts/models and scripts/data are ignored), so the server
# cannot get them by cloning. Run from PowerShell on the laptop:
#
#   .\deploy\upload_model_files.ps1 -Server nairobi-flood.southafricanorth.cloudapp.azure.com
#
param(
    [Parameter(Mandatory = $true)][string]$Server,
    [string]$Key = "$HOME\.ssh\flood-dss_key.pem",
    [string]$User = "azureuser"
)
$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..\scripts")).Path

$files = @(
    "models/unet_surrogate.pth",
    "models/unet_surrogate_config.json",
    "data/surrogate/static_inputs.npz",
    "data/aligned/aligned_esa.tif",
    "data/dashboard/layers.npz",
    "data/dashboard/road_graph.npz",
    "data/dashboard/facilities.json",
    "data/dashboard/places.json",
    "data/dashboard/subcounties.geojson",
    "data/validation/vehicle_margins.json"
)
foreach ($f in $files) {
    if (-not (Test-Path (Join-Path $root $f))) { throw "Not found on this laptop: scripts/$f" }
}

$dirs = ($files | ForEach-Object { "flood-files/" + (Split-Path $_ -Parent).Replace('\', '/') } | Sort-Object -Unique) -join " "
Write-Host "Creating folders on $Server (answer 'yes' if asked to trust the server the first time)..."
ssh -i $Key "$User@$Server" "mkdir -p $dirs"
if ($LASTEXITCODE -ne 0) { throw "Could not connect with ssh. Check the server name and the key path ($Key)." }

foreach ($f in $files) {
    Write-Host "  $f"
    scp -q -i $Key (Join-Path $root $f) "${User}@${Server}:flood-files/$f"
    if ($LASTEXITCODE -ne 0) { throw "Upload failed: $f" }
}
Write-Host "Done. Next, on the server: bash setup.sh <domain> <email>  (see deploy/DEPLOY.md)"
