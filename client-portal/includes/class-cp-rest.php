<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Rest {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'cp/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'date_from'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'date_to'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'preset'      => array( 'sanitize_callback' => 'sanitize_key' ),
					'campaign_id' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			'cp/v1',
			'/campaigns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_campaigns' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission() {
		return is_user_logged_in() && current_user_can( CP_Roles::CAP_VIEW_DASHBOARD );
	}

	/**
	 * Список кампаній для випадаючого списку клієнта — згруповано по
	 * campaign_id, а не по окремих прив'язках: клієнту можна пошарити кілька
	 * потоків однієї гри, і в його кабінеті це має бути один пункт "гра", а
	 * не дублікат назви на кожен потік. client_id, як і в /stats, береться
	 * виключно з сесії.
	 */
	public function get_campaigns() {
		$client = CP_DB::get_client_by_user_id( get_current_user_id() );
		if ( ! $client ) {
			return new WP_Error( 'cp_no_client', 'Акаунт не прив’язаний до клієнта.', array( 'status' => 403 ) );
		}
		if ( 'active' !== $client->status ) {
			return new WP_Error( 'cp_client_blocked', 'Акаунт заблоковано.', array( 'status' => 403 ) );
		}

		$links     = CP_DB::get_links_for_client( $client->id );
		$campaigns = array();

		foreach ( $links as $link ) {
			$campaign_id = $link->keitaro_campaign_id;
			if ( empty( $campaign_id ) || isset( $campaigns[ $campaign_id ] ) ) {
				continue;
			}

			$name = $link->campaign_name;
			if ( empty( $name ) ) {
				$name = $link->label;
			}
			if ( empty( $name ) ) {
				$name = sprintf( 'Campaign #%s', $campaign_id );
			}

			$campaigns[ $campaign_id ] = array(
				'campaign_id' => $campaign_id,
				'name'        => $name,
			);
		}

		return rest_ensure_response( array_values( $campaigns ) );
	}

	/**
	 * client_id і перелік прив'язок, що відповідають кампанії, виводяться
	 * виключно з поточного залогіненого користувача — ніколи з параметрів
	 * запиту, щоб один клієнт не міг запросити дані іншого чи чужу кампанію.
	 */
	public function get_stats( WP_REST_Request $request ) {
		$client = CP_DB::get_client_by_user_id( get_current_user_id() );

		if ( ! $client ) {
			return new WP_Error( 'cp_no_client', 'Акаунт не прив’язаний до клієнта.', array( 'status' => 403 ) );
		}
		if ( 'active' !== $client->status ) {
			return new WP_Error( 'cp_client_blocked', 'Акаунт заблоковано.', array( 'status' => 403 ) );
		}

		$links       = null;
		$campaign_id = $request->get_param( 'campaign_id' );
		if ( $campaign_id ) {
			$links = CP_DB::get_links_for_client_campaign( $client->id, $campaign_id );
			if ( empty( $links ) ) {
				return new WP_Error( 'cp_invalid_campaign', 'Кампанію не знайдено.', array( 'status' => 403 ) );
			}
		}

		list( $date_from, $date_to ) = $this->resolve_date_range( $request );

		if ( ! $this->is_valid_date( $date_from ) || ! $this->is_valid_date( $date_to ) || $date_from > $date_to ) {
			return new WP_Error( 'cp_invalid_date', 'Невірний період.', array( 'status' => 400 ) );
		}

		$result = ( $date_from === $date_to )
			? $this->get_hourly_series( $client, $links, $date_from )
			: $this->get_daily_series( $client, $campaign_id, $date_from, $date_to );

		$result['date_from'] = $date_from;
		$result['date_to']   = $date_to;

		return rest_ensure_response( $result );
	}

	/**
	 * Іменовані пресети рахуються на сервері (у таймзоні сайту — так само, як
	 * і денний кеш), щоб "Сьогодні"/"Текущий місяць" тощо не плавали між
	 * часовими поясами клієнта й сервера. Без preset — явні date_from/date_to
	 * (кастомний інтервал дат), як і раніше.
	 */
	private function resolve_date_range( WP_REST_Request $request ) {
		$preset = $request->get_param( 'preset' );
		$now    = current_time( 'timestamp' );
		$today  = current_time( 'Y-m-d' );

		switch ( $preset ) {
			case 'today':
				return array( $today, $today );

			case 'yesterday':
				$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day', $now ) );
				return array( $yesterday, $yesterday );

			case 'this_week':
				$monday = gmdate( 'Y-m-d', strtotime( 'monday this week', $now ) );
				return array( $monday, $today );

			case 'last_7_days':
				return array( gmdate( 'Y-m-d', strtotime( '-6 days', $now ) ), $today );

			case 'this_month':
				return array( gmdate( 'Y-m-01', $now ), $today );

			case 'last_30_days':
				return array( gmdate( 'Y-m-d', strtotime( '-29 days', $now ) ), $today );

			case 'last_month':
				$first_of_this_month = strtotime( gmdate( 'Y-m-01', $now ) );
				$last_month_end      = gmdate( 'Y-m-d', strtotime( '-1 day', $first_of_this_month ) );
				$last_month_start    = gmdate( 'Y-m-01', strtotime( $last_month_end ) );
				return array( $last_month_start, $last_month_end );
		}

		$date_to   = $request->get_param( 'date_to' ) ?: $today;
		$date_from = $request->get_param( 'date_from' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days', $now ) );
		return array( $date_from, $date_to );
	}

	/**
	 * Один календарний день: йдемо в Keitaro наживо з погодинним
	 * групуванням (день — замалий проміжок, щоб тримати в кеші окремою
	 * гранулярністю, і клієнт і так очікує тут майже реальний час — поруч є
	 * кнопка "Оновити"). $links — null, якщо кампанію не вибрано (сумуємо по
	 * годинах усі прив'язки клієнта, так само як це робить нічний синк для
	 * денного кешу), або масив прив'язок ОДНІЄЇ кампанії (можливо кілька —
	 * клієнту може бути пошарено кілька потоків однієї гри, їх теж сумуємо).
	 * Якщо Keitaro недоступна — відкочуємось на денний кеш за цю саму дату,
	 * щоб кабінет не зламався (source: 'cache_fallback').
	 */
	private function get_hourly_series( $client, $links, $date ) {
		$api = new CP_Keitaro_Api();

		if ( $api->is_configured() ) {
			$source_links = null !== $links ? $links : CP_DB::get_links_for_client( $client->id );
			$queries      = array();
			foreach ( $source_links as $l ) {
				$queries[] = array( $l->keitaro_campaign_id, $l->keitaro_stream_id, $l->keitaro_source_id );
			}

			if ( ! empty( $queries ) ) {
				$hourly      = array();
				$totals      = $this->zero_metrics();
				$totals['roi_count'] = 0;
				$had_success = false;

				foreach ( $queries as $q ) {
					$rows = $api->build_report(
						array(
							'date_from'   => $date,
							'date_to'     => $date,
							'campaign_id' => $q[0],
							'stream_id'   => $q[1],
							'source_id'   => $q[2],
							'grouping'    => 'day_hour',
						)
					);

					if ( is_wp_error( $rows ) ) {
						continue;
					}
					$had_success = true;

					foreach ( $rows as $row ) {
						$hour = (int) substr( $row['bucket'], -2 );
						if ( ! isset( $hourly[ $hour ] ) ) {
							$hourly[ $hour ] = $this->zero_metrics();
							$hourly[ $hour ]['roi_count'] = 0;
						}
						// Накопичуємо і в розбивку по годинах (для графіка), і в
						// загальний підсумок дня — обидва рахунки беруть roi_count
						// з реальних рядків Keitaro, а не з 24 синтетичних слотів
						// графіка, інакше середній ROI розмивався б порожніми
						// годинами без трафіку.
						$hourly[ $hour ] = $this->accumulate( $hourly[ $hour ], $row );
						$totals          = $this->accumulate( $totals, $row );
					}
				}

				if ( $had_success ) {
					$series = array();
					for ( $h = 0; $h <= 23; $h++ ) {
						$metrics  = isset( $hourly[ $h ] ) ? $hourly[ $h ] : $this->zero_metrics();
						$series[] = array_merge(
							array( 'bucket' => sprintf( '%02d:00', $h ) ),
							$this->finalize( $metrics )
						);
					}

					return array(
						'granularity' => 'hour',
						'source'      => 'live',
						'totals'      => $this->finalize( $totals ),
						'series'      => $series,
					);
				}
			}
		}

		$fallback_campaign_id    = ! empty( $links ) ? $links[0]->keitaro_campaign_id : null;
		$fallback                = $this->get_daily_series( $client, $fallback_campaign_id, $date, $date );
		$fallback['granularity'] = 'hour';
		$fallback['source']      = 'cache_fallback';
		return $fallback;
	}

	/**
	 * Два і більше дні: читаємо тільки з локального кешу — підсумок по
	 * кампанії (усі пошарені клієнту потоки цієї гри разом), якщо кампанію
	 * обрано, інакше client-level підсумок по всіх кампаніях. Keitaro тут
	 * ніколи не викликається напряму.
	 */
	private function get_daily_series( $client, $campaign_id, $date_from, $date_to ) {
		$rows = $campaign_id
			? CP_DB::get_campaign_stats_range( $client->id, $campaign_id, $date_from, $date_to )
			: CP_DB::get_stats_range( $client->id, $date_from, $date_to );

		$totals = $this->zero_metrics();
		$totals['roi_count'] = 0;
		$series = array();

		foreach ( $rows as $row ) {
			$metrics = array(
				'clicks'            => (int) $row->clicks,
				'unique_clicks'     => (int) $row->unique_clicks,
				'conversions'       => (int) $row->conversions,
				'deposits'          => (int) $row->deposits,
				'cost'              => (float) $row->cost,
				'confirmed_revenue' => (float) $row->confirmed_revenue,
				'roi_confirmed'     => (float) $row->roi_confirmed,
			);
			$series[] = array_merge( array( 'bucket' => $row->stat_date ), $metrics );
			$totals   = $this->accumulate( $totals, $metrics );
		}

		return array(
			'granularity' => 'day',
			'source'      => 'cache',
			'totals'      => $this->finalize( $totals ),
			'series'      => $series,
		);
	}

	private function zero_metrics() {
		return array(
			'clicks'            => 0,
			'unique_clicks'     => 0,
			'conversions'       => 0,
			'deposits'          => 0,
			'cost'              => 0.0,
			'confirmed_revenue' => 0.0,
			'roi_confirmed'     => 0.0,
		);
	}

	/**
	 * Додає $row до накопичувача $acc: лічильники й суми складаються,
	 * roi_confirmed — усереднюється по кількості доданих рядків (так само,
	 * як і попередня логіка ROI в цьому плагіні).
	 */
	private function accumulate( $acc, $row ) {
		$acc['clicks']            += $row['clicks'];
		$acc['unique_clicks']     += $row['unique_clicks'];
		$acc['conversions']       += $row['conversions'];
		$acc['deposits']          += $row['deposits'];
		$acc['cost']              += $row['cost'];
		$acc['confirmed_revenue'] += $row['confirmed_revenue'];
		$acc['roi_confirmed']     += $row['roi_confirmed'];
		$acc['roi_count']          = ( isset( $acc['roi_count'] ) ? $acc['roi_count'] : 0 ) + 1;
		return $acc;
	}

	private function finalize( $acc ) {
		$roi_count = isset( $acc['roi_count'] ) ? $acc['roi_count'] : 0;
		return array(
			'clicks'            => (int) $acc['clicks'],
			'unique_clicks'     => (int) $acc['unique_clicks'],
			'conversions'       => (int) $acc['conversions'],
			'deposits'          => (int) $acc['deposits'],
			'cost'              => round( $acc['cost'], 4 ),
			'confirmed_revenue' => round( $acc['confirmed_revenue'], 2 ),
			'roi_confirmed'     => $roi_count ? round( $acc['roi_confirmed'] / $roi_count, 2 ) : 0,
		);
	}

	private function is_valid_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
