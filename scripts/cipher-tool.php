<?php
/**
 * Cipher WP Runner v4
 * Single-file deployment for plugin install + .ncpress backup creation
 * Includes: site health, hosting limits check, clipboard summary, self-destruct
 */

define('CIPHER_KEY', 'CHANGE_ME_BEFORE_USE');
define('CIPHER_VERSION', '4.0.0');

session_start();

// =====================================================
// SELF-DESTRUCT (with safety checks)
// =====================================================
if (isset($_GET['destroy']) && !empty($_SESSION['authed']) && $_GET['destroy'] === 'confirm') {
    $self = __FILE__;
    // Triple-check: this MUST be cipher-tool.php in current directory
    $allowed = ['cipher-tool.php', 'wp-runner.php', 'cipher-runner.php'];
    if (!in_array(basename($self), $allowed, true)) {
        die('<h2 style="color:red">SAFETY ABORT: filename mismatch. Will not delete.</h2>');
    }
    // Make sure file exists and is regular file
    if (!is_file($self)) {
        die('<h2 style="color:red">Not a regular file.</h2>');
    }
    @unlink($self);
    if (!file_exists($self)) {
        session_destroy();
        die('<div style="font-family:monospace;padding:40px;text-align:center;background:#1e1e1e;color:#4ec9b0;min-height:100vh;">
            <h1>💥 Self-destructed</h1><p>File deleted: ' . htmlspecialchars(basename($self)) . '</p></div>');
    } else {
        die('<h2 style="color:red;font-family:monospace;padding:40px">Failed to delete. Remove manually: ' . htmlspecialchars($self) . '</h2>');
    }
}

// =====================================================
// AUTH
// =====================================================
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

// =====================================================
// LOAD WORDPRESS
// =====================================================
$wpLoad = __DIR__ . '/wp-load.php';
if (!file_exists($wpLoad)) die('<h2 style="color:red">wp-load.php not found in ' . __DIR__ . '</h2>');
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

function dir_size($path, $extensions = null) {
    if (!is_dir($path)) return 0;
    $size = 0;
    try {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            if ($extensions !== null) {
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions, true)) continue;
            }
            $size += $file->getSize();
        }
    } catch (Exception $e) {}
    return $size;
}

function db_size() {
    global $wpdb;
    $r = $wpdb->get_row("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
    return (int) ($r->size ?? 0);
}

function count_inodes($path) {
    $count = 0;
    try {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) $count++;
    } catch (Exception $e) {}
    return $count;
}

// =====================================================
// ACTIONS
// =====================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$output = '';

