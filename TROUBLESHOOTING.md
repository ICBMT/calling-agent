# Voca AI Calling Agent — Operational Troubleshooting Guide

This guide helps administrators diagnose and resolve operational issues in Voca.

---

## 1. Quick Diagnosis: Run `voca:doctor`

Before making manual changes, run the built-in diagnostic tool:

```bash
php artisan voca:doctor
```

It inspects runtime dependencies, database connectivity, migration status, queue worker heartbeat, LiveKit server connectivity, and Python voice worker registration. Each failure includes actionable remediation advice.

---

## 2. Common Issues & Solutions

### A. Voice Worker is "Offline" or "Unregistered" (503)

**Symptoms:**

- Doctor reports: `Voice worker LiveKit registration: FAIL/WARN (Offline / Unregistered)`.
- Playground shows: `Configuration required` or setup blocker `Voice worker is not responding`.

**Causes & Solutions:**

1. **Worker daemon not running:**
   Check systemd status or start manually:
    ```bash
    sudo systemctl status voca-voice-worker
    # Or manual test:
    cd /var/www/voca/voice-worker
    .venv/bin/python agent.py start
    ```
2. **Worker HTTP 503 (Unregistered):**
   The worker is running on port 8081 but cannot connect to the LiveKit server WebSocket.
   Verify `LIVEKIT_URL`, `LIVEKIT_API_KEY`, and `LIVEKIT_API_SECRET` match between `/var/www/voca/.env` and `voice-worker/.env`.
3. **Port 8081 conflict:**
   Check if another process occupies port 8081:
    ```bash
    ss -tlpn | grep 8081
    ```

---

### B. LiveKit Server Connectivity Fails ("Unavailable")

**Symptoms:**

- Doctor reports: `LiveKit server connectivity: Unavailable`.
- System health shows Voice engine `Unavailable`.

**Causes & Solutions:**

1. **LiveKit server stopped:**
    ```bash
    sudo systemctl status livekit
    ```
2. **Invalid API Key or Secret:**
   LiveKit returns HTTP 401 when Twirp authentication fails. Verify `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET` in `.env`.
3. **Firewall blocking port 7880 or RTC UDP:**
    ```bash
    sudo ufw status
    sudo ufw allow 7880/tcp
    sudo ufw allow 50000:50010/udp
    ```

---

### C. Queue Worker "Stalled" or "Not verified"

**Symptoms:**

- Doctor reports: `Queue worker heartbeat: Stalled` or `No worker heartbeat recorded in cache`.

**Causes & Solutions:**

1. **Queue worker daemon not running:**
    ```bash
    sudo systemctl status voca-queue
    # Start manually for testing:
    php artisan queue:work --sleep=3 --tries=3
    ```
2. **Cache driver configured as `array`:**
   Ensure `CACHE_STORE=database` (or `redis`) in `.env`, not `array`. The queue heartbeat requires a shared cache store.

---

### D. Microphone Access Blocked in Playground

**Symptoms:**

- Playground displays error: `Microphone permission denied or device busy`.

**Causes & Solutions:**

1. **HTTPS Required:** Browsers only grant WebRTC microphone access over `https://` or `http://localhost`. If accessing via an IP address (e.g. `http://192.168.1.100:8000`), the browser will block media devices. Use a valid domain with SSL or localhost.
2. **Site Settings:** Check browser address bar icon and ensure microphone permission is set to "Allow".

---

### E. AI Provider Fails Authentication or Model List Check

**Symptoms:**

- In `/app/providers`, Test Connection fails with `401 Unauthorized` or `Invalid API Key`.

**Causes & Solutions:**

1. **Expired or Restricted Key:**
    - OpenAI: Ensure the API key has `Model List` read permissions and the organization has active billing credits.
    - Google Gemini: Ensure the API key is created in Google AI Studio for an active project with the Generative Language API enabled.
2. **Network / Outbound Firewall:**
   Verify server can reach `api.openai.com` or `generativelanguage.googleapis.com` over HTTPS port 443.

---

### F. Migration or Database Permission Errors

**Symptoms:**

- Database errors in `storage/logs/laravel.log` or permission denied writing to SQLite file.

**Causes & Solutions:**

1. **Fix directory ownership:**
    ```bash
    sudo chown -R www-data:www-data /var/www/voca/storage /var/www/voca/bootstrap/cache /var/www/voca/database
    sudo chmod -R 775 /var/www/voca/storage /var/www/voca/bootstrap/cache
    ```
2. **Run migrations:**
    ```bash
    php artisan migrate --force
    ```

---

## 3. Privacy-Safe Log Review

To view application logs without risk of credential exposure (Voca automatically redacts API keys and sensitive tokens):

```bash
# Laravel application errors:
tail -n 100 storage/logs/laravel.log

# Voice worker logs:
journalctl -u voca-voice-worker -n 100 --no-pager

# Nginx error logs:
sudo tail -n 100 /var/log/nginx/error.log
```
