#!/bin/bash
#
# Claude Code Configuration Export Script
#
# This script exports your Claude Code configuration files into a portable
# archive that can be imported into PocketDev or another machine.
#
# Usage:
#   ./export-claude-config.sh
#   # or
#   curl -sL https://raw.githubusercontent.com/your-repo/scripts/export-claude-config.sh | bash
#
# Output:
#   ~/claude-config-export-YYYY-MM-DD.tar.gz
#

set -e

# Cleanup trap - ensures temp directory is removed on exit
cleanup() {
    [[ -d "$TEMP_DIR" ]] && rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
CLAUDE_DIR="$HOME/.claude"
CLAUDE_JSON="$HOME/.claude.json"
EXPORT_DATE=$(date +%Y-%m-%d)
EXPORT_NAME="claude-config-export-$EXPORT_DATE"
TEMP_DIR=$(mktemp -d)
EXPORT_DIR="$TEMP_DIR/$EXPORT_NAME"
OUTPUT_FILE="$HOME/$EXPORT_NAME.tar.gz"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Claude Code Configuration Export                       ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo

# Check for jq (needed for JSON extraction)
if ! command -v jq &> /dev/null; then
    echo -e "${YELLOW}Warning: 'jq' is not installed. MCP servers will not be exported.${NC}"
    echo -e "${YELLOW}Install jq with: brew install jq (macOS) or apt install jq (Linux)${NC}"
    echo
    HAS_JQ=false
else
    HAS_JQ=true
fi

# Create export directory
mkdir -p "$EXPORT_DIR"

# Track what we export
EXPORTED_ITEMS=()
SKIPPED_ITEMS=()

# Function to copy file if it exists
copy_if_exists() {
    local src="$1"
    local dest="$2"
    local name="$3"

    if [[ -f "$src" ]]; then
        cp "$src" "$dest"
        EXPORTED_ITEMS+=("$name")
        echo -e "  ${GREEN}✓${NC} $name"
    else
        SKIPPED_ITEMS+=("$name (not found)")
        echo -e "  ${YELLOW}○${NC} $name (not found)"
    fi
}

# Function to copy directory if it exists and has content
copy_dir_if_exists() {
    local src="$1"
    local dest="$2"
    local name="$3"

    if [[ -d "$src" ]] && [[ -n "$(ls -A "$src" 2>/dev/null)" ]]; then
        cp -r "$src" "$dest"
        local count
        count=$(find "$dest" -type f | wc -l | tr -d ' ')
        EXPORTED_ITEMS+=("$name ($count files)")
        echo -e "  ${GREEN}✓${NC} $name ($count files)"
    else
        SKIPPED_ITEMS+=("$name (empty or not found)")
        echo -e "  ${YELLOW}○${NC} $name (empty or not found)"
    fi
}

echo -e "${BLUE}Exporting configuration...${NC}"
echo

# Export settings.json
copy_if_exists "$CLAUDE_DIR/settings.json" "$EXPORT_DIR/settings.json" "settings.json"

# Export CLAUDE.md
copy_if_exists "$CLAUDE_DIR/CLAUDE.md" "$EXPORT_DIR/CLAUDE.md" "CLAUDE.md"

# Export directories
copy_dir_if_exists "$CLAUDE_DIR/agents" "$EXPORT_DIR/agents" "agents/"
copy_dir_if_exists "$CLAUDE_DIR/commands" "$EXPORT_DIR/commands" "commands/"
copy_dir_if_exists "$CLAUDE_DIR/rules" "$EXPORT_DIR/rules" "rules/"

# Export MCP servers from claude.json (if jq available)
if [[ "$HAS_JQ" == true ]] && [[ -f "$CLAUDE_JSON" ]]; then
    MCP_SERVERS=$(jq '.mcpServers // empty' "$CLAUDE_JSON" 2>/dev/null)
    if [[ -n "$MCP_SERVERS" ]] && [[ "$MCP_SERVERS" != "null" ]] && [[ "$MCP_SERVERS" != "{}" ]]; then
        echo "$MCP_SERVERS" > "$EXPORT_DIR/mcp-servers.json"
        EXPORTED_ITEMS+=("mcp-servers.json")
        echo -e "  ${GREEN}✓${NC} mcp-servers.json (from ~/.claude.json)"
    else
        SKIPPED_ITEMS+=("mcp-servers.json (no MCP servers configured)")
        echo -e "  ${YELLOW}○${NC} mcp-servers.json (no MCP servers configured)"
    fi
elif [[ "$HAS_JQ" == false ]] && [[ -f "$CLAUDE_JSON" ]]; then
    echo -e "  ${YELLOW}○${NC} mcp-servers.json (jq not installed)"
fi

echo

# Check if we have anything to export
if [[ ${#EXPORTED_ITEMS[@]} -eq 0 ]]; then
    echo -e "${RED}No configuration files found to export.${NC}"
    echo -e "Expected files in: $CLAUDE_DIR"
    exit 1  # Cleanup handled by trap
fi

# Build exportedItems JSON array (bash fallback if jq unavailable)
if [[ "$HAS_JQ" == true ]]; then
    ITEMS_JSON=$(printf '%s\n' "${EXPORTED_ITEMS[@]}" | jq -R . | jq -s .)
else
    ITEMS_JSON='['
    for i in "${!EXPORTED_ITEMS[@]}"; do
        [[ $i -gt 0 ]] && ITEMS_JSON+=','
        # Basic JSON escaping for the item
        escaped="${EXPORTED_ITEMS[$i]//\\/\\\\}"
        escaped="${escaped//\"/\\\"}"
        ITEMS_JSON+="\"$escaped\""
    done
    ITEMS_JSON+=']'
fi

# Create manifest
cat > "$EXPORT_DIR/manifest.json" << EOF
{
    "version": "1.0",
    "exportDate": "$EXPORT_DATE",
    "exportedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "hostname": "$(hostname)",
    "exportedItems": $ITEMS_JSON
}
EOF

# Create tarball
echo -e "${BLUE}Creating archive...${NC}"
tar -czf "$OUTPUT_FILE" -C "$TEMP_DIR" "$EXPORT_NAME"

# Cleanup handled by EXIT trap

# Summary
echo
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     Export Complete!                                       ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo
echo -e "${BLUE}Output:${NC} $OUTPUT_FILE"
echo -e "${BLUE}Size:${NC}   $(du -h "$OUTPUT_FILE" | cut -f1)"
echo

echo -e "${BLUE}Exported:${NC}"
for item in "${EXPORTED_ITEMS[@]}"; do
    echo -e "  • $item"
done

if [[ ${#SKIPPED_ITEMS[@]} -gt 0 ]]; then
    echo
    echo -e "${YELLOW}Skipped:${NC}"
    for item in "${SKIPPED_ITEMS[@]}"; do
        echo -e "  • $item"
    done
fi

echo
echo -e "${BLUE}Next steps:${NC}"
echo -e "  1. Transfer this file to your PocketDev instance"
echo -e "  2. During setup, upload when prompted for configuration import"
echo -e "  3. Complete authentication with: docker exec -it pocket-dev-queue claude"
echo
echo -e "${YELLOW}Note:${NC} OAuth credentials are NOT included (PocketDev authenticates separately)"
echo
