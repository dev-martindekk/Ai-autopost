# Production Deployment Guide — AI AutoPost SEO

## Prerequisites
- VPS/Server with Docker + Docker Compose installed
- Domain pointing to server IP (DNS A record)
- Port 80 and 443 open in firewall

---

## Step 1 — Get SSL Certificate (Let's Encrypt)

```bash
# Install certbot on the HOST (not in Docker)
apt install certbot -y

# Stop any existing services on port 80
docker compose down

# Get certificate (standalone mode)
certbot certonly --standalone -d yourdomain.com -d www.yourdomain.com

# Certs are at:
# /etc/letsencrypt/live/yourdomain.com/fullchain.pem
# /etc/letsencrypt/live/yourdomain.com/privkey.pem

# Copy to project ssl dir
cp /etc/letsencrypt/live/yourdomain.com/fullchain.pem ./config/ssl/
cp /etc/letsencrypt/live/yourdomain.com/privkey.pem  ./config/ssl/
chmod 644 ./config/ssl/fullchain.pem
chmod 600 ./config/ssl/privkey.pem
```

---

## Step 2 — Create .env file

```bash
cp .env.example .env
nano .env   # fill in all values
```

Required values:
- `DB_PASS` — strong random password (no special chars that break MySQL)
- `MYSQL_ROOT_PASSWORD` — different strong password
- `BASE_URL` — `https://yourdomain.com` (no trailing slash)
- `ENCRYPTION_KEY` — generate: `openssl rand -base64 32`

---

## Step 3 — Update Apache config

Edit `config/apache-prod.conf` and replace `yourdomain.com` with your actual domain.

---

## Step 4 — Deploy

```bash
# Build and start production stack
docker compose -f docker-compose.prod.yml --env-file .env up -d --build

# Verify running
docker compose -f docker-compose.prod.yml ps

# Check logs
docker logs ai-autopost-web --tail=50
docker logs ai-autopost-db  --tail=20
```

---

## Step 5 — Verify HTTPS

1. Visit `https://yourdomain.com/admin/` → should load over HTTPS
2. Visit `http://yourdomain.com` → should redirect to HTTPS (301)
3. Check SSL: `curl -I https://yourdomain.com`

---

## Step 6 — Enable HSTS (after SSL confirmed working)

In `.htaccess`, uncomment the short HSTS first:
```apache
Header always set Strict-Transport-Security "max-age=300"
```
Wait a few days, then switch to full HSTS:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
```

---

## Step 7 — Auto-renew SSL

```bash
# Add to HOST crontab (not Docker)
# Renews cert before expiry; restarts web container to pick up new cert
echo "0 3 * * * root certbot renew --quiet && cp /etc/letsencrypt/live/yourdomain.com/*.pem /path/to/project/config/ssl/ && docker restart ai-autopost-web" >> /etc/crontab
```

---

## Security Checklist Before Going Live

- [ ] `.env` file created with strong passwords
- [ ] `.env` NOT committed to git (check `.gitignore`)
- [ ] `config/ssl/` certs in place
- [ ] DB port 3308 NOT exposed publicly (prod compose binds to 127.0.0.1 only)
- [ ] phpMyAdmin removed or password-protected (not exposed in prod compose)
- [ ] Admin account password changed from default
- [ ] Telegram bot token configured
- [ ] AI provider API key configured
- [ ] Test member registration → Trial plan assigned
- [ ] Test slip upload → admin notification

---

## Notes

- **No email system**: Password reset tokens are generated but not emailed.
  Either integrate an email service (SMTP/Mailgun) or handle resets via admin.
- **WARP proxy**: The warp-proxy container needs `NET_ADMIN` capabilities.
  Some VPS providers block this — test with `docker run --cap-add NET_ADMIN ...` first.
- **DB port**: In production, DB binds to `127.0.0.1:3308` only (not `0.0.0.0`).
