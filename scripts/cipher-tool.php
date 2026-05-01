<?php
/**
 * Cipher WP Runner v5
 * - Lazy loading of disk metrics (button-triggered)
 * - File scan with limits to avoid timeouts
 */

define('CIPHER_KEY', 'CHANGE_ME_BEFORE_USE');
define('CIPHER_VERSION', '5.0.0');

session_start();

// Self-destruct
if (isset($_GET['destroy']) && !empty($_SESSION['authed']) && $_GET['destroy'] === 'confirm') {
    $self = __FILE__;
    $allowed = ['cipher-tool.php', 'wp-runner.php', 'cipher-runner.php'];
    if (!in_array(basename($self), $allowed, true)) die('<h2 style="color:red">SAFETY ABORT</h2>');
    if (!is_file($self)) die('<h2 style="color:red">Not a regular file.</h2>');
    @unlink($self);
    if (!file_exists($self)) {
        session_destroy();
        die('<div style="font-family:monospace;padding:40px;text-align:center;background:#1e1e1e;color:#4ec9b0;min-height:100vh;">
            <h1>💥 Self-destructed</h1></div>');
    }
}

// Auth
if (isset($_POST['auth_key'])) {
    if ($_POST['auth_key'] === CIPHER_KEY) $_SESSION['authed'] = true;
    else die('<h2 style="color:red;font-family:monospace;padding:40px">Wrong key</h2>');
}

if (empty($_SESSION['authed'])) {
    echo '<!DOCTYPE html><html><head><title>Cipher</title>
    <style>body{font-family:monospace;padding:40px;background:#1e1e1e;color:#d4d4d4;}
    input{padding:10px;width:300px;background:#2d2d2d;color:#fff;border:1px solid #555;}
    button{padding:10px 20px;background:#0e639c;color:#fff;border:none;cursor:pointer;}</style>
    </head><body><h2>🔐 Cipher WP Runner v' . CIPHER_VERSION . '</h2>
    <form method="post"><input type="password" name="auth_key" placeholder="Enter access key" autofocus><br><br>
    <button type="submit">Authenticate</button></form></body></html>';
    exit;
}

// Load WP
$wpLoad = __DIR__ . '/wp-load.php';
if (!file_exists($wpLoad)) die('<h2 style="color:red">wp-load.php not found</h2>');
define('WP_USE_THEMES', false);
require_once($wpLoad);
if (!defined('ABSPATH')) die('<h2 style="color:red">WP did not load</h2>');

// =====================================================
// HELPERS
// =====================================================
function fmt_bytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function dir_size_safe($path, $extensions = null, $maxFiles = 100000, $maxTime = 30) {
    if (!is_dir($path)) return ['size' => 0, 'count' => 0, 'truncated' => false];
    $size = 0;
    $count = 0;
    $start = microtime(true);
    $truncated = false;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iter as $file) {
            if ($count >= $maxFiles || (microtime(true) - $start) > $maxTime) {
                $truncated = true;
                break;
            }
            if (!$file->isFile()) continue;
            if ($extensions !== null) {
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions, true)) continue;
            }
            $size += $file->getSize();
            $count++;
        }
    } catch (Exception $e) {}
    return ['size' => $size, 'count' => $count, 'truncated' => $truncated];
}

