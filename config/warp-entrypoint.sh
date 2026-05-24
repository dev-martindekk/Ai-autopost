#!/bin/bash
# Cloudflare WARP SOCKS5 Proxy Entrypoint
# Provides encrypted tunnel to bypass DNS-level blocking (e.g. Thai MDES)
# WARP binds to 127.0.0.1:40000, gost forwards 0.0.0.0:1080 as SOCKS5 -> WARP

set -e

WARP_PORT=40000
LISTEN_PORT=1080

# Create TUN device if not exists
mkdir -p /dev/net
[ ! -c /dev/net/tun ] && mknod /dev/net/tun c 10 200 && chmod 600 /dev/net/tun

# Start WARP daemon
warp-svc &
WARP_PID=$!

# Wait for daemon to be ready
echo "Waiting for WARP daemon to start..."
for i in $(seq 1 30); do
    if warp-cli --accept-tos status 2>/dev/null; then
        break
    fi
    sleep 1
done

# Register if needed (persisted via volume)
if warp-cli --accept-tos status 2>&1 | grep -q "RegistrationMissing\|Registration Missing"; then
    echo "Registering WARP..."
    warp-cli --accept-tos registration new
    sleep 2
fi

# Set proxy mode (SOCKS5 on internal port)
warp-cli --accept-tos mode proxy
warp-cli --accept-tos proxy port $WARP_PORT

# Connect
warp-cli --accept-tos connect

echo "Waiting for WARP to connect..."

# Wait for connection
CONNECTED=false
for i in $(seq 1 60); do
    STATUS=$(warp-cli --accept-tos status 2>&1)
    if echo "$STATUS" | grep -q "Connected"; then
        echo "WARP Connected!"
        CONNECTED=true
        break
    fi
    sleep 2
done

if [ "$CONNECTED" = false ]; then
    echo "WARNING: WARP not connected yet, starting gost anyway..."
fi

# Start gost as SOCKS5 proxy forwarder
# Listens on 0.0.0.0:1080 and chains to WARP's SOCKS5 on 127.0.0.1:40000
echo "Starting gost SOCKS5 forwarder: 0.0.0.0:${LISTEN_PORT} -> 127.0.0.1:${WARP_PORT}"
gost -L "socks5://:${LISTEN_PORT}" -F "socks5://127.0.0.1:${WARP_PORT}" &
GOST_PID=$!

echo "============================================"
echo "WARP SOCKS5 proxy ready on port ${LISTEN_PORT}"
echo "============================================"

warp-cli --accept-tos status

# Health check loop
while kill -0 $WARP_PID 2>/dev/null; do
    sleep 60
    if ! warp-cli --accept-tos status 2>&1 | grep -q "Connected"; then
        echo "$(date) - WARP disconnected, reconnecting..."
        warp-cli --accept-tos connect
        sleep 5
    fi
done
