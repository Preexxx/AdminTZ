<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Dashboard {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'cp_dashboard', array( $this, 'render_shortcode' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'restrict_wp_admin' ) );
		add_filter( 'allow_password_reset', array( $this, 'disable_self_service_reset' ), 10, 2 );
	}

	public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}
		if ( in_array( CP_Roles::ROLE_CLIENT, (array) $user->roles, true ) ) {
			return $this->dashboard_url();
		}
		return $redirect_to;
	}

	/**
	 * Клієнти логінів/паролів не відновлюють самостійно через email (рішення
	 * клієнта: без email-розсилок, логін/пароль видає і зберігає тільки адмін).
	 */
	public function disable_self_service_reset( $allow, $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && in_array( CP_Roles::ROLE_CLIENT, (array) $user->roles, true ) ) {
			return new WP_Error( 'cp_no_self_reset', 'Відновлення паролю вимкнено. Зверніться до адміністратора.' );
		}
		return $allow;
	}

	/**
	 * Клієнти не повинні бачити wp-admin — весь їхній кабінет живе на фронтенді
	 * через шорткод [cp_dashboard].
	 */
	public function restrict_wp_admin() {
		if ( wp_doing_ajax() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() && in_array( CP_Roles::ROLE_CLIENT, (array) $user->roles, true ) ) {
			wp_safe_redirect( $this->dashboard_url() );
			exit;
		}
	}

	private function dashboard_url() {
		$page_id = (int) get_option( 'cp_dashboard_page_id', 0 );
		if ( $page_id ) {
			return get_permalink( $page_id );
		}
		return home_url( '/' );
	}

	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Будь ласка, <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">увійдіть</a>, щоб побачити кабінет.</p>';
		}

		$client = CP_DB::get_client_by_user_id( get_current_user_id() );
		if ( ! $client ) {
			return '<p>Цей акаунт не прив’язаний до клієнта.</p>';
		}
		if ( 'active' !== $client->status ) {
			return '<p>Ваш акаунт заблоковано. Зверніться до адміністратора.</p>';
		}

		wp_enqueue_style( 'cp-dashboard', CP_PLUGIN_URL . 'assets/css/dashboard.css', array(), CP_VERSION );
		wp_enqueue_script( 'cp-chartjs', CP_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js', array(), CP_VERSION, true );
		wp_enqueue_script( 'cp-dashboard', CP_PLUGIN_URL . 'assets/js/dashboard.js', array( 'cp-chartjs' ), CP_VERSION, true );
		wp_localize_script(
			'cp-dashboard',
			'cpDashboardConfig',
			array(
				'statsUrl'     => esc_url_raw( rest_url( 'cp/v1/stats' ) ),
				'campaignsUrl' => esc_url_raw( rest_url( 'cp/v1/campaigns' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);

		ob_start();
		?>
		<div id="cp-dashboard-root" class="cp-dashboard alignwide">
			<div class="cp-dashboard__topbar">
				<h2 class="cp-dashboard__title">Дашборд</h2>
				<div class="cp-dashboard__controls">
					<select id="cp-campaign-select" class="cp-select"><option value="">Усі кампанії</option></select>
					<select id="cp-range-select" class="cp-select">
						<option value="today">Сьогодні</option>
						<option value="yesterday">Вчора</option>
						<option value="this_week">Поточний тиждень</option>
						<option value="last_7_days" selected>Останні 7 днів</option>
						<option value="this_month">Поточний місяць</option>
						<option value="last_30_days">Останні 30 днів</option>
						<option value="last_month">Попередній місяць</option>
						<option value="custom">Інтервал дат</option>
					</select>
					<span id="cp-custom-range" class="cp-custom-range" hidden>
						<input type="date" id="cp-date-from" />
						<input type="date" id="cp-date-to" />
					</span>
					<button type="button" id="cp-refresh" class="cp-icon-btn" title="Оновити" aria-label="Оновити">&#8635;</button>
					<button type="button" id="cp-settings-toggle" class="cp-icon-btn" title="Налаштування" aria-label="Налаштування">&#9881;</button>
				</div>
			</div>

			<div class="cp-dashboard__settings" id="cp-settings-panel" hidden>
				<label><input type="checkbox" id="cp-cost-toggle" /> Внести власні витрати (якщо Keitaro ще не рахує вартість трафіку)</label>
				<span id="cp-cost-fields" hidden>
					<input type="number" step="0.01" min="0" id="cp-cost-input" placeholder="Сума витрат, $" />
					<button type="button" id="cp-cost-apply" class="button button-small">Застосувати</button>
				</span>
			</div>

			<p id="cp-dashboard-note" class="cp-dashboard__note" hidden></p>

			<div class="cp-dashboard__cards" id="cp-dashboard-cards">
				<p class="cp-dashboard__loading">Завантаження...</p>
			</div>

			<div class="cp-dashboard__chart-wrap">
				<canvas id="cp-dashboard-chart" height="110"></canvas>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
