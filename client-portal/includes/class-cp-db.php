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

	public static function link_stats_table() {
		global $wpdb;
		return $wpdb->prefix . CP_TABLE_LINK_STATS;
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

	public static function add_link( $client_id, $campaign_id, $stream_id, $source_id, $label ) {
		global $wpdb;
		$table = self::links_table();
		$wpdb->insert(
			$table,
			array(
				'client_id'           => $client_id,
				'keitaro_campaign_id' => $campaign_id,
				'keitaro_stream_id'   => $stream_id,
				'keitaro_source_id'   => $source_id,
				'label'               => $label,
				'created_at'          => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function delete_link( $link_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->delete( $table, array( 'id' => $link_id ), array( '%d' ) );
	}

	/**
	 * Підписує прив'язку реальною назвою кампанії з Keitaro (напр.
	 * "[G] Stack Block Builder (Tower)"), щоб клієнт бачив у фільтрі
	 * впізнавану назву, а не голий Campaign ID.
	 */
	public static function update_link_campaign_name( $link_id, $campaign_name ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->update( $table, array( 'campaign_name' => $campaign_name ), array( 'id' => $link_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * $metrics keys: clicks, unique_clicks, conversions, deposits, cost,
	 * confirmed_revenue, roi_confirmed. Це сумарний кеш по клієнту (усі його
	 * кампанії разом) — читається для фільтра "Усі кампанії" на багатоденних
	 * періодах.
	 */
	public static function upsert_stats( $client_id, $date, $metrics ) {
		global $wpdb;
		$table    = self::stats_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_id = %d AND stat_date = %s", $client_id, $date ) );

		$data = array(
			'client_id'         => $client_id,
			'stat_date'         => $date,
			'clicks'            => $metrics['clicks'],
			'unique_clicks'     => $metrics['unique_clicks'],
			'conversions'       => $metrics['conversions'],
			'deposits'          => $metrics['deposits'],
			'cost'              => $metrics['cost'],
			'confirmed_revenue' => $metrics['confirmed_revenue'],
			'roi_confirmed'     => $metrics['roi_confirmed'],
			'synced_at'         => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s' );

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

	/**
	 * Те саме, що upsert_stats(), але per-link кеш — одна кампанія/прив'язка
	 * окремо, день за днем. Дозволяє клієнту фільтрувати дашборд по одній
	 * конкретній кампанії на будь-якому періоді, не тільки "сьогодні".
	 */
	public static function upsert_link_stats( $link_id, $client_id, $date, $metrics ) {
		global $wpdb;
		$table    = self::link_stats_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE link_id = %d AND stat_date = %s", $link_id, $date ) );

		$data = array(
			'link_id'           => $link_id,
			'client_id'         => $client_id,
			'stat_date'         => $date,
			'clicks'            => $metrics['clicks'],
			'unique_clicks'     => $metrics['unique_clicks'],
			'conversions'       => $metrics['conversions'],
			'deposits'          => $metrics['deposits'],
			'cost'              => $metrics['cost'],
			'confirmed_revenue' => $metrics['confirmed_revenue'],
			'roi_confirmed'     => $metrics['roi_confirmed'],
			'synced_at'         => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s' );

		if ( $existing ) {
			unset( $data['link_id'], $data['client_id'], $data['stat_date'] );
			array_splice( $formats, 0, 3 );
			$wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	public static function get_link_stats_range( $link_id, $date_from, $date_to ) {
		global $wpdb;
		$table = self::link_stats_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE link_id = %d AND stat_date BETWEEN %s AND %s ORDER BY stat_date ASC",
				$link_id,
				$date_from,
				$date_to
			)
		);
	}

	/**
	 * Усі прив'язки клієнта до однієї кампанії — клієнту можна пошарити
	 * кілька потоків однієї гри, тож одна "кампанія" в його кабінеті
	 * фактично може відповідати кільком рядкам wp_cp_links.
	 */
	public static function get_links_for_client_campaign( $client_id, $campaign_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_id = %d AND keitaro_campaign_id = %s",
				$client_id,
				$campaign_id
			)
		);
	}

	/**
	 * Те саме, що get_link_stats_range(), але підсумовано по всіх потоках,
	 * які пошарені цьому клієнту в межах однієї кампанії (JOIN на wp_cp_links,
	 * щоб не ходити по кожному link_id окремим запитом).
	 */
	public static function get_campaign_stats_range( $client_id, $campaign_id, $date_from, $date_to ) {
		global $wpdb;
		$links_table = self::links_table();
		$stats_table = self::link_stats_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ls.stat_date,
					SUM(ls.clicks) AS clicks,
					SUM(ls.unique_clicks) AS unique_clicks,
					SUM(ls.conversions) AS conversions,
					SUM(ls.deposits) AS deposits,
					SUM(ls.cost) AS cost,
					SUM(ls.confirmed_revenue) AS confirmed_revenue,
					AVG(ls.roi_confirmed) AS roi_confirmed
				FROM {$stats_table} ls
				JOIN {$links_table} l ON l.id = ls.link_id
				WHERE l.client_id = %d AND l.keitaro_campaign_id = %s AND ls.stat_date BETWEEN %s AND %s
				GROUP BY ls.stat_date
				ORDER BY ls.stat_date ASC",
				$client_id,
				$campaign_id,
				$date_from,
				$date_to
			)
		);
	}
}
