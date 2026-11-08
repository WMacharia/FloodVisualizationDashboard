# Deploying the Nairobi Flood DSS

One Ubuntu server runs everything. Only Nginx is public; the model server and the database
are reachable from the server itself only.

```
Internet --HTTPS--> Nginx --> Laravel (PHP-FPM) --> PostgreSQL
                                  |
                                  +--> model server (uvicorn, 127.0.0.1:8001)
```

| File | Purpose |
|---|---|
| `setup.sh` | One-time set-up on the server (packages, code, Python env, database, `.env`, Nginx, HTTPS, firewall, auto-start) |
| `update.sh` | Deploys the latest pushed code |
| `upload_model_files.ps1` | Copies the model and its data files (about 35 MB, not in git) from the laptop |
| `requirements-server.txt` | Model server packages, CPU-only PyTorch |
| `flood-model.service` | Keeps the model server running (restarts on crash and reboot) |
| `nginx-flood-dss.conf` | Web server site for Laravel |
| `env.production` | Template for the server's `.env` (no secrets) |

## 1. Create the server (Azure portal)

Virtual machine, **Ubuntu Server 24.04 LTS**, size **Standard_B2s** (2 vCPU, 4 GB RAM), SSH
public key with user `azureuser`, inbound ports **22, 80 and 443**. Then on the VM's page:
**DNS name > Configure** and set a label, e.g. `nairobi-flood`, which gives
`nairobi-flood.<region>.cloudapp.azure.com`.

Keep the private key (`.pem`) in `C:\Users\<you>\.ssh\`, never in the project or git.

## 2. Upload the model files (laptop, PowerShell, from the project folder)

```powershell
.\deploy\upload_model_files.ps1 -Server <dns-name>
```

## 3. Run the set-up (on the server)

```powershell
ssh -i $HOME\.ssh\flood-dss_key.pem azureuser@<dns-name>
```
```bash
curl -fsSLO https://raw.githubusercontent.com/WMacharia/FloodVisualizationDashboard/surrogate-model-roles-alerts/deploy/setup.sh
bash setup.sh <dns-name> <your-email-for-the-https-certificate>
```

It asks for the Google OAuth client ID and secret (typing the secret is hidden) and the
administrator Google account(s). It takes about 10-15 minutes, mostly installing packages.

## 4. Google sign-in (Google Cloud Console > APIs & Services > Credentials > your OAuth client)

- Authorised JavaScript origins: add `https://<dns-name>`
- Authorised redirect URIs: add `https://<dns-name>/auth/google/callback`

Keep the `http://127.0.0.1:8000/...` entries so the laptop keeps working. If the OAuth
consent screen is in **Testing**, only the listed test users can sign in: add the accounts
that will use the system (e.g. the panel), or publish the app.

## Everyday commands (on the server)

| Task | Command |
|---|---|
| Deploy new code (after `git push`) | `bash /srv/flood-dss/deploy/update.sh` |
| Make someone an administrator | `sudo -u www-data php /srv/flood-dss/nairobi-flood-dashboard/artisan admin:grant someone@gmail.com` |
| List administrators | `sudo -u www-data php /srv/flood-dss/nairobi-flood-dashboard/artisan admin:list` |
| Model server status / log | `systemctl status flood-model` / `sudo journalctl -u flood-model -n 60` |
| Laravel errors | `tail -n 50 /srv/flood-dss/nairobi-flood-dashboard/storage/logs/laravel-*.log` |
| Restart everything | `sudo systemctl restart flood-model php8.3-fpm nginx` |

Stopping the VM in the Azure portal (so it shows **Stopped (deallocated)**) pauses compute
charges; start it again before a demo and allow about a minute for the model server to load.
