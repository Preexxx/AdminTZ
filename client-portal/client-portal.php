<?php
/**
 * Plugin Name: Admintz Client Portal
 * Description: Адмінка + клієнтський кабінет зі статистикою з Keitaro (MVP).
 * Version: 0.1.0
 * Author: Admintz
 * Text Domain: admintz-client-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_VERSION', '0.2.0' );
// Версія схеми БД — окремо від CP_VERSION, бо міграції мають застосуватись і
// без повторної активації плагіна (сайт уже live). Див. CP_Activator::maybe_upgrade().
define( 'CP_DB_VERSION', '3' );
define( 'CP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CP_TABLE_CLIENTS', 'cp_clients' );
define( 'CP_TABLE_LINKS', 'cp_links' );
define( 'CP_TABLE_STATS', 'cp_stats' );
define( 'CP_TABLE_LINK_STATS', 'cp_link_stats' );

require_once CP_PLUGIN_DIR . 'includes/class-cp-db.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-roles.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-activator.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-keitaro-api.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-sync.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-admin.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-dashboard.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-rest.php';

register_activation_hook( __FILE__, array( 'CP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CP_Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		CP_Activator::maybe_upgrade();
		CP_Admin::instance();
		CP_Dashboard::instance();
		CP_Rest::instance();
		CP_Sync::instance();
	}
);
