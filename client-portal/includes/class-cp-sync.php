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

		// schedule_event() лише перевіряє wp_next_scheduled() і виходить, якщо
		// подія вже є, тож цей виклик майже завжди нічого не робить — але
		// самовідновлюється, якщо подію колись втрачено (наприклад, плагін
		// оновили копіюванням файлів поверх живого сайту, без деактивації/
		// активації через wp-admin, через що register_activation_hook — а з
		// ним і початкове планування синку — просто ніколи не спрацював).
		self::schedule_event();
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
	 * і кладе в локальний кеш: по кожній кампанії окремо (wp_cp_link_stats —
	 * дозволяє клієнту фільтрувати дашборд по одній кампанії) і сумарно по
	 * клієнту (wp_cp_stats — для перемикача "Усі кампанії"). Дашборд клієнта
	 * на багатоденних періодах читає тільки з цього кешу, тож падіння/
	 * недоступність Keitaro API не ламає кабінет клієнта (для сьогоднішнього
	 * погодинного графіка REST ходить у Keitaro наживо — див. CP_Rest).
	 *
	 * Клієнт може мати кілька кампаній у Keitaro — тому для client-level кешу
	 * результати по всіх його лінках за один день підсумовуються в одну
	 * комірку, а не перезаписують одна одну (інакше при 2+ кампаніях на
	 * клієнта лишалися б дані тільки останньої).
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

		$this->sync_campaign_names( $api, $links );

		$lookback_days = max( 1, (int) get_option( 'cp_sync_lookback_days', 3 ) );
		$date_to       = current_time( 'Y-m-d' );
		$date_from     = gmdate( 'Y-m-d', strtotime( "-{$lookback_days} days", current_time( 'timestamp' ) ) );

		$links_by_client = array();
		foreach ( $links as $link ) {
			$links_by_client[ $link->client_id ][] = $link;
		}

		foreach ( $links_by_client as $client_id => $client_links ) {
			$daily_client = array();

			foreach ( $client_links as $link ) {
				$rows = $api->build_report(
					array(
						'date_from'   => $date_from,
						'date_to'     => $date_to,
						'campaign_id' => $link->keitaro_campaign_id,
						'stream_id'   => $link->keitaro_stream_id,
						'source_id'   => $link->keitaro_source_id,
						'grouping'    => 'day',
					)
				);

				if ( is_wp_error( $rows ) ) {
					error_log( sprintf( '[Client Portal] Keitaro sync error for client %d (link %d): %s', $client_id, $link->id, $rows->get_error_message() ) );
					continue;
				}

				foreach ( $rows as $row ) {
					if ( empty( $row['bucket'] ) ) {
						continue;
					}
					$date = $row['bucket'];

					CP_DB::upsert_link_stats(
						$link->id,
						$client_id,
						$date,
						array(
							'clicks'            => $row['clicks'],
							'unique_clicks'     => $row['unique_clicks'],
							'conversions'       => $row['conversions'],
							'deposits'          => $row['deposits'],
							'cost'              => $row['cost'],
							'confirmed_revenue' => $row['confirmed_revenue'],
							'roi_confirmed'     => $row['roi_confirmed'],
						)
					);

					if ( ! isset( $daily_client[ $date ] ) ) {
						$daily_client[ $date ] = array(
							'clicks'            => 0,
							'unique_clicks'     => 0,
							'conversions'       => 0,
							'deposits'          => 0,
							'cost'              => 0.0,
							'confirmed_revenue' => 0.0,
							'roi_sum'           => 0.0,
							'roi_count'         => 0,
						);
					}

					$daily_client[ $date ]['clicks']            += $row['clicks'];
					$daily_client[ $date ]['unique_clicks']     += $row['unique_clicks'];
					$daily_client[ $date ]['conversions']       += $row['conversions'];
					$daily_client[ $date ]['deposits']          += $row['deposits'];
					$daily_client[ $date ]['cost']              += $row['cost'];
					$daily_client[ $date ]['confirmed_revenue'] += $row['confirmed_revenue'];
					$daily_client[ $date ]['roi_sum']           += $row['roi_confirmed'];
					$daily_client[ $date ]['roi_count']++;
				}
			}

			foreach ( $daily_client as $date => $metrics ) {
				$metrics['roi_confirmed'] = $metrics['roi_count'] ? round( $metrics['roi_sum'] / $metrics['roi_count'], 2 ) : 0;
				unset( $metrics['roi_sum'], $metrics['roi_count'] );
				CP_DB::upsert_stats( $client_id, $date, $metrics );
			}
		}

		update_option( 'cp_last_sync_at', current_time( 'mysql' ) );
	}

	/**
	 * Підписує прив'язки реальними назвами кампаній з Keitaro (один запит
	 * get_campaigns() на весь прогін синку, не на кожну прив'язку).
	 */
	private function sync_campaign_names( CP_Keitaro_Api $api, $links ) {
		$needs_names = false;
		foreach ( $links as $link ) {
			if ( empty( $link->campaign_name ) && ! empty( $link->keitaro_campaign_id ) ) {
				$needs_names = true;
				break;
			}
		}
		if ( ! $needs_names ) {
			return;
		}

		$campaigns = $api->get_campaigns();
		if ( is_wp_error( $campaigns ) || ! is_array( $campaigns ) ) {
			return;
		}

		$names_by_id = array();
		foreach ( $campaigns as $campaign ) {
			if ( isset( $campaign['id'], $campaign['name'] ) ) {
				$names_by_id[ (string) $campaign['id'] ] = $campaign['name'];
			}
		}

		foreach ( $links as $link ) {
			if ( ! empty( $link->campaign_name ) || empty( $link->keitaro_campaign_id ) ) {
				continue;
			}
			if ( isset( $names_by_id[ (string) $link->keitaro_campaign_id ] ) ) {
				CP_DB::update_link_campaign_name( $link->id, $names_by_id[ (string) $link->keitaro_campaign_id ] );
			}
		}
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
