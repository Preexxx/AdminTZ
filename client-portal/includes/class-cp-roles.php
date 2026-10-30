<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Roles {

	const ROLE_CLIENT        = 'cp_client';
	const CAP_VIEW_DASHBOARD = 'cp_view_own_dashboard';
	const CAP_MANAGE_CLIENTS = 'cp_manage_clients';

	public static function add_roles() {
		add_role(
			self::ROLE_CLIENT,
			'Client',
			array(
				'read'                   => true,
				self::CAP_VIEW_DASHBOARD => true,
			)
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE_CLIENTS );
		}
	}
}
