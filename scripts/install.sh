#!/bin/bash
# Cipher one-line installer
# Usage: bash <(curl -sL https://raw.githubusercontent.com/s1ndeeder/cipher/main/scripts/install.sh)

set -e

# Find WordPress root
WP_PATH="${1:-$(pwd)}"
if [[ ! -f "$WP_PATH/wp-config.php" ]]; then
    echo "❌ wp-config.php not found in $WP_PATH"
    echo "   Usage: bash install.sh /path/to/wordpress"
    exit 1
fi

# Generate random key
KEY=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 32)
TOOL_NAME="cipher-tool.php"

echo "📥 Downloading cipher-tool.php..."
curl -sL https://raw.githubusercontent.com/s1ndeeder/cipher/main/scripts/cipher-tool.php -o "$WP_PATH/$TOOL_NAME"

# Replace default key with random one
sed -i "s|CHANGE_ME_BEFORE_USE|$KEY|" "$WP_PATH/$TOOL_NAME"

# Set ownership to WP user (detect from wp-config)
WP_USER=$(stat -c '%U' "$WP_PATH/wp-config.php")
WP_GROUP=$(stat -c '%G' "$WP_PATH/wp-config.php")
chown "$WP_USER:$WP_GROUP" "$WP_PATH/$TOOL_NAME"
chmod 644 "$WP_PATH/$TOOL_NAME"

# Detect site URL
SITE_URL=$(grep -oP "siteurl.*?VALUES.*?'(https?://[^']+)'" "$WP_PATH/wp-config.php" 2>/dev/null | head -1 || echo "https://YOUR-SITE")
if [[ "$SITE_URL" == "https://YOUR-SITE" ]]; then
    # Fallback — try to grep from DB if WP-CLI exists
    if command -v wp &>/dev/null; then
        SITE_URL=$(cd "$WP_PATH" && wp option get siteurl --allow-root 2>/dev/null || echo "https://YOUR-SITE")
    fi
fi

echo ""
echo "================================================"
echo "✅ Cipher Tool installed successfully"
echo "================================================"
echo ""
echo "  🌐 URL:  $SITE_URL/$TOOL_NAME"
echo "  🔑 Key:  $KEY"
echo ""
echo "  After use, click 'Self-Destruct' in the UI"
echo "  or run: rm $WP_PATH/$TOOL_NAME"
echo ""
echo "================================================"
