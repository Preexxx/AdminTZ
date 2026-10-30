<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CP_Clients_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'client',
				'plural'   => 'clients',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'display_name' => 'Логін',
			'email'        => 'Email',
			'status'       => 'Статус',
			'links'        => "Прив'язка Keitaro",
			'actions'      => 'Дії',
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = CP_DB::get_all_clients();
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'display_name':
				return esc_html( $item->display_name );

			case 'email':
				$user = get_userdata( $item->wp_user_id );
				return $user ? esc_html( $user->user_email ) : '—';

			case 'status':
				return 'active' === $item->status
					? '<span style="color:green;">активний</span>'
					: '<span style="color:#b32d2e;">блокований</span>';

			case 'links':
				$links = CP_DB::get_links_for_client( $item->id );
				if ( empty( $links ) ) {
					return '—';
				}
				$out = array();
				foreach ( $links as $link ) {
					$out[] = esc_html( trim( $link->keitaro_campaign_id . ' / ' . $link->keitaro_source_id, ' /' ) );
				}
				return implode( '<br>', $out );

			case 'actions':
				$toggle_label = 'active' === $item->status ? 'Блокувати' : 'Розблокувати';
				$toggle_url   = wp_nonce_url( admin_url( 'admin-post.php?action=cp_toggle_client_status&client_id=' . $item->id ), 'cp_toggle_status' );
				$links_url    = admin_url( 'admin.php?page=cp-client-links&client_id=' . $item->id );
				$reset_url    = wp_nonce_url( admin_url( 'admin-post.php?action=cp_reset_password&client_id=' . $item->id ), 'cp_reset_password' );
				return '<a href="' . esc_url( $links_url ) . '" class="button button-small">Прив’язки</a> '
					. '<a href="' . esc_url( $reset_url ) . '" class="button button-small" onclick="return confirm(\'Згенерувати новий пароль? Старий перестане діяти.\');">Скинути пароль</a> '
					. '<a href="' . esc_url( $toggle_url ) . '" class="button button-small">' . esc_html( $toggle_label ) . '</a>';

			default:
				return '—';
		}
	}
}
