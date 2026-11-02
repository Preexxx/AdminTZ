<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Activator {

	public static function activate() {
		self::create_tables();
		CP_Roles::add_roles();
		CP_Sync::schedule_event();
		update_option( 'cp_db_version', CP_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		CP_Sync::unschedule_event();
		flush_rewrite_rules();
	}

	/**
	 * Застосовує міграції схеми на вже активному сайті (без повторної
	 * активації плагіна) — порівнює збережену версію БД із поточною і за
	 * потреби повторно проганяє create_tables(). dbDelta() ідемпотентний:
	 * для існуючих таблиць він лише додає відсутні колонки/ключі, нічого не
	 * видаляє і не чіпає наявні дані.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'cp_db_version' ) === CP_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'cp_db_version', CP_DB_VERSION );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$clients_table    = $wpdb->prefix . CP_TABLE_CLIENTS;
		$links_table      = $wpdb->prefix . CP_TABLE_LINKS;
		$stats_table      = $wpdb->prefix . CP_TABLE_STATS;
		$link_stats_table = $wpdb->prefix . CP_TABLE_LINK_STATS;

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
			campaign_name VARCHAR(191) NOT NULL DEFAULT '',
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
			unique_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cost DECIMAL(14,4) NOT NULL DEFAULT 0,
			confirmed_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
			roi_confirmed DECIMAL(10,2) NOT NULL DEFAULT 0,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_date (client_id, stat_date)
		) {$charset_collate};

		CREATE TABLE {$link_stats_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			client_id BIGINT UNSIGNED NOT NULL,
			stat_date DATE NOT NULL,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			unique_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			deposits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cost DECIMAL(14,4) NOT NULL DEFAULT 0,
			confirmed_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
			roi_confirmed DECIMAL(10,2) NOT NULL DEFAULT 0,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY link_date (link_id, stat_date),
			KEY client_id (client_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
