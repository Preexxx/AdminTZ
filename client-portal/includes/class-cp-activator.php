<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Activator {

	public static function activate() {
		self::create_tables();
		CP_Roles::add_roles();
		CP_Sync::schedule_event();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		CP_Sync::unschedule_event();
		flush_rewrite_rules();
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$clients_table = $wpdb->prefix . CP_TABLE_CLIENTS;
		$links_table   = $wpdb->prefix . CP_TABLE_LINKS;
		$stats_table   = $wpdb->prefix . CP_TABLE_STATS;

		$sql = "CREATE TABLE {$clients_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user_id (wp_user_id)
		) {$charset_collate};

		CREATE TABLE {$links_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id BIGINT UNSIGNED NOT NULL,
			keitaro_campaign_id VARCHAR(64) NOT NULL DEFAULT '',
			keitaro_source_id VARCHAR(64) NOT NULL DEFAULT '',
			label VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) {$charset_collate};

		CREATE TABLE {$stats_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id BIGINT UNSIGNED NOT NULL,
			stat_date DATE NOT NULL,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			registrations BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ftd BIGINT UNSIGNED NOT NULL DEFAULT 0,
			deposits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
			roi DECIMAL(10,2) NOT NULL DEFAULT 0,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_date (client_id, stat_date)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
