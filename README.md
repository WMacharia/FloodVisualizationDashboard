# Nairobi Flood DSS

A web-based spatial decision support system for urban flash floods in Nairobi County. An Attention U-Net,
trained to reproduce a physics-based flood simulator, maps flood extent, depth and depth x velocity for any
storm (10-120 mm/h, 1-6 h). The dashboard turns this into hazard zones, vehicle-aware safe routes, alerts and
role-based tools for residents, responders and administrators.

Live system: https://nairobi-flood.southafricanorth.cloudapp.azure.com

## Repository layout

| Folder | Contents |
|---|---|
| `scripts/` | Data pipeline, flood simulator, dataset builder and the FastAPI model server (`model_api.py`). See [scripts/README.md](scripts/README.md). |
| `Flood_Surrogate_Training.ipynb` | Training notebook for the U-Net (Google Colab, T4 GPU). |
| `nairobi-flood-dashboard/` | Laravel web application (map, routing, alerts, roles, admin console) and its automated tests. |
| `deploy/` | Scripts and configuration for the Ubuntu server. See [deploy/DEPLOY.md](deploy/DEPLOY.md). |

The trained model and data files (`scripts/models/`, `scripts/data/`) are not stored in Git. They can be
rebuilt by running the numbered scripts in `scripts/` in order and then the training notebook.

## Requirements

Python 3.13, PHP 8.3 with Composer, PostgreSQL 16, and a Google OAuth client for sign-in.

## Set up and run on a laptop

1. Model server (Python):
   ```cmd
   cd scripts
   python -m venv nairobi_flood_env
   nairobi_flood_env\Scripts\activate
   pip install -r ..\requirements.txt
   uvicorn model_api:app --host 127.0.0.1 --port 8001
   ```
2. Web application (Laravel), in a second terminal:
   ```cmd
   cd nairobi-flood-dashboard
   composer install
   copy .env.example .env
   php artisan key:generate
   ```
   In `.env`, set the PostgreSQL connection (`DB_CONNECTION=pgsql`, database, user and password),
   `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback`
   and `ADMIN_EMAILS` (Google accounts that become administrators). Then:
   ```cmd
   php artisan migrate
   php artisan serve --host=127.0.0.1 --port=8000
   ```
3. Open http://127.0.0.1:8000. On Windows, `start_dashboard.bat` starts both servers once they are set up.

Never commit `.env` or any credentials; they are excluded by `.gitignore`.

## Test

```cmd
cd nairobi-flood-dashboard
php artisan test
```
The feature tests use a temporary SQLite database and simulate Google sign-in and the model server, so they
need no credentials or network access. Model evaluation results are produced by the training notebook and
`scripts/11_calibrate_vehicle_margins.py`.

## Deploy

The system runs on one Ubuntu 24.04 server, built from the `main` branch. First-time set-up uses
`deploy/setup.sh`; later releases use `deploy/update.sh`. Full steps are in [deploy/DEPLOY.md](deploy/DEPLOY.md).

## Releases

Development is done on feature branches and merged into `main` after the automated tests pass. Each
evaluated version is tagged (for example `v1.0`), and the server runs the tagged commit from `main`.