function db_size() {
    global $wpdb;
    $r = $wpdb->get_row("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
    return (int) ($r->size ?? 0);
}

// =====================================================
// ACTIONS
// =====================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$output = '';
$scanResults = null;

if ($action === 'scan') {
    @set_time_limit(120);
    @ini_set('memory_limit', '512M');
    
    $rootPath = ABSPATH;
    $contentPath = WP_CONTENT_DIR;
    $uploadsPath = wp_upload_dir()['basedir'];
    
    $scanResults = [
        'total' => dir_size_safe($rootPath),
        'media' => dir_size_safe($uploadsPath, ['aac','avi','mp3','mp4','mpeg','mpg','mov','wav','flac','wmv','webm','ogg','jpg','jpeg','png','gif','webp','svg','bmp','tiff']),
        'archives' => dir_size_safe($contentPath, ['zip','tar','gz','bz2','rar','7z','iso','dmg','wpress','ncpress']),
        'dbdumps' => dir_size_safe($rootPath, ['sql','sqlite','db','dump']),
        'execs' => dir_size_safe($rootPath, ['exe','bin','so','dll','app','apk']),
    ];
    $_SESSION['lastScan'] = $scanResults;
    $_SESSION['lastScanTime'] = time();
}

if (isset($_SESSION['lastScan']) && $scanResults === null) {
    $scanResults = $_SESSION['lastScan'];
}

if ($action === 'install_cipher') {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    
    if (!class_exists('Plugin_Upgrader')) require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (!class_exists('WP_Filesystem_Direct')) require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!function_exists('activate_plugin')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    
    WP_Filesystem();
    
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install('https://github.com/s1ndeeder/cipher/releases/latest/download/cipher-migration.zip');
    
    if (is_wp_error($result)) {
        $output = "Install failed: " . $result->get_error_message();
    } elseif ($result === false) {
        $output = "Install failed: " . implode("\n", $skin->get_error_messages());
    } else {
        $a = activate_plugin('cipher-migration/all-in-one-wp-migration-wi.php');
        $output = is_wp_error($a) ? "Activation failed: " . $a->get_error_message() : "✅ Cipher installed and activated!";
    }
}
elseif ($action === 'post_restore_cleanup') {
    @set_time_limit(120);
    $logs = [];
    
    // 1. Flush rewrite rules
    flush_rewrite_rules(true);
    $logs[] = "✅ Rewrite rules flushed";
    
    // 2. Ensure required uploads dirs exist
    $required_dirs = [
        'elementor/css', 'elementor/thumbs',
        'wpforms', 'woocommerce_uploads',
        'wc-logs', 'cache',
    ];
    $upload_base = wp_upload_dir()['basedir'];
    foreach ($required_dirs as $dir) {
        $path = $upload_base . '/' . $dir;
        if (!is_dir($path)) {
            wp_mkdir_p($path);
            $logs[] = "✅ Created: uploads/$dir";
        }
    }
    
    // 3. Flush all caches
    wp_cache_flush();
    $logs[] = "✅ WP cache flushed";
    
    // 4. Elementor CSS regen
    if (class_exists('\Elementor\Plugin')) {
        try {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $logs[] = "✅ Elementor CSS cache cleared";
        } catch (Exception $e) {
            $logs[] = "⚠ Elementor: " . $e->getMessage();
        }
    }
    
    // 5. Reactivate active plugins (refresh their hooks)
    if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $active = get_option('active_plugins', []);
    $logs[] = "ℹ Active plugins: " . count($active);
    
    // 6. Permalinks structure
    $permalink = get_option('permalink_structure');
    $logs[] = "ℹ Permalink structure: " . ($permalink ?: 'default (plain)');
    
    // 7. .htaccess if missing
    $htaccess = ABSPATH . '.htaccess';
    if (!file_exists($htaccess) || !str_contains(@file_get_contents($htaccess) ?: '', 'RewriteEngine')) {
        @file_put_contents($htaccess, "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php\$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n");
        $logs[] = "✅ .htaccess restored";
    }
    
    $output = implode("\n", $logs);
}
elseif ($action === 'backup') {
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    
    if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!is_plugin_active('cipher-migration/all-in-one-wp-migration-wi.php')) {
        $output = "❌ Cipher not active";
    } else {
        ob_start();
        try {
            $params = array('storage' => uniqid());
            $hooks = ['Ai1wm_Export_Init','Ai1wm_Export_Config','Ai1wm_Export_Enumerate','Ai1wm_Export_Content','Ai1wm_Export_Database','Ai1wm_Export_Compatibility','Ai1wm_Export_Archive','Ai1wm_Export_Download','Ai1wm_Export_Clean'];
            foreach ($hooks as $cls) {
                $hook = $cls . '::execute';
                while (true) {
                    $params = call_user_func($hook, $params);
                    if (empty($params['completed'])) break;
                }
            }
            $output = "✅ Backup completed!";
        } catch (Exception $e) { $output = "❌ " . $e->getMessage(); }
        $debug = ob_get_clean();
        if ($debug) $output .= "\n\n" . $debug;
    }
}

