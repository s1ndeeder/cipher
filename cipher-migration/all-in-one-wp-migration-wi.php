<?php
/**
 * Plugin Name: Cipher Migration
 * Plugin URI: https://servmask.com/
 * Description: Migration tool for all your blog data. Import or Export your blog content with a single click.
 * Author: ServMask
 * Author URI: https://servmask.com/
 * Version: 6.77
 * Text Domain: all-in-one-wp-migration-wi
 * Domain Path: /languages
 * Network: True
 *
 * Copyright (C) 2014-2018 ServMask Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

// Auto-deactivate conflicting old plugin (function name collision)
if ( ! function_exists( "is_plugin_active" ) ) {
    require_once ABSPATH . "wp-admin/includes/plugin.php";
}
$conflict_plugin = "All-In-One-WP-Migration-With-Import-master/all-in-one-wp-migration-wi.php";
if ( is_plugin_active( $conflict_plugin ) ) {
    deactivate_plugins( $conflict_plugin, true );
    return; // exit here — re-activate cipher will succeed on next load
}
// Skip loading if conflicting functions already declared
if ( function_exists( "ai1wm_progress_path" ) ) {
    return;
}
// Check SSL Mode
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && ( $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) ) {
	$_SERVER['HTTPS'] = 'on';
}

// Plugin Basename
define( 'AI1WM_PLUGIN_BASENAME', basename( dirname( __FILE__ ) ) . '/' . basename( __FILE__ ) );

// Plugin Path
define( 'AI1WM_PATH', dirname( __FILE__ ) );

// Plugin Url
define( 'AI1WM_URL', plugins_url( '', AI1WM_PLUGIN_BASENAME ) );

// Plugin Storage Url
define( 'AI1WM_STORAGE_URL', plugins_url( 'storage', AI1WM_PLUGIN_BASENAME ) );

// Plugin Backups Url
define( 'AI1WM_BACKUPS_URL', content_url( 'ai1wm-backups', AI1WM_PLUGIN_BASENAME ) );

// Themes Absolute Path
define( 'AI1WM_THEMES_PATH', get_theme_root() );

// Include constants
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'constants.php';

// Include deprecated
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'deprecated.php';

// Include functions
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'functions.php';

// Include exceptions
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'exceptions.php';

// Include loader
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'loader.php';

// =========================================================================
// = All app initialization is done in Ai1wm_Main_Controller __constructor =
// =========================================================================
$main_controller = new Ai1wm_Main_Controller();
