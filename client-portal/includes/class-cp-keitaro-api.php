<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Обгортка над Keitaro Admin API.
 *
 * Шляхи та назви полів нижче звірені напряму з реальним інстансом клієнта
 * (`/admin_api/v1/campaigns`, `/admin_api/v1/report/build`, Api-Key header) —
 * підтверджено робочим тестовим запитом 2026-10-29. Повний список валідних
 * колонок/метрик звіту "events" на цьому інстансі: clicks, regs, leads, sales,
 * deposits, revenue, sale_revenue, lead_revenue, deposit_revenue, reg_revenue,
 * roi, profit (та інші — див. помилку API при невірній назві колонки, вона
 * повертає повний список).
 *
 * НЕ ПІДТВЕРДЖЕНО: окремого поля для FTD (перший депозит окремо від повторних)
 * у списку немає — є лише загальний `deposits`. На тестовому акаунті конверсій
 * ще не було, тож fallback 'ftd' => 'deposits' нижче не перевірений на реальних
 * цифрах і потребує підтвердження клієнта, як саме рахувати FTD окремо від
 * повторних депозитів (можливо через окремий статус постбеку).
 */
class CP_Keitaro_Api {

	const ENDPOINT_CAMPAIGNS = '/admin_api/v1/campaigns';
	const ENDPOINT_SOURCES   = '/admin_api/v1/traffic_sources';
	const ENDPOINT_REPORT    = '/admin_api/v1/report/build';

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

	public function get_campaigns() {
		return $this->request( 'GET', self::ENDPOINT_CAMPAIGNS );
	}

	public function get_sources() {
		return $this->request( 'GET', self::ENDPOINT_SOURCES );
	}

	/**
	 * $params: date_from, date_to (Y-m-d), campaign_id, source_id.
	 * Повертає масив рядків звіту по днях або WP_Error.
	 */
	public function build_report( $params ) {
		$body = array(
			'range'    => array(
				'from'     => $params['date_from'] . ' 00:00:00',
				'to'       => $params['date_to'] . ' 23:59:59',
				'timezone' => wp_timezone_string(),
			),
			'columns'  => array( 'campaign_id', 'ts_id' ),
			'metrics'  => array( 'clicks', 'regs', 'deposits', 'revenue', 'roi' ),
			'grouping' => array( 'day' ),
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

		return $this->map_report_rows( $response );
	}

	private function map_report_rows( $raw ) {
		$rows   = isset( $raw['rows'] ) ? $raw['rows'] : array();
		$mapped = array();

		foreach ( $rows as $row ) {
			$mapped[] = array(
				'date'          => isset( $row['day'] ) ? $row['day'] : null,
				'clicks'        => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'registrations' => isset( $row['regs'] ) ? (int) $row['regs'] : 0,
				// FTD не підтверджено окремим полем — тимчасово дорівнює deposits,
				// див. коментар класу вище. Виправити, коли клієнт підтвердить
				// логіку розрізнення FTD від повторних депозитів.
				'ftd'           => isset( $row['deposits'] ) ? (int) $row['deposits'] : 0,
				'deposits'      => isset( $row['deposits'] ) ? (int) $row['deposits'] : 0,
				'revenue'       => isset( $row['revenue'] ) ? (float) $row['revenue'] : 0,
				'roi'           => isset( $row['roi'] ) ? (float) $row['roi'] : 0,
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
