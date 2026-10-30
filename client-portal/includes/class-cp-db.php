<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_DB {

	public static function clients_table() {
		global $wpdb;
		return $wpdb->prefix . CP_TABLE_CLIENTS;
	}

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . CP_TABLE_LINKS;
	}

	public static function stats_table() {
		global $wpdb;
		return $wpdb->prefix . CP_TABLE_STATS;
	}

	public static function get_client_by_user_id( $wp_user_id ) {
		global $wpdb;
		$table = self::clients_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $wp_user_id ) );
	}

	public static function get_client( $client_id ) {
		global $wpdb;
		$table = self::clients_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $client_id ) );
	}

	public static function get_all_clients() {
		global $wpdb;
		$table = self::clients_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	public static function insert_client( $wp_user_id, $display_name ) {
		global $wpdb;
		$table = self::clients_table();
		$wpdb->insert(
			$table,
			array(
				'wp_user_id'   => $wp_user_id,
				'display_name' => $display_name,
				'status'       => 'active',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function set_client_status( $client_id, $status ) {
		global $wpdb;
		$table = self::clients_table();
		return $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $client_id ), array( '%s' ), array( '%d' ) );
	}

	public static function get_links_for_client( $client_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %d", $client_id ) );
	}

	public static function get_all_links() {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results( "SELECT * FROM {$table}" );
	}

	public static function get_link( $link_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $link_id ) );
	}

	public static function add_link( $client_id, $campaign_id, $source_id, $label ) {
		global $wpdb;
		$table = self::links_table();
		$wpdb->insert(
			$table,
			array(
				'client_id'           => $client_id,
				'keitaro_campaign_id' => $campaign_id,
				'keitaro_source_id'   => $source_id,
				'label'               => $label,
				'created_at'          => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function delete_link( $link_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->delete( $table, array( 'id' => $link_id ), array( '%d' ) );
	}

	/**
	 * $metrics keys: clicks, registrations, ftd, deposits, revenue, roi.
	 */
	public static function upsert_stats( $client_id, $date, $metrics ) {
		global $wpdb;
		$table    = self::stats_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_id = %d AND stat_date = %s", $client_id, $date ) );

		$data = array(
			'client_id'     => $client_id,
			'stat_date'     => $date,
			'clicks'        => $metrics['clicks'],
			'registrations' => $metrics['registrations'],
			'ftd'           => $metrics['ftd'],
			'deposits'      => $metrics['deposits'],
			'revenue'       => $metrics['revenue'],
			'roi'           => $metrics['roi'],
			'synced_at'     => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%d', '%d', '%d', '%d', '%f', '%f', '%s' );

		if ( $existing ) {
			unset( $data['client_id'], $data['stat_date'] );
			array_splice( $formats, 0, 2 );
			$wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	public static function get_stats_range( $client_id, $date_from, $date_to ) {
		global $wpdb;
		$table = self::stats_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_id = %d AND stat_date BETWEEN %s AND %s ORDER BY stat_date ASC",
				$client_id,
				$date_from,
				$date_to
			)
		);
	}
}
