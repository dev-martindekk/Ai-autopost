#!/bin/bash
# =============================================================
# Migration Runner — runs on server
# รัน SQL files ใน sql/ ที่ยังไม่เคย apply
# =============================================================

set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SQL_DIR="$APP_DIR/sql"
ENV_FILE="$APP_DIR/.env"

# Load .env
if [ -f "$ENV_FILE" ]; then
    set -o allexport
    source "$ENV_FILE"
    set +o allexport
fi

DB_CONTAINER="${DB_CONTAINER:-ai-autopost-db}"
DB_NAME="${DB_NAME:-ai_autopost_seo}"
DB_USER="${DB_USER:-seouser}"
DB_PASS="${DB_PASS:-}"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

mysql_cmd() {
    docker exec "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@" 2>/dev/null
}

# สร้าง migrations table ถ้ายังไม่มี
mysql_cmd -e "
CREATE TABLE IF NOT EXISTS \`migrations\` (
    \`id\` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    \`filename\` VARCHAR(255) NOT NULL UNIQUE,
    \`applied_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" 2>/dev/null || true

echo -e "${YELLOW}🗄  Checking migrations...${NC}"

applied=0
skipped=0
failed=0

# วนรัน SQL file ตามลำดับตัวอักษร
for sqlfile in "$SQL_DIR"/*.sql; do
    [ -f "$sqlfile" ] || continue
    filename="$(basename "$sqlfile")"

    # เช็คว่า apply แล้วหรือยัง
    count=$(mysql_cmd -sN -e "SELECT COUNT(*) FROM \`migrations\` WHERE filename='$filename';" 2>/dev/null || echo "0")

    if [ "$count" != "0" ]; then
        echo -e "   ${NC}⏭  $filename (already applied)"
        ((skipped++)) || true
        continue
    fi

    echo -e "   ${YELLOW}▶  $filename${NC}"
    output=$(docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$sqlfile" 2>&1)
    exit_code=$?
    echo "$output" | grep -v "Warning: Using a password" || true
    if [ $exit_code -eq 0 ]; then
        mysql_cmd -e "INSERT IGNORE INTO \`migrations\` (filename) VALUES ('$filename');" 2>/dev/null
        echo -e "   ${GREEN}✓  $filename applied${NC}"
        ((applied++)) || true
    else
        echo -e "   ${RED}✗  $filename FAILED (exit $exit_code)${NC}"
        ((failed++)) || true
    fi
done

echo ""
if [ "$failed" -gt 0 ]; then
    echo -e "${RED}Migration done: $applied applied, $skipped skipped, $failed FAILED${NC}"
    exit 1
else
    echo -e "${GREEN}Migration done: $applied applied, $skipped skipped${NC}"
fi
