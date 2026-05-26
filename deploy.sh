#!/bin/bash
# =============================================================
# deploy.sh — AI AutoPost SEO
# วิธีใช้:
#   ./deploy.sh                    # ถามชื่อ commit
#   ./deploy.sh "แก้ bug xxx"      # ระบุ commit message เลย
#   ./deploy.sh --no-commit        # deploy โดยไม่ commit ใหม่
# =============================================================

set -e

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
BOLD='\033[1m'

# ─── อ่าน config จาก .env ───────────────────────────────────
if [ -f ".env" ]; then
    set -o allexport
    source .env
    set +o allexport
fi

DEPLOY_HOST="${DEPLOY_HOST:-178.128.16.220}"
DEPLOY_USER="${DEPLOY_USER:-root}"
DEPLOY_PASS="${DEPLOY_PASS:-}"
DEPLOY_PATH="${DEPLOY_PATH:-/var/www/ai-autopost}"

# ─── ตรวจสอบ tools ──────────────────────────────────────────
if ! command -v sshpass &>/dev/null; then
    echo -e "${RED}❌ ต้องติดตั้ง sshpass ก่อน:${NC}"
    echo "   brew install hudochenkov/sshpass/sshpass"
    exit 1
fi

if [ -z "$DEPLOY_PASS" ]; then
    echo -e "${RED}❌ DEPLOY_PASS ไม่ได้ตั้งค่าใน .env${NC}"
    exit 1
fi

ssh_run() {
    sshpass -p "$DEPLOY_PASS" ssh -o StrictHostKeyChecking=no -o LogLevel=error \
        "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"
}

# ─── Header ─────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${CYAN}  🚀 Deploy → $DEPLOY_HOST${NC}"
echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# ─── 1. Git commit & push ────────────────────────────────────
if [ "$1" = "--no-commit" ]; then
    echo -e "${YELLOW}⏭  ข้าม commit (--no-commit)${NC}"
else
    # เช็คว่ามีการเปลี่ยนแปลงไหม
    if git diff --quiet && git diff --staged --quiet && git diff HEAD --quiet 2>/dev/null; then
        echo -e "${YELLOW}ℹ️  ไม่มีการเปลี่ยนแปลงใหม่${NC}"
    else
        # รับ commit message
        if [ -n "$1" ]; then
            COMMIT_MSG="$1"
        else
            echo -e "${CYAN}📝 Commit message (Enter = ใช้วันที่):${NC} \c"
            read -r COMMIT_MSG
            if [ -z "$COMMIT_MSG" ]; then
                COMMIT_MSG="Update $(date '+%Y-%m-%d %H:%M')"
            fi
        fi

        echo ""
        echo -e "${YELLOW}📦 Committing...${NC}"
        git add -A
        git commit -m "$COMMIT_MSG

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>" || true
    fi

    echo -e "${YELLOW}⬆️  Pushing to GitHub...${NC}"
    git push origin main
    echo -e "${GREEN}✓  Push สำเร็จ${NC}"
fi

echo ""

# ─── 2. Pull code บน server ─────────────────────────────────
echo -e "${YELLOW}⬇️  Pulling code on server...${NC}"
ssh_run "cd $DEPLOY_PATH && git pull origin main 2>&1"
echo -e "${GREEN}✓  Pull สำเร็จ${NC}"
echo ""

# ─── 2.1 Clear OPcache ──────────────────────────────────────
echo -e "${YELLOW}🧹 Clearing OPcache...${NC}"
ssh_run "docker exec ai-autopost-web php -r \"opcache_reset(); echo 'OPcache cleared';\" 2>/dev/null || true"
echo -e "${GREEN}✓  OPcache cleared${NC}"
echo ""

# ─── 3. รัน migrations ──────────────────────────────────────
echo -e "${YELLOW}🗄  Running migrations...${NC}"
ssh_run "cd $DEPLOY_PATH && bash scripts/migrate.sh"
echo ""

# ─── 4. ตรวจสอบ containers ──────────────────────────────────
echo -e "${YELLOW}🐳 Container status:${NC}"
ssh_run "docker ps --format 'table {{.Names}}\t{{.Status}}' | grep ai-autopost"
echo ""

echo -e "${BOLD}${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BOLD}${GREEN}  ✅ Deploy เสร็จแล้ว!${NC}"
echo -e "${BOLD}${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