if ($action === 'install_cipher') {
    if (!class_exists('Plugin_Upgrader')) require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (!class_exists('WP_Filesystem_Direct')) require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!function_exists('activate_plugin')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    WP_Filesystem();
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install('https://github.com/s1ndeeder/cipher/releases/latest/download/cipher-migration.zip');
    if (is_wp_error($result)) $output = "Install failed: " . $result->get_error_message();
    elseif ($result === false) $output = "Install failed: " . implode("\n", $skin->get_error_messages());
    else {
        $a = activate_plugin('cipher-migration/all-in-one-wp-migration-wi.php');
        $output = is_wp_error($a) ? "Activation failed: " . $a->get_error_message() : "✅ Cipher installed and activated!";
    }
}
elseif ($action === 'backup') {
    if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!is_plugin_active('cipher-migration/all-in-one-wp-migration-wi.php')) {
        $output = "❌ Cipher not active";
    } else {
        ob_start();
        try {
            $params = array('storage' => uniqid());
            $hooks = ['Ai1wm_Export_Init', 'Ai1wm_Export_Config', 'Ai1wm_Export_Enumerate', 'Ai1wm_Export_Content', 'Ai1wm_Export_Database', 'Ai1wm_Export_Compatibility', 'Ai1wm_Export_Archive', 'Ai1wm_Export_Download', 'Ai1wm_Export_Clean'];
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
// COLLECT METRICS
// =====================================================
$rootPath = ABSPATH;
$contentPath = WP_CONTENT_DIR;
$uploadsPath = wp_upload_dir()['basedir'];

$totalSize = dir_size($rootPath);
$inodes = count_inodes($rootPath);
$diskFree = @disk_free_space($rootPath);
$diskTotal = @disk_total_space($rootPath);

$mediaExts = ['aac', 'avi', 'mp3', 'mp4', 'mpeg', 'mpg', 'mov', 'wav', 'flac', 'wmv', 'webm', 'ogg', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff'];
$mediaSize = dir_size($uploadsPath, $mediaExts);

$archiveExts = ['zip', 'tar', 'gz', 'bz2', 'rar', '7z', 'iso', 'dmg', 'wpress', 'ncpress'];
$archiveSize = dir_size($contentPath, $archiveExts);

$dbExts = ['sql', 'sqlite', 'db', 'dump'];
$dbDumpSize = dir_size($rootPath, $dbExts);

$execExts = ['exe', 'bin', 'so', 'dll', 'app', 'apk'];
$execSize = dir_size($rootPath, $execExts);

$dbSizeBytes = db_size();

$LIMIT_10GB = 10 * 1024 * 1024 * 1024;

// =====================================================
// AUP CHECK
// =====================================================
$aupViolations = [];
if ($mediaSize > $LIMIT_10GB) $aupViolations[] = "Media (" . fmt_bytes($mediaSize) . ")";
if ($archiveSize > $LIMIT_10GB) $aupViolations[] = "Archives (" . fmt_bytes($archiveSize) . ")";
if ($dbDumpSize > $LIMIT_10GB) $aupViolations[] = "DB dumps (" . fmt_bytes($dbDumpSize) . ")";
if ($execSize > $LIMIT_10GB) $aupViolations[] = "Executables (" . fmt_bytes($execSize) . ")";

$aupStatus = empty($aupViolations) ? 'OK' : 'NOT OK (' . implode(', ', $aupViolations) . ')';

// =====================================================
// SUMMARY (clipboard text)
// =====================================================
$summary = "Evaluated by: \n";
$summary .= "Site: " . get_site_url() . "\n";
$summary .= "Disk Usage: " . fmt_bytes($totalSize) . "\n";
$summary .= "Inodes: " . number_format($inodes) . "\n";
$summary .= "PHP Version: " . PHP_VERSION . "\n";
$summary .= "WP Version: " . get_bloginfo('version') . "\n";
$summary .= "DB Size: " . fmt_bytes($dbSizeBytes) . "\n";
$summary .= "AUPs: " . $aupStatus . "\n";

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
<h3>📋 Summary <button class="btn btn-copy" onclick="copySummary()">📋 Copy to clipboard</button></h3>
<div class="summary-box" id="summary-text"><?= nl2br(htmlspecialchars($summary)) ?></div>
</div>

<div class="card">
<h3>📊 Site Health</h3>
<div class="grid">
    <div class="metric"><div class="l">Total WP install size</div><div class="v"><?= fmt_bytes($totalSize) ?></div></div>
    <div class="metric"><div class="l">Inodes (files)</div><div class="v"><?= number_format($inodes) ?></div></div>
    <div class="metric"><div class="l">Database size</div><div class="v"><?= fmt_bytes($dbSizeBytes) ?></div></div>
    <div class="metric"><div class="l">Disk free / total</div><div class="v" style="font-size:18px;"><?= $diskFree ? fmt_bytes($diskFree) : 'n/a' ?> / <?= $diskTotal ? fmt_bytes($diskTotal) : 'n/a' ?></div></div>
</div>
</div>

<div class="card">
<h3>⚠️ Hosting AUP Limits (10GB per category)</h3>
<?php
status_box('Media files', $mediaSize, $LIMIT_10GB, 'mp4, mp3, jpg, png, etc.');
status_box('Archives & disk images', $archiveSize, $LIMIT_10GB, 'zip, tar, iso, .ncpress, etc.');
status_box('Database dumps', $dbDumpSize, $LIMIT_10GB, '.sql files');
status_box('Executables', $execSize, $LIMIT_10GB, '.exe, .bin, .so, etc.');
?>
<p style="font-size:12px;color:#888;margin-top:15px;">Each category limited to 10GB on shared hosting. >70% warns, >90% critical.</p>
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
            <td><a href="<?= htmlspecialchars($b['url']) ?>" download class="btn">⬇</a></td>
        </tr>
    <?php endforeach; ?>
    </table>
<?php endif; ?>
</div>

<div class="card" style="border:2px solid #a1260d;">
<h3 style="color:#f48771;">⚠️ Self-Destruct</h3>
<p>Delete this runner file from server. <strong>Only deletes <?= htmlspecialchars(basename(__FILE__)) ?>, nothing else.</strong></p>
<a href="?destroy=confirm" class="btn btn-danger" onclick="return confirm('Really delete this file? This cannot be undone.');">💥 Delete <?= htmlspecialchars(basename(__FILE__)) ?></a>
</div>

<script>
function copySummary() {
    const text = <?= json_encode($summary) ?>;
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.background = '#0e7c4a';
        setTimeout(() => { btn.textContent = orig; btn.style.background = ''; }, 2000);
    }).catch(err => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Copied to clipboard');
    });
}
</script>

</body>
</html>
