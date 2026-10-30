<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Sync {

	const CRON_HOOK     = 'cp_sync_stats_event';
	const CRON_SCHEDULE = 'cp_sync_interval';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_sync' ) );
		add_action( 'admin_post_cp_manual_sync', array( $this, 'handle_manual_sync' ) );
	}

	public function register_schedule( $schedules ) {
		$minutes                           = max( 5, (int) get_option( 'cp_sync_interval_minutes', 15 ) );
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( 'Кожні %d хв (Client Portal sync)', $minutes ),
		);
		return $schedules;
	}

	public static function schedule_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Тягне свіжу статистику для кожної Client -> Keitaro Campaign/Source прив'язки
	 * і кладе в локальний кеш (wp_cp_stats). Дашборд клієнта читає тільки з кешу,
	 * тож падіння/недоступність Keitaro API не ламає кабінет клієнта.
	 *
	 * Клієнт може мати кілька кампаній у Keitaro — тому результати по всіх його
	 * лінках за один день підсумовуються в одну комірку, а не перезаписують одна
	 * одну (інакше при 2+ кампаніях на клієнта лишалися б дані тільки останньої).
	 */
	public function run_sync() {
		$links = CP_DB::get_all_links();
		if ( empty( $links ) ) {
			return;
		}

		$api = new CP_Keitaro_Api();
		if ( ! $api->is_configured() ) {
			return;
		}

		$lookback_days = max( 1, (int) get_option( 'cp_sync_lookback_days', 3 ) );
		$date_to       = current_time( 'Y-m-d' );
		$date_from     = gmdate( 'Y-m-d', strtotime( "-{$lookback_days} days", current_time( 'timestamp' ) ) );

		$links_by_client = array();
		foreach ( $links as $link ) {
			$links_by_client[ $link->client_id ][] = $link;
		}

		foreach ( $links_by_client as $client_id => $client_links ) {
			$daily = array();

			foreach ( $client_links as $link ) {
				$rows = $api->build_report(
					array(
						'date_from'   => $date_from,
						'date_to'     => $date_to,
						'campaign_id' => $link->keitaro_campaign_id,
						'source_id'   => $link->keitaro_source_id,
					)
				);

				if ( is_wp_error( $rows ) ) {
					error_log( sprintf( '[Client Portal] Keitaro sync error for client %d (link %d): %s', $client_id, $link->id, $rows->get_error_message() ) );
					continue;
				}

				foreach ( $rows as $row ) {
					if ( empty( $row['date'] ) ) {
						continue;
					}
					$date = $row['date'];

					if ( ! isset( $daily[ $date ] ) ) {
						$daily[ $date ] = array(
							'clicks'        => 0,
							'registrations' => 0,
							'ftd'           => 0,
							'deposits'      => 0,
							'revenue'       => 0.0,
							'roi_sum'       => 0.0,
							'roi_count'     => 0,
						);
					}

					$daily[ $date ]['clicks']        += $row['clicks'];
					$daily[ $date ]['registrations'] += $row['registrations'];
					$daily[ $date ]['ftd']           += $row['ftd'];
					$daily[ $date ]['deposits']       += $row['deposits'];
					$daily[ $date ]['revenue']        += $row['revenue'];
					$daily[ $date ]['roi_sum']        += $row['roi'];
					$daily[ $date ]['roi_count']++;
				}
			}

			foreach ( $daily as $date => $metrics ) {
				$metrics['roi'] = $metrics['roi_count'] ? round( $metrics['roi_sum'] / $metrics['roi_count'], 2 ) : 0;
				unset( $metrics['roi_sum'], $metrics['roi_count'] );
				CP_DB::upsert_stats( $client_id, $date, $metrics );
			}
		}

		update_option( 'cp_last_sync_at', current_time( 'mysql' ) );
	}

	public function handle_manual_sync() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_manual_sync' );
		$this->run_sync();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'cp-settings',
					'synced' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
