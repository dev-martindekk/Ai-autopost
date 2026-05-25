#!/bin/bash
# =============================================================
# Production Deploy Script
# รัน: bash scripts/deploy.sh
# =============================================================
set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

echo -e "${YELLOW}🚀 Starting deploy...${NC}"

cd "$APP_DIR"

# Pull latest code
echo -e "${YELLOW}📥 Pulling latest code...${NC}"
git pull origin main

# Clear PHP OPcache so new code is picked up immediately
echo -e "${YELLOW}🔄 Clearing OPcache...${NC}"
docker exec ai-autopost-web php -r "opcache_reset(); echo 'OPcache cleared\n';" 2>/dev/null || true

# Run database migrations
echo -e "${YELLOW}🗄  Running migrations...${NC}"
bash "$APP_DIR/scripts/migrate.sh"

echo -e "${GREEN}✓ Deploy complete!${NC}"
