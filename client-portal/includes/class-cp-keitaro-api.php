<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Обгортка над Keitaro Admin API.
 *
 * Шляхи та назви полів нижче звірені напряму з реальним інстансом клієнта
 * (`/admin_api/v1/campaigns`, `/admin_api/v1/report/build`, Api-Key header) —
 * підтверджено робочими тестовими запитами 2026-10-29 і 2026-11-02.
 *
 * Повний список валідних колонок/метрик звіту "events" (взято напряму з
 * помилки валідації API, 2026-11-02): ... clicks, visitors,
 * campaign_unique_clicks, stream_unique_clicks, global_unique_clicks, ...
 * conversions, ... revenue, ... profit, ... roi, ... deposits, regs,
 * reg_revenue, deposit_revenue, ... cost, ... confirmed_count,
 * confirmed_revenue, ... roi_confirmed, ... hour, day_hour, day ... (та інші).
 *
 * Метрики нижче (METRICS) звірені живим запитом на кампанії campaign_id=16 і
 * дають саме ті цифри, що показує власний дашборд Keitaro клієнта (картки
 * "Клики / Уник. (кампанія) / Конв. / Депозити / Расход / Доход (подтв.) /
 * ROI (подтв.)"). `day_hour` (формат відповіді: рядок "YYYYMMDDHH") теж
 * підтверджено робочим запитом — дає погодинну розбивку всередині одного дня.
 */
class CP_Keitaro_Api {

	const ENDPOINT_CAMPAIGNS = '/admin_api/v1/campaigns';
	const ENDPOINT_SOURCES   = '/admin_api/v1/traffic_sources';
	const ENDPOINT_REPORT    = '/admin_api/v1/report/build';

	const METRICS = array(
		'clicks',
		'campaign_unique_clicks',
		'conversions',
		'deposits',
		'cost',
		'confirmed_revenue',
		'roi_confirmed',
	);

	private $base_url;
	private $api_key;

	public function __construct() {
		$this->base_url = rtrim( get_option( 'cp_keitaro_url', '' ), '/' );
		$this->api_key  = get_option( 'cp_keitaro_api_key', '' );
	}

	public function is_configured() {
		return ! empty( $this->base_url ) && ! empty( $this->api_key );
	}

	public function test_connection() {
		return $this->request( 'GET', self::ENDPOINT_CAMPAIGNS );
	}

	/**
	 * Повертає сирий список кампаній Keitaro (з реальними назвами на кшталт
	 * "[G] Stack Block Builder (Tower)") або WP_Error. Використовується, щоб
	 * підписати прив'язки клієнта людяними назвами замість голих ID.
	 */
	public function get_campaigns() {
		return $this->request( 'GET', self::ENDPOINT_CAMPAIGNS );
	}

	public function get_sources() {
		return $this->request( 'GET', self::ENDPOINT_SOURCES );
	}

	/**
	 * $params: date_from, date_to (Y-m-d), campaign_id, source_id,
	 * grouping ('day' за замовчуванням, або 'day_hour' для погодинної
	 * розбивки — має сенс лише в межах одного календарного дня).
	 * Повертає масив рядків звіту (по днях або по годинах) або WP_Error.
	 */
	public function build_report( $params ) {
		$grouping = ( isset( $params['grouping'] ) && 'day_hour' === $params['grouping'] ) ? 'day_hour' : 'day';

		$body = array(
			'range'    => array(
				'from'     => $params['date_from'] . ' 00:00:00',
				'to'       => $params['date_to'] . ' 23:59:59',
				'timezone' => wp_timezone_string(),
			),
			'columns'  => array( 'campaign_id', 'ts_id' ),
			'metrics'  => self::METRICS,
			'grouping' => array( $grouping ),
			'filters'  => array(),
		);

		if ( ! empty( $params['campaign_id'] ) ) {
			$body['filters'][] = array(
				'name'       => 'campaign_id',
				'operator'   => 'EQUALS',
				'expression' => $params['campaign_id'],
			);
		}

		if ( ! empty( $params['source_id'] ) ) {
			$body['filters'][] = array(
				'name'       => 'ts_id',
				'operator'   => 'EQUALS',
				'expression' => $params['source_id'],
			);
		}

		$response = $this->request( 'POST', self::ENDPOINT_REPORT, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->map_report_rows( $response, $grouping );
	}

	private function map_report_rows( $raw, $grouping = 'day' ) {
		$rows   = isset( $raw['rows'] ) ? $raw['rows'] : array();
		$mapped = array();

		foreach ( $rows as $row ) {
			$bucket = ( 'day_hour' === $grouping )
				? ( isset( $row['day_hour'] ) ? $row['day_hour'] : null )
				: ( isset( $row['day'] ) ? $row['day'] : null );

			if ( ! $bucket ) {
				continue;
			}

			$mapped[] = array(
				'bucket'            => $bucket,
				'clicks'            => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'unique_clicks'     => isset( $row['campaign_unique_clicks'] ) ? (int) $row['campaign_unique_clicks'] : 0,
				'conversions'       => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
				'deposits'          => isset( $row['deposits'] ) ? (int) $row['deposits'] : 0,
				'cost'              => isset( $row['cost'] ) ? (float) $row['cost'] : 0.0,
				'confirmed_revenue' => isset( $row['confirmed_revenue'] ) ? (float) $row['confirmed_revenue'] : 0.0,
				'roi_confirmed'     => isset( $row['roi_confirmed'] ) ? (float) $row['roi_confirmed'] : 0.0,
			);
		}

		return $mapped;
	}

	private function request( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'cp_keitaro_not_configured', 'Keitaro API URL/ключ не налаштовані.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Api-Key'      => $this->api_key,
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = $this->base_url . $path;
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'cp_keitaro_api_error', sprintf( 'Keitaro API повернув помилку %d: %s', $code, $raw_body ) );
		}

		return $decoded;
	}
}
