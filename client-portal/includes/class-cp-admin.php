<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cp_save_client', array( $this, 'handle_save_client' ) );
		add_action( 'admin_post_cp_toggle_client_status', array( $this, 'handle_toggle_status' ) );
		add_action( 'admin_post_cp_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cp_test_keitaro', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_cp_add_link', array( $this, 'handle_add_link' ) );
		add_action( 'admin_post_cp_delete_link', array( $this, 'handle_delete_link' ) );
		add_action( 'admin_post_cp_reset_password', array( $this, 'handle_reset_password' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page( 'Клієнтський портал', 'Клієнтський портал', CP_Roles::CAP_MANAGE_CLIENTS, 'cp-clients', array( $this, 'render_clients_page' ), 'dashicons-groups' );
		add_submenu_page( 'cp-clients', 'Клієнти', 'Клієнти', CP_Roles::CAP_MANAGE_CLIENTS, 'cp-clients', array( $this, 'render_clients_page' ) );
		add_submenu_page( 'cp-clients', 'Додати клієнта', 'Додати клієнта', CP_Roles::CAP_MANAGE_CLIENTS, 'cp-client-edit', array( $this, 'render_client_edit_page' ) );
		add_submenu_page( 'cp-clients', 'Налаштування', 'Налаштування', CP_Roles::CAP_MANAGE_CLIENTS, 'cp-settings', array( $this, 'render_settings_page' ) );
		// Сторінка керування прив'язками клієнта доступна тільки через посилання
		// з таблиці клієнтів, тож прибираємо її з видимого меню.
		add_submenu_page( 'cp-clients', 'Прив’язки', 'Прив’язки', CP_Roles::CAP_MANAGE_CLIENTS, 'cp-client-links', array( $this, 'render_client_links_page' ) );
		remove_submenu_page( 'cp-clients', 'cp-client-links' );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'cp-' ) ) {
			return;
		}
		wp_enqueue_style( 'cp-admin', CP_PLUGIN_URL . 'assets/css/admin.css', array(), CP_VERSION );
	}

	public function render_clients_page() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}

		require_once CP_PLUGIN_DIR . 'includes/class-cp-clients-list-table.php';
		$list_table = new CP_Clients_List_Table();
		$list_table->prepare_items();

		echo '<div class="wrap"><h1 class="wp-heading-inline">Клієнти</h1> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cp-client-edit' ) ) . '" class="page-title-action">Додати клієнта</a>';
		if ( isset( $_GET['created'] ) ) {
			echo '<div class="notice notice-success"><p>Клієнта створено.</p></div>';
		}
		$this->maybe_render_password_notice();
		echo '<form method="post">';
		$list_table->display();
		echo '</form></div>';
	}

	public function render_client_edit_page() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		require CP_PLUGIN_DIR . 'includes/views/client-edit.php';
	}

	public function render_settings_page() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		require CP_PLUGIN_DIR . 'includes/views/settings.php';
	}

	public function render_client_links_page() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}

		$client_id = (int) ( $_GET['client_id'] ?? 0 );
		$client    = CP_DB::get_client( $client_id );
		if ( ! $client ) {
			wp_die( 'Клієнта не знайдено.' );
		}

		$links = CP_DB::get_links_for_client( $client_id );
		require CP_PLUGIN_DIR . 'includes/views/client-links.php';
	}

	/**
	 * Показує логін/пароль клієнта ОДИН раз (одразу після створення чи скидання),
	 * якщо для поточного client_id є ще не прочитаний transient. Викликати на
	 * сторінках, куди редіректить handle_save_client()/handle_reset_password().
	 */
	private function maybe_render_password_notice() {
		$client_id = (int) ( $_GET['client_id'] ?? $_GET['pw_client'] ?? 0 );
		echo self::render_password_notice_html( $client_id );
	}

	/**
	 * Публічний хелпер, щоб view-файли (includes/views/*.php) теж могли показати
	 * цей notice без дублювання логіки. Повертає порожній рядок, якщо показувати
	 * нічого.
	 */
	public static function render_password_notice_html( $client_id ) {
		$client_id = (int) $client_id;
		if ( ! $client_id ) {
			return '';
		}

		$data = get_transient( 'cp_show_password_' . $client_id );
		if ( ! $data ) {
			return '';
		}
		delete_transient( 'cp_show_password_' . $client_id );

		$html  = '<div class="notice notice-warning"><p><strong>Пароль клієнта (показується лише один раз, занотуйте зараз):</strong><br>';
		$html .= 'Логін: <code>' . esc_html( $data['login'] ) . '</code><br>';
		$html .= 'Пароль: <code>' . esc_html( $data['password'] ) . '</code></p></div>';
		return $html;
	}

	public function handle_save_client() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_save_client' );

		$login       = sanitize_user( wp_unslash( $_POST['cp_login'] ?? '' ) );
		$email       = sanitize_email( wp_unslash( $_POST['cp_email'] ?? '' ) );
		$password    = wp_unslash( $_POST['cp_password'] ?? '' );
		$campaign_id = sanitize_text_field( wp_unslash( $_POST['cp_campaign_id'] ?? '' ) );
		$source_id   = sanitize_text_field( wp_unslash( $_POST['cp_source_id'] ?? '' ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['cp_link_label'] ?? '' ) );

		if ( empty( $login ) || empty( $email ) ) {
			wp_die( 'Логін і email обов’язкові.' );
		}

		if ( empty( $password ) ) {
			$password = wp_generate_password( 12, false );
		}

		$user_id = wp_create_user( $login, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			wp_die( esc_html( $user_id->get_error_message() ) );
		}

		$user = new WP_User( $user_id );
		$user->set_role( CP_Roles::ROLE_CLIENT );

		$client_id = CP_DB::insert_client( $user_id, $login );

		if ( ! empty( $campaign_id ) || ! empty( $source_id ) ) {
			CP_DB::add_link( $client_id, $campaign_id, $source_id, $label );
		}

		$this->store_password_for_display( $client_id, $login, $password );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'cp-client-links',
					'client_id' => $client_id,
					'created'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * WordPress ніколи не зберігає паролі у відкритому вигляді (тільки хеш),
	 * тож показати існуючий пароль неможливо в принципі. Єдиний спосіб "нагадати"
	 * клієнту пароль — згенерувати новий і показати його адміну один раз одразу
	 * після створення/скидання (через короткоживучий transient, видаляється після
	 * першого показу).
	 */
	public function handle_reset_password() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_reset_password' );

		$client_id = (int) ( $_GET['client_id'] ?? 0 );
		$client    = CP_DB::get_client( $client_id );
		if ( ! $client ) {
			wp_die( 'Клієнта не знайдено.' );
		}

		$user = get_userdata( $client->wp_user_id );
		if ( ! $user ) {
			wp_die( 'Користувача не знайдено.' );
		}

		$new_password = wp_generate_password( 12, false );
		wp_set_password( $new_password, $user->ID );

		$this->store_password_for_display( $client_id, $user->user_login, $new_password );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'cp-clients',
					'pw_client' => $client_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function store_password_for_display( $client_id, $login, $password ) {
		set_transient(
			'cp_show_password_' . $client_id,
			array(
				'login'    => $login,
				'password' => $password,
			),
			300
		);
	}

	public function handle_add_link() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_add_link' );

		$client_id   = (int) ( $_POST['client_id'] ?? 0 );
		$campaign_id = sanitize_text_field( wp_unslash( $_POST['cp_campaign_id'] ?? '' ) );
		$source_id   = sanitize_text_field( wp_unslash( $_POST['cp_source_id'] ?? '' ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['cp_link_label'] ?? '' ) );

		if ( ! CP_DB::get_client( $client_id ) ) {
			wp_die( 'Клієнта не знайдено.' );
		}
		if ( empty( $campaign_id ) && empty( $source_id ) ) {
			wp_die( 'Вкажіть Campaign ID або Source ID.' );
		}

		CP_DB::add_link( $client_id, $campaign_id, $source_id, $label );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'cp-client-links',
					'client_id' => $client_id,
					'added'     => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_delete_link() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_delete_link' );

		$link_id = (int) ( $_GET['link_id'] ?? 0 );
		$link    = CP_DB::get_link( $link_id );
		if ( ! $link ) {
			wp_die( 'Прив’язку не знайдено.' );
		}

		CP_DB::delete_link( $link_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'cp-client-links',
					'client_id' => $link->client_id,
					'deleted'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_toggle_status() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_toggle_status' );

		$client_id = (int) ( $_GET['client_id'] ?? 0 );
		$client    = CP_DB::get_client( $client_id );
		if ( ! $client ) {
			wp_die( 'Клієнта не знайдено.' );
		}

		$new_status = ( 'active' === $client->status ) ? 'blocked' : 'active';
		CP_DB::set_client_status( $client_id, $new_status );

		wp_safe_redirect( admin_url( 'admin.php?page=cp-clients' ) );
		exit;
	}

	public function handle_save_settings() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_save_settings' );

		update_option( 'cp_keitaro_url', sanitize_text_field( wp_unslash( $_POST['cp_keitaro_url'] ?? '' ) ) );
		update_option( 'cp_keitaro_api_key', sanitize_text_field( wp_unslash( $_POST['cp_keitaro_api_key'] ?? '' ) ) );
		update_option( 'cp_sync_interval_minutes', (int) ( $_POST['cp_sync_interval_minutes'] ?? 15 ) );
		update_option( 'cp_sync_lookback_days', (int) ( $_POST['cp_sync_lookback_days'] ?? 3 ) );
		update_option( 'cp_dashboard_page_id', (int) ( $_POST['cp_dashboard_page_id'] ?? 0 ) );

		wp_safe_redirect( admin_url( 'admin.php?page=cp-settings&saved=1' ) );
		exit;
	}

	public function handle_test_connection() {
		if ( ! current_user_can( CP_Roles::CAP_MANAGE_CLIENTS ) ) {
			wp_die( 'Недостатньо прав.' );
		}
		check_admin_referer( 'cp_test_keitaro' );

		$api    = new CP_Keitaro_Api();
		$result = $api->test_connection();

		$status  = is_wp_error( $result ) ? 'error' : 'ok';
		$message = is_wp_error( $result ) ? $result->get_error_message() : 'З’єднання з Keitaro успішне.';

		set_transient(
			'cp_keitaro_test_result',
			array(
				'status'  => $status,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=cp-settings&tested=1' ) );
		exit;
	}
}
