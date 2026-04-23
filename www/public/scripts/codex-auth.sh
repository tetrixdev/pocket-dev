#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# PocketDev — Codex Auth Upload Script
#
# Runs `codex login` on your local machine (no org restrictions, uses your
# browser), then automatically uploads the resulting auth.json to PocketDev.
#
# Usage:
#   bash <(curl -s https://YOUR-POCKETDEV/scripts/codex-auth.sh) https://YOUR-POCKETDEV
#
# Or download and run manually:
#   curl -O https://YOUR-POCKETDEV/scripts/codex-auth.sh
#   bash codex-auth.sh https://YOUR-POCKETDEV
#   bash codex-auth.sh https://user:pass@YOUR-POCKETDEV   # with Basic Auth
# ─────────────────────────────────────────────────────────────────────────────
set -e

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}→${RESET} $*"; }
success() { echo -e "${GREEN}✓${RESET} $*"; }
warn()    { echo -e "${YELLOW}!${RESET} $*"; }
error()   { echo -e "${RED}✗${RESET} $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}$*${RESET}"; }

# ── Argument / env parsing ────────────────────────────────────────────────────
PD_URL="${1:-${POCKETDEV_URL:-}}"

if [ -z "$PD_URL" ]; then
    echo -e "${BOLD}PocketDev Codex Auth Upload${RESET}"
    echo ""
    echo "Usage:"
    echo "  $0 <pocketdev-url>"
    echo "  $0 https://user:pass@pocketdev.example.com   # with Basic Auth"
    echo ""
    echo "Or set the POCKETDEV_URL environment variable:"
    echo "  POCKETDEV_URL=https://pocketdev.example.com $0"
    exit 1
fi

# Strip trailing slash
PD_URL="${PD_URL%/}"

header "PocketDev Codex Auth Upload"
echo "Target: ${PD_URL}"
echo ""

# ── Step 1: Check for Node / npm ─────────────────────────────────────────────
if ! command -v npm > /dev/null 2>&1; then
    error "npm is not installed. Install Node.js from https://nodejs.org and try again."
fi

# ── Step 2: Install codex if missing ─────────────────────────────────────────
if ! command -v codex > /dev/null 2>&1; then
    warn "Codex is not installed. Installing now..."
    npm install -g @openai/codex
    success "Codex installed."
else
    success "Codex is already installed ($(codex --version 2>/dev/null || echo 'version unknown'))."
fi

# ── Step 3: Run codex login ───────────────────────────────────────────────────
AUTH_FILE="$HOME/.codex/auth.json"

info "Running 'codex login'..."
echo "    A browser will open. Sign in with your ChatGPT account."
echo ""

codex login

if [ ! -f "$AUTH_FILE" ]; then
    error "auth.json not found at $AUTH_FILE. Did the login succeed?"
fi

success "Login complete. auth.json found."

# ── Step 4: Build the JSON payload ───────────────────────────────────────────
# We need to embed the auth.json content as a JSON string value.
# Use Python3 (available on macOS & most Linux) or jq as fallback.

AUTH_CONTENT=$(cat "$AUTH_FILE")

if command -v python3 > /dev/null 2>&1; then
    PAYLOAD=$(python3 -c "
import json, sys
content = sys.stdin.read()
print(json.dumps({'json': content}))
" <<< "$AUTH_CONTENT")
elif command -v jq > /dev/null 2>&1; then
    PAYLOAD=$(jq -n --arg content "$AUTH_CONTENT" '{"json": $content}')
else
    error "Could not build JSON payload safely. Please install python3 or jq and try again."
fi

# ── Step 5: Upload to PocketDev ───────────────────────────────────────────────
info "Uploading auth.json to PocketDev..."

HTTP_CODE=$(curl -s -o /tmp/pd_codex_response.json -w "%{http_code}" \
    -X POST \
    "${PD_URL}/api/codex/auth/upload" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD")

RESPONSE=$(cat /tmp/pd_codex_response.json 2>/dev/null || echo '{}')
rm -f /tmp/pd_codex_response.json

# ── Step 6: Check result ──────────────────────────────────────────────────────
if echo "$RESPONSE" | grep -q '"success":true'; then
    echo ""
    success "${BOLD}Codex is now linked to PocketDev!${RESET}"
    echo ""
    echo "  You can now use Codex in PocketDev."
    echo "  Go to ${PD_URL} to get started."
    echo ""
elif [ "$HTTP_CODE" = "400" ] && echo "$RESPONSE" | grep -q '"message"'; then
    MSG=$(echo "$RESPONSE" | grep -o '"message":"[^"]*"' | head -1 | cut -d'"' -f4)
    error "Server error: $MSG"
elif [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "403" ]; then
    error "Access denied (HTTP $HTTP_CODE). Add your Basic Auth credentials to the URL:\n    $0 https://user:password@your-pocketdev.com"
elif [ "$HTTP_CODE" = "000" ]; then
    error "Could not reach PocketDev. Check the URL and your internet connection:\n    ${PD_URL}"
else
    error "Upload failed (HTTP $HTTP_CODE):\n$RESPONSE"
fi