// =====================================================
// CHEAP METRICS (always shown)
// =====================================================
$diskFree = @disk_free_space(ABSPATH);
$diskTotal = @disk_total_space(ABSPATH);
$dbSizeBytes = db_size();

// Backups
$backups = [];
$backupDir = WP_CONTENT_DIR . '/ai1wm-backups';
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '/*.{ncpress,wpress}', GLOB_BRACE) as $file) {
        $backups[] = ['name' => basename($file), 'size' => filesize($file), 'url' => content_url('/ai1wm-backups/' . basename($file)), 'mtime' => filemtime($file)];
    }
    usort($backups, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
$pluginActive = is_plugin_active('cipher-migration/all-in-one-wp-migration-wi.php');

$LIMIT_10GB = 10 * 1024 * 1024 * 1024;

// AUP / Summary
$aupViolations = [];
if ($scanResults) {
    if ($scanResults['media']['size'] > $LIMIT_10GB) $aupViolations[] = "Media (" . fmt_bytes($scanResults['media']['size']) . ")";
    if ($scanResults['archives']['size'] > $LIMIT_10GB) $aupViolations[] = "Archives (" . fmt_bytes($scanResults['archives']['size']) . ")";
    if ($scanResults['dbdumps']['size'] > $LIMIT_10GB) $aupViolations[] = "DB dumps (" . fmt_bytes($scanResults['dbdumps']['size']) . ")";
    if ($scanResults['execs']['size'] > $LIMIT_10GB) $aupViolations[] = "Executables (" . fmt_bytes($scanResults['execs']['size']) . ")";
}
$aupStatus = $scanResults ? (empty($aupViolations) ? 'OK' : 'NOT OK (' . implode(', ', $aupViolations) . ')') : 'Not scanned yet';

$summary = "Evaluated by: \n";
$summary .= "Site: " . get_site_url() . "\n";
$summary .= "Disk Usage: " . ($scanResults ? fmt_bytes($scanResults['total']['size']) : 'Run scan first') . "\n";
$summary .= "Inodes: " . ($scanResults ? number_format($scanResults['total']['count']) : 'Run scan first') . "\n";
$summary .= "PHP Version: " . PHP_VERSION . "\n";
$summary .= "WP Version: " . get_bloginfo('version') . "\n";
$summary .= "DB Size: " . fmt_bytes($dbSizeBytes) . "\n";
$summary .= "AUPs: " . $aupStatus . "\n";

function status_box($label, $size, $limit, $hint = '') {
    $pct = $limit > 0 ? min(100, ($size / $limit) * 100) : 0;
    $color = $pct < 70 ? '#0e7c4a' : ($pct < 90 ? '#cc7a00' : '#a1260d');
    $icon = $pct < 70 ? '✓' : ($pct < 90 ? '⚠' : '⛔');
    echo '<div style="margin:8px 0;">';
    echo "<div style='display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;'><span><strong>$icon $label</strong> " . ($hint ? "<span style='color:#888'>($hint)</span>" : "") . "</span><span>" . fmt_bytes($size) . " / " . fmt_bytes($limit) . " (" . round($pct,1) . "%)</span></div>";
    echo "<div style='background:#333;height:8px;border-radius:4px;overflow:hidden;'><div style='background:$color;width:{$pct}%;height:100%;'></div></div>";
    echo '</div>';
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Cipher WP Runner v<?= CIPHER_VERSION ?></title>
<style>
body{font-family:'SF Mono',Consolas,monospace;padding:20px;max-width:1200px;margin:0 auto;background:#1e1e1e;color:#d4d4d4;}
h2{color:#4ec9b0;margin-top:0;}h3{color:#9cdcfe;border-bottom:1px solid #333;padding-bottom:8px;}
.card{background:#252526;padding:20px;border-radius:8px;margin:15px 0;border:1px solid #333;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
button,.btn{padding:10px 20px;background:#0e639c;color:#fff;border:none;cursor:pointer;border-radius:4px;font-family:inherit;font-size:14px;display:inline-block;text-decoration:none;margin:3px;}
button:hover,.btn:hover{background:#1177bb;}
.btn-danger{background:#a1260d;}.btn-danger:hover{background:#c42b0d;}
.btn-success{background:#0e7c4a;}.btn-success:hover{background:#15a05c;}
.btn-copy{background:#5a3a8a;}.btn-copy:hover{background:#7050a8;}
.btn-warn{background:#cc7a00;}.btn-warn:hover{background:#e89500;}
pre{background:#000;padding:15px;border-radius:4px;overflow:auto;max-height:300px;border:1px solid #333;white-space:pre-wrap;}
.status{display:inline-block;padding:3px 10px;border-radius:3px;font-size:12px;}
.status-ok{background:#0e7c4a;color:#fff;}.status-no{background:#a1260d;color:#fff;}
a{color:#4fc1ff;}
.info{background:#1c3548;padding:12px;border-left:4px solid #4fc1ff;border-radius:3px;margin:10px 0;}
.metric{padding:10px;background:#1a1a1a;border-radius:4px;}
.metric .v{font-size:24px;color:#4ec9b0;}
.metric .l{font-size:12px;color:#999;}
table{width:100%;border-collapse:collapse;}
table td{padding:8px;border-bottom:1px solid #333;}
.summary-box{background:#0a0a0a;border:1px solid #555;padding:15px;border-radius:4px;font-size:13px;line-height:1.6;}
@media(max-width:768px){.grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<h2>🔐 Cipher WP Runner <span style="color:#666;font-size:14px;">v<?= CIPHER_VERSION ?></span></h2>
<div class="info">
    <strong><?= htmlspecialchars(get_site_url()) ?></strong> | WP <?= get_bloginfo('version') ?> | PHP <?= PHP_VERSION ?> | <?= htmlspecialchars(ABSPATH) ?>
</div>

<div class="card">
<h3>📋 Summary <button class="btn btn-copy" onclick="copySummary()">📋 Copy</button></h3>
<div class="summary-box" id="summary-text"><?= nl2br(htmlspecialchars($summary)) ?></div>
</div>

<div class="card">
<h3>📊 Site Health (cheap metrics)</h3>
<div class="grid">
    <div class="metric"><div class="l">Disk free / total</div><div class="v" style="font-size:18px;"><?= $diskFree ? fmt_bytes($diskFree) : 'n/a' ?> / <?= $diskTotal ? fmt_bytes($diskTotal) : 'n/a' ?></div></div>
    <div class="metric"><div class="l">Database size</div><div class="v"><?= fmt_bytes($dbSizeBytes) ?></div></div>
</div>
</div>

<div class="card">
<h3>🔍 Disk Scan (heavy)</h3>
<?php if (!$scanResults): ?>
    <p>Click to scan all files. This may take 30-60 seconds for large sites.</p>
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="scan">
        <button type="submit" class="btn-warn">🔍 Run Disk Scan</button>
    </form>
<?php else: ?>
    <p style="color:#888;">Last scan: <?= date('Y-m-d H:i', $_SESSION['lastScanTime'] ?? 0) ?></p>
    <div class="grid">
        <div class="metric"><div class="l">Total install size</div><div class="v"><?= fmt_bytes($scanResults['total']['size']) ?></div></div>
        <div class="metric"><div class="l">Inodes</div><div class="v"><?= number_format($scanResults['total']['count']) ?></div></div>
    </div>
    <h4 style="color:#9cdcfe;margin-top:20px;">⚠️ AUP Limits (10GB per category)</h4>
    <?php
    status_box('Media files', $scanResults['media']['size'], $LIMIT_10GB, $scanResults['media']['count'] . ' files');
    status_box('Archives', $scanResults['archives']['size'], $LIMIT_10GB, $scanResults['archives']['count'] . ' files');
    status_box('Database dumps', $scanResults['dbdumps']['size'], $LIMIT_10GB, $scanResults['dbdumps']['count'] . ' files');
    status_box('Executables', $scanResults['execs']['size'], $LIMIT_10GB, $scanResults['execs']['count'] . ' files');
    ?>
    <form method="post" style="display:inline;margin-top:10px;">
        <input type="hidden" name="action" value="scan">
        <button type="submit" class="btn-warn">🔄 Re-scan</button>
    </form>
<?php endif; ?>
</div>

<div class="card">
<h3>🧩 Plugin Status</h3>
<p>Cipher Migration: 
<?php if ($pluginActive): ?>
    <span class="status status-ok">✓ ACTIVE</span>
<?php else: ?>
    <span class="status status-no">✗ NOT INSTALLED</span>
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="install_cipher">
        <button type="submit">📥 Install & Activate</button>
    </form>
<?php endif; ?>
</p>
</div>

<?php if ($pluginActive): ?>
<div class="card">
<h3>🚀 Actions</h3>
<form method="post" style="display:inline">
    <input type="hidden" name="action" value="backup">
    <button type="submit" class="btn-success">📦 Create Backup (.ncpress)</button>
</form>
<form method="post" style="display:inline">
    <input type="hidden" name="action" value="post_restore_cleanup">
    <button type="submit" class="btn-warn">🔧 Post-Restore Cleanup</button>
</form>
</div>
<?php endif; ?>

<?php if ($output): ?>
<div class="card"><h3>Output</h3><pre><?= htmlspecialchars($output) ?></pre></div>
<?php endif; ?>

<div class="card">
<h3>📦 Backups (<?= count($backups) ?>)</h3>
<?php if (empty($backups)): ?>
    <p style="color:#888;">No backups yet.</p>
<?php else: ?>
    <table>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td><a href="<?= htmlspecialchars($b['url']) ?>" download>📁 <?= htmlspecialchars($b['name']) ?></a></td>
            <td style="color:#999;"><?= fmt_bytes($b['size']) ?></td>
            <td style="color:#999;"><?= date('Y-m-d H:i', $b['mtime']) ?></td>
            <td>
                <a href="<?= htmlspecialchars($b['url']) ?>" download class="btn" title="Download">⬇</a>
                <button onclick="copyToClipboard('<?= htmlspecialchars($b['url']) ?>', this)" class="btn btn-copy">📋 Copy Link</button>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>
<?php endif; ?>
</div>

<div class="card" style="border:2px solid #a1260d;">
<h3 style="color:#f48771;">⚠️ Self-Destruct</h3>
<p>Only deletes <?= htmlspecialchars(basename(__FILE__)) ?>, nothing else.</p>
<a href="?destroy=confirm" class="btn btn-danger" onclick="return confirm('Really delete this file?');">💥 Delete <?= htmlspecialchars(basename(__FILE__)) ?></a>
</div>

<script>
// Універсальна функція для копіювання
function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✅ Done!';
        btn.style.background = '#0e7c4a';
        setTimeout(() => { 
            btn.textContent = orig; 
            btn.style.background = ''; 
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}

function copySummary(event) {
    const text = <?= json_encode($summary) ?>;
    copyToClipboard(text, event.target);
}
</script>
</body>
</html>
