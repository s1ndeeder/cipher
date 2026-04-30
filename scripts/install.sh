#!/bin/bash
# Cipher one-line installer
# Usage: bash <(curl -sL https://raw.githubusercontent.com/s1ndeeder/cipher/main/scripts/install.sh) [/path/to/wordpress]

set -e

WP_PATH="${1:-$(pwd)}"
WP_PATH="$(cd "$WP_PATH" && pwd)"  # absolute path

if [[ ! -f "$WP_PATH/wp-config.php" ]]; then
    echo "❌ wp-config.php not found in $WP_PATH"
    echo "   Usage: bash install.sh /path/to/wordpress"
    exit 1
fi

KEY=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 32)
TOOL_NAME="cipher-tool.php"

echo "📥 Downloading cipher-tool.php..."
curl -sL https://raw.githubusercontent.com/s1ndeeder/cipher/main/scripts/cipher-tool.php -o "$WP_PATH/$TOOL_NAME"

# Replace key
sed -i "s|CHANGE_ME_BEFORE_USE|$KEY|" "$WP_PATH/$TOOL_NAME"

# Ownership
WP_USER=$(stat -c '%U' "$WP_PATH/wp-config.php")
WP_GROUP=$(stat -c '%G' "$WP_PATH/wp-config.php")
chown "$WP_USER:$WP_GROUP" "$WP_PATH/$TOOL_NAME"
chmod 644 "$WP_PATH/$TOOL_NAME"

# =====================================================
# Detect site URL (multiple strategies)
# =====================================================
SITE_URL=""

# Strategy 1: WP-CLI if available
if command -v wp &>/dev/null; then
    SITE_URL=$(cd "$WP_PATH" && wp option get siteurl --allow-root --skip-themes --skip-plugins 2>/dev/null || true)
fi

# Strategy 2: Read from DB directly via PHP + wp-config credentials
if [[ -z "$SITE_URL" ]]; then
    SITE_URL=$(php -r "
        \$path = '$WP_PATH';
        \$config = file_get_contents(\$path . '/wp-config.php');
        preg_match(\"/define\\(\\s*['\\\"]DB_NAME['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$config, \$db);
        preg_match(\"/define\\(\\s*['\\\"]DB_USER['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$config, \$user);
        preg_match(\"/define\\(\\s*['\\\"]DB_PASSWORD['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$config, \$pass);
        preg_match(\"/define\\(\\s*['\\\"]DB_HOST['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$config, \$host);
        preg_match(\"/\\\$table_prefix\\s*=\\s*['\\\"]([^'\\\"]+)['\\\"]/\", \$config, \$prefix);
        if (!\$db || !\$user || !\$pass) exit;
        try {
            \$pdo = new PDO('mysql:host=' . (\$host[1] ?? 'localhost') . ';dbname=' . \$db[1], \$user[1], \$pass[1]);
            \$stmt = \$pdo->query('SELECT option_value FROM ' . (\$prefix[1] ?? 'wp_') . 'options WHERE option_name=\"siteurl\" LIMIT 1');
            echo \$stmt->fetchColumn();
        } catch(Exception \$e) {}
    " 2>/dev/null || true)
fi

# Strategy 3: Guess from directory name (last fallback)
if [[ -z "$SITE_URL" ]]; then
    DIR_NAME=$(basename "$WP_PATH")
    if [[ "$DIR_NAME" == *.* ]]; then
        SITE_URL="https://$DIR_NAME"
    fi
fi

[[ -z "$SITE_URL" ]] && SITE_URL="https://YOUR-DOMAIN"

echo ""
echo "================================================"
echo "✅ Cipher Tool installed successfully"
echo "================================================"
echo ""
echo "  🌐 URL:  $SITE_URL/$TOOL_NAME"
echo "  🔑 Key:  $KEY"
echo "  📁 Path: $WP_PATH/$TOOL_NAME"
echo ""
echo "  After use, click 'Self-Destruct' in the UI"
echo "  or run: rm $WP_PATH/$TOOL_NAME"
echo ""
echo "================================================"
