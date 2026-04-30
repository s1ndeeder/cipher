#!/bin/bash
# ============================================================
#  CIPHER MIGRATION — Master Patch Script
#  Applies all PHP 8.x compatibility and format fixes
# ============================================================

set -e
PLUGIN_DIR="${1:-./cipher-migration}"
[[ ! -d "$PLUGIN_DIR" ]] && { echo "Plugin dir not found: $PLUGIN_DIR"; exit 1; }

echo "=== Applying patches to $PLUGIN_DIR ==="

# Patch helper: searches for marker, skips if already applied
already_patched() {
    grep -q "$1" "$2" 2>/dev/null
}

# ============================================================
# PATCH 1: mysqli_query() empty argument (PHP 8)
# ============================================================
F1="$PLUGIN_DIR/lib/vendor/servmask/database/class-ai1wm-database-mysqli.php"
if [[ -f "$F1" ]]; then
    if already_patched "CIPHER_PATCH_1" "$F1"; then
        echo "[skip] Patch 1: already applied"
    else
        php -r '
$f = "'$F1'";
$c = file_get_contents($f);
$old = "public function query( \$input ) {\n\t\treturn mysqli_query( \$this->wpdb->dbh, \$input, MYSQLI_STORE_RESULT );";
$new = "public function query( \$input ) { // CIPHER_PATCH_1\n\t\tif ( empty( trim( (string) \$input ) ) ) return false;\n\t\treturn mysqli_query( \$this->wpdb->dbh, \$input, MYSQLI_STORE_RESULT );";
file_put_contents($f, str_replace($old, $new, $c));
echo "[ok] Patch 1: empty mysqli_query check added\n";
'
    fi
fi

# ============================================================
# PATCH 2: is_valid() accepts new .wpress format with checksum
# ============================================================
F2="$PLUGIN_DIR/lib/vendor/servmask/archiver/class-ai1wm-archiver.php"
if [[ -f "$F2" ]]; then
    if already_patched "CIPHER_PATCH_2" "$F2"; then
        echo "[skip] Patch 2: already applied"
    else
        php << 'PHPEOF'
$f = "/root/cipher/cipher-migration/lib/vendor/servmask/archiver/class-ai1wm-archiver.php";
$c = file_get_contents($f);
$old = 'public function is_valid() {
		if ( ( $offset = @ftell( $this->file_handle ) ) !== false ) {
			if ( @fseek( $this->file_handle, -4377, SEEK_END ) !== -1 ) {
				if ( @fread( $this->file_handle, 4377 ) === $this->eof ) {
					if ( @fseek( $this->file_handle, $offset, SEEK_SET ) !== -1 ) {
						return true;
					}
				}
			}
		}
		return false;
	}';
$new = 'public function is_valid() { // CIPHER_PATCH_2 — supports new format with checksum tail
		if ( ( $offset = @ftell( $this->file_handle ) ) !== false ) {
			// Old format: EOF block at -4377 from end
			if ( @fseek( $this->file_handle, -4377, SEEK_END ) !== -1 ) {
				if ( @fread( $this->file_handle, 4377 ) === $this->eof ) {
					if ( @fseek( $this->file_handle, $offset, SEEK_SET ) !== -1 ) {
						return true;
					}
				}
			}
			// New format: EOF block at -4385 (8-byte checksum tail after EOF)
			if ( @fseek( $this->file_handle, -4385, SEEK_END ) !== -1 ) {
				if ( @fread( $this->file_handle, 4377 ) === $this->eof ) {
					if ( @fseek( $this->file_handle, $offset, SEEK_SET ) !== -1 ) {
						return true;
					}
				}
			}
		}
		return false;
	}';
if (strpos($c, $old) !== false) {
    file_put_contents($f, str_replace($old, $new, $c));
    echo "[ok] Patch 2: is_valid() now supports both old and new formats\n";
} else {
    echo "[warn] Patch 2: original is_valid() pattern not found\n";
}
PHPEOF
    fi
fi

# ============================================================
# PATCH 3: ReturnTypeWillChange for iterator/filter classes (PHP 8.1+)
# ============================================================
ITERATOR_FILES=(
    "$PLUGIN_DIR/lib/vendor/servmask/iterator/class-ai1wm-recursive-directory-iterator.php"
    "$PLUGIN_DIR/lib/vendor/servmask/filter/class-ai1wm-recursive-extension-filter.php"
    "$PLUGIN_DIR/lib/vendor/servmask/filter/class-ai1wm-recursive-exclude-filter.php"
    "$PLUGIN_DIR/lib/vendor/servmask/filter/class-ai1wm-recursive-newline-filter.php"
)

for FILE in "${ITERATOR_FILES[@]}"; do
    if [[ -f "$FILE" ]]; then
        if already_patched "CIPHER_PATCH_3" "$FILE"; then
            echo "[skip] Patch 3: $FILE already done"
        else
            # Add #[\ReturnTypeWillChange] before public function declarations
            sed -i 's|^\(\s*\)public function \(hasChildren\|rewind\|next\|getChildren\|accept\)|\1#[\\ReturnTypeWillChange] // CIPHER_PATCH_3\n\1public function \2|' "$FILE"
            echo "[ok] Patch 3: added ReturnTypeWillChange to $(basename $FILE)"
        fi
    fi
done

# ============================================================
# PATCH 4: Dynamic property declarations (PHP 8.2+ deprecation)
# ============================================================
F4="$PLUGIN_DIR/lib/vendor/servmask/database/class-ai1wm-database.php"
if [[ -f "$F4" ]]; then
    if already_patched "CIPHER_PATCH_4" "$F4"; then
        echo "[skip] Patch 4: already applied"
    else
        # Add #[\AllowDynamicProperties] attribute to the abstract class
        sed -i '/^abstract class Ai1wm_Database/i #[\\AllowDynamicProperties] // CIPHER_PATCH_4' "$F4"
        # Also patch mysqli database
        F4B="$PLUGIN_DIR/lib/vendor/servmask/database/class-ai1wm-database-mysqli.php"
        if [[ -f "$F4B" ]] && ! already_patched "CIPHER_PATCH_4" "$F4B"; then
            sed -i '/^class Ai1wm_Database_Mysqli/i #[\\AllowDynamicProperties] // CIPHER_PATCH_4' "$F4B"
        fi
        echo "[ok] Patch 4: AllowDynamicProperties added"
    fi
fi

echo ""
echo "=== All patches applied ==="
