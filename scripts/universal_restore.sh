#!/bin/bash
# Universal .wpress restore — bypasses plugin entirely
# Works with any .wpress format (old/new with checksum)
# Usage: bash universal_restore.sh /path/to/backup.wpress
set -e
BACKUP_FILE="${1:-}"
[[ -z "$BACKUP_FILE" || ! -f "$BACKUP_FILE" ]] && { echo "Usage: $0 backup.wpress"; exit 1; }
WP_PATH="/home/$(whoami)/public_html"
EXTRACT_DIR="/tmp/wpress_$$"
PREFIX=$(grep "table_prefix" "$WP_PATH/wp-config.php" | grep -oP "'[^']+'" | tail -1 | tr -d "'")
mkdir -p "$EXTRACT_DIR"
python3 "$(dirname "$0")/wpress_extract.py" "$BACKUP_FILE" "$EXTRACT_DIR"
sed -i "s/SERVMASK_PREFIX_/${PREFIX}/g; s/,0x,/,'',/g" "$EXTRACT_DIR/database.sql"
wp --path="$WP_PATH" db reset --yes
wp --path="$WP_PATH" db import "$EXTRACT_DIR/database.sql"
[[ -d "$EXTRACT_DIR/uploads" ]] && cp -rn "$EXTRACT_DIR/uploads/"* "$WP_PATH/wp-content/uploads/"
[[ -d "$EXTRACT_DIR/themes" ]]  && cp -rn "$EXTRACT_DIR/themes/"*  "$WP_PATH/wp-content/themes/"
[[ -d "$EXTRACT_DIR/plugins" ]] && cp -rn "$EXTRACT_DIR/plugins/"* "$WP_PATH/wp-content/plugins/"
wp --path="$WP_PATH" cache flush
wp --path="$WP_PATH" rewrite flush --hard
rm -rf "$EXTRACT_DIR"
echo "Done."
