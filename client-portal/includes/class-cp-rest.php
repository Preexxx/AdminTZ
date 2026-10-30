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
					'date_from' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'date_to'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public function check_permission() {
		return is_user_logged_in() && current_user_can( CP_Roles::CAP_VIEW_DASHBOARD );
	}

	/**
	 * client_id виводиться виключно з поточного залогіненого користувача —
	 * ніколи з параметрів запиту, щоб один клієнт не міг запросити дані іншого.
	 */
	public function get_stats( WP_REST_Request $request ) {
		$client = CP_DB::get_client_by_user_id( get_current_user_id() );

		if ( ! $client ) {
			return new WP_Error( 'cp_no_client', 'Акаунт не прив’язаний до клієнта.', array( 'status' => 403 ) );
		}
		if ( 'active' !== $client->status ) {
			return new WP_Error( 'cp_client_blocked', 'Акаунт заблоковано.', array( 'status' => 403 ) );
		}

		$date_to   = $request->get_param( 'date_to' ) ?: current_time( 'Y-m-d' );
		$date_from = $request->get_param( 'date_from' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );

		if ( ! $this->is_valid_date( $date_from ) || ! $this->is_valid_date( $date_to ) ) {
			return new WP_Error( 'cp_invalid_date', 'Невірний формат дати, очікується YYYY-MM-DD.', array( 'status' => 400 ) );
		}

		$rows = CP_DB::get_stats_range( $client->id, $date_from, $date_to );

		$totals = array(
			'clicks'        => 0,
			'registrations' => 0,
			'ftd'           => 0,
			'deposits'      => 0,
			'revenue'       => 0.0,
		);
		$roi_sum = 0.0;

		foreach ( $rows as $row ) {
			$totals['clicks']        += (int) $row->clicks;
			$totals['registrations'] += (int) $row->registrations;
			$totals['ftd']           += (int) $row->ftd;
			$totals['deposits']      += (int) $row->deposits;
			$totals['revenue']       += (float) $row->revenue;
			$roi_sum                 += (float) $row->roi;
		}

		$totals['roi'] = count( $rows ) ? round( $roi_sum / count( $rows ), 2 ) : 0;

		return rest_ensure_response(
			array(
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'totals'    => $totals,
				'daily'     => array_map(
					function ( $row ) {
						return array(
							'date'          => $row->stat_date,
							'clicks'        => (int) $row->clicks,
							'registrations' => (int) $row->registrations,
							'ftd'           => (int) $row->ftd,
							'deposits'      => (int) $row->deposits,
							'revenue'       => (float) $row->revenue,
							'roi'           => (float) $row->roi,
						);
					},
					$rows
				),
			)
		);
	}

	private function is_valid_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
