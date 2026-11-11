# Voca AI Calling Agent — Production Server Deployment Guide

This guide describes how to deploy, configure, secure, and maintain Voca AI Calling Agent on a production Ubuntu 22.04 or 24.04 LTS server.

---

## 1. Architecture Overview

In production, Voca operates across three coordinated tiers:

```
[ User Browser / WebRTC Client ]
           │ (HTTPS / WSS)
           ▼
[ Nginx Reverse Proxy (Port 443) ]
     │                              │
     ▼ (FastCGI)                    ▼ (WSS Proxy / RTC Ports)
[ Laravel Web Application ]     [ LiveKit Server (7880 / 7881 / 50000-50010 UDP) ]
[ Queue Worker (systemd)  ]         ▲
                                    │ (WebSocket Registration)
                                    │
                                [ Python Voice Worker (Port 8081) ]
                                    │ (HMAC-SHA256 Token Claims)
                                    ▼
                                [ Laravel Control Plane ]
```

---

## 2. Server Requirements & Firewall Ports

### Hardware

- **CPU:** 2 vCPU minimum (4 vCPU recommended for high concurrency).
- **RAM:** 4 GB minimum (8 GB recommended).
- **Disk:** 40 GB SSD.

### Firewall & Port Requirements (UFW)

| Port / Protocol       | Service         | Direction      | Purpose                          |
| --------------------- | --------------- | -------------- | -------------------------------- |
| **80 / TCP**          | HTTP            | Inbound        | Let's Encrypt renewal & redirect |
| **443 / TCP**         | HTTPS           | Inbound        | Web interface & REST APIs        |
| **7880 / TCP**        | LiveKit HTTP/WS | Inbound        | LiveKit client signaling         |
| **7881 / TCP**        | LiveKit RTC TCP | Inbound        | WebRTC TCP fallback              |
| **50000–50010 / UDP** | LiveKit RTC UDP | Inbound        | WebRTC audio media transport     |
| **8081 / TCP**        | Voice Worker    | Localhost only | Internal worker health probes    |

Configure UFW firewall:

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 7880/tcp
sudo ufw allow 7881/tcp
sudo ufw allow 50000:50010/udp
sudo ufw enable
```

---

## 3. Fresh Server Installation (One-Command)

On a clean Ubuntu server with git installed:

```bash
# Clone the repository
sudo git clone https://github.com/ICBMT/calling-agent.git /var/www/voca
cd /var/www/voca

# Run the automated installer
sudo ./bin/install.sh
```

The installer will:

1. Check system prerequisites (PHP 8.3/8.4, Composer, Node 22+, Python 3.11+).
2. Create `.env` from `.env.example` safely preserving existing secrets.
3. Install Composer production dependencies.
4. Set up Python virtual environment (`voice-worker/.venv`) and install `livekit-agents` + plugins.
5. Initialize the database and apply non-destructive migrations.
6. Build frontend assets using Vite.
7. Run `php artisan voca:doctor` diagnostics.

---

## 4. Production Web Server Setup (Nginx + TLS)

### 1. Install Nginx & Certbot

```bash
sudo apt update
sudo apt install -y nginx certbot python3-certbot-nginx
```

### 2. Configure Nginx

Copy the production Nginx configuration:

```bash
sudo cp /var/www/voca/deploy/nginx/voca.conf /etc/nginx/sites-available/voca.conf
sudo ln -s /etc/nginx/sites-available/voca.conf /etc/nginx/sites-enabled/
```

Edit `/etc/nginx/sites-available/voca.conf` and replace `your-voca-domain.com` with your actual domain name.

### 3. Issue SSL Certificate

```bash
sudo certbot --nginx -d your-voca-domain.com
sudo systemctl reload nginx
```

---

## 5. Systemd Background Services

Voca provides systemd service units in `deploy/systemd/` for persistent background operation and automatic reboot recovery.

### 1. Install Service Files

```bash
sudo cp /var/www/voca/deploy/systemd/voca-web.service /etc/systemd/system/
sudo cp /var/www/voca/deploy/systemd/voca-queue.service /etc/systemd/system/
sudo cp /var/www/voca/deploy/systemd/voca-voice-worker.service /etc/systemd/system/
```

### 2. Enable & Start Services

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now voca-web
sudo systemctl enable --now voca-queue
sudo systemctl enable --now voca-voice-worker
```

### 3. Check Service Status

```bash
sudo systemctl status voca-web
sudo systemctl status voca-queue
sudo systemctl status voca-voice-worker
```

---

## 6. LiveKit Server Setup (Self-Hosted)

If running a self-hosted LiveKit instance:

1. Install LiveKit Server binary:
    ```bash
    curl -sSL https://get.livekit.io | bash
    ```
2. Generate API keys:
    ```bash
    livekit-server generate-keys
    ```
3. Place configuration in `/etc/livekit/livekit.yaml` and install the systemd unit:
    ```bash
    sudo cp /var/www/voca/deploy/systemd/livekit.service /etc/systemd/system/
    sudo systemctl enable --now livekit
    ```
4. Copy the generated `api_key` and `api_secret` into `/var/www/voca/.env`:
    ```env
    LIVEKIT_URL=https://your-voca-domain.com/livekit
    LIVEKIT_API_KEY=your_key_here
    LIVEKIT_API_SECRET=your_secret_here
    VOICE_ENABLED=true
    ```

---

## 7. Operational Diagnostics (`voca:doctor`)

Run the comprehensive doctor command at any time to verify system health without exposing credentials:

```bash
php artisan voca:doctor
```

For automated monitoring or health checks (e.g. via Zabbix or Datadog):

```bash
php artisan voca:doctor --json
```

A clean installation reports `PASS` on all core components.

---

## 8. Routine Upgrades & Rollbacks

### Upgrading Voca

Run the safe upgrade script:

```bash
cd /var/www/voca
sudo ./bin/upgrade.sh
```

Or via Artisan:

```bash
php artisan voca:upgrade
```

This will:

1. Automatically create a timestamped database backup in `storage/backups/`.
2. Apply any new migrations safely.
3. Refresh application caches.
4. Rebuild frontend assets.
5. Restart systemd workers.
6. Verify post-upgrade health with `voca:doctor`.

---

## 9. Security Checklist

- [ ] `APP_DEBUG=false` in `/var/www/voca/.env`.
- [ ] `APP_ENV=production`.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] Application document root strictly set to `/var/www/voca/public`.
- [ ] Nginx denies access to dotfiles (`.env`, `.git`).
- [ ] LiveKit UDP media ports `50000-50010` open to clients.
- [ ] Python voice worker health port `8081` bound to loopback only.
