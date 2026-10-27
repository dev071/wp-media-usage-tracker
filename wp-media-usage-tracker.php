<?php
/**
 * Plugin Name: WP Media Usage Tracker
 * Description: Scans all media items across Posts, Pages, and CPTs (Published, Draft, Trashed & more) to track usage counts, locations, and find unused files.
 * Version: 1.0.2
 * Author: dev071
 * Author URI: https://github.com/dev071
 * Plugin URI: https://github.com/dev071/wp-media-usage-tracker
 * Text Domain: wp-media-usage-tracker
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMUT_VERSION', '1.0.2' );
define( 'WMUT_FILE', __FILE__ );
define( 'WMUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMUT_URL', plugin_dir_url( __FILE__ ) );

require_once WMUT_DIR . 'includes/class-wmut-scanner.php';
require_once WMUT_DIR . 'includes/class-wmut-admin.php';

register_activation_hook( __FILE__, array( 'WMUT_Scanner', 'clear_cache' ) );
register_deactivation_hook( __FILE__, array( 'WMUT_Scanner', 'clear_cache' ) );

// Auto-invalidate scan cache when posts or media change
add_action( 'save_post', array( 'WMUT_Scanner', 'maybe_clear_cache' ), 10, 3 );
add_action( 'deleted_post', array( 'WMUT_Scanner', 'clear_cache' ) );
add_action( 'trashed_post', array( 'WMUT_Scanner', 'clear_cache' ) );
add_action( 'untrashed_post', array( 'WMUT_Scanner', 'clear_cache' ) );
add_action( 'add_attachment', array( 'WMUT_Scanner', 'clear_cache' ) );
add_action( 'edit_attachment', array( 'WMUT_Scanner', 'clear_cache' ) );
add_action( 'delete_attachment', array( 'WMUT_Scanner', 'clear_cache' ) );

add_action( 'plugins_loaded', array( 'WMUT_Admin', 'init' ) );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( 'WMUT_Admin', 'action_links' ) );
