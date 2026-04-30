# Cipher Migration

PHP 8.x compatible fork of All-in-One WP Migration with support for new `.wpress` format archives (with checksum tail).

## Why this exists

The upstream patched plugin breaks on:
- **PHP 8.0+** — `mysqli_query()` throws `ValueError` on empty queries
- **PHP 8.1+** — Deprecated return type warnings on iterator/filter classes
- **PHP 8.2+** — Dynamic property creation deprecation
- **New `.wpress` archives** — Files exported by official ai1wm v7.x have an 8-byte checksum tail that the patched plugin treats as corruption

This fork fixes all of the above.

## Patches Applied

| # | File | Issue | Fix |
|---|------|-------|-----|
| 1 | `class-ai1wm-database-mysqli.php` | `mysqli_query()` empty arg ValueError | Empty input check before query |
| 2 | `class-ai1wm-archiver.php` | New format archives marked corrupted | `is_valid()` accepts both -4377 and -4385 EOF offsets |
| 3 | iterator/filter classes | PHP 8.1 deprecated return types | `#[\ReturnTypeWillChange]` attributes |
| 4 | `class-ai1wm-database.php`, `class-ai1wm-database-mysqli.php` | PHP 8.2 dynamic properties | `#[\AllowDynamicProperties]` attribute |

## Quick Install

```bash
# Via WP-CLI
cd /path/to/wordpress
wp plugin install https://github.com/s1ndeeder/cipher/releases/latest/download/cipher-migration.zip --activate

# Or download manually
wget https://github.com/s1ndeeder/cipher/releases/latest/download/cipher-migration.zip
unzip cipher-migration.zip -d wp-content/plugins/
wp plugin activate cipher-migration
```

## Manual Build from Source

```bash
git clone git@github.com:s1ndeeder/cipher.git
cd cipher
zip -r cipher-migration.zip cipher-migration/ -x "*/storage/*" "*.git*"
```

## Usage

Plugin registers itself as `ai1wm` in WP-CLI:

```bash
# Backup
wp ai1wm backup

# Restore
wp ai1wm restore /path/to/backup.wpress --yes

# List backups
wp ai1wm list-backups
```

## Restoring Archives Without the Plugin

For cases where the plugin won't run (different PHP version, missing dependencies), use the standalone Python extractor:

```bash
python3 scripts/wpress_extract.py backup.wpress /tmp/extracted/
```

This produces:
- `database.sql` — patched to use your `wp_` prefix and fix empty BLOBs
- `wp-content/` — uploads, themes, plugins to copy in place

## Credits

Based on the patched fork of All-in-One WP Migration. Original plugin by ServMask Inc., licensed under GPL v3.

## License

GPL v3 (inherited from upstream)
