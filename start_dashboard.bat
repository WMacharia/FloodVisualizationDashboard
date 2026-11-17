@echo off
REM Starts the Nairobi Flood DSS: the model server (port 8001) and the Laravel site (port 8000),
REM each in its own window. PostgreSQL runs as a Windows service and starts with the laptop.
REM Close a window (or press Ctrl+C in it) to stop that server.

cd /d "%~dp0"

start "Flood model server (port 8001)" cmd /k "cd /d "%~dp0scripts" && nairobi_flood_env\Scripts\python.exe -m uvicorn model_api:app --host 127.0.0.1 --port 8001"
start "Laravel site (port 8000)" cmd /k "cd /d "%~dp0nairobi-flood-dashboard" && php artisan serve --host=127.0.0.1 --port=8000"

echo The model server takes about 30 seconds to load. Opening the dashboard...
timeout /t 30 /nobreak >nul
start "" http://127.0.0.1:8000
