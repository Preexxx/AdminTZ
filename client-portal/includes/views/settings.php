<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$test_result = get_transient( 'cp_keitaro_test_result' );
?>
<div class="wrap">
	<h1>Налаштування Keitaro / синку</h1>
	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success"><p>Налаштування збережено.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['synced'] ) ) : ?>
		<div class="notice notice-success"><p>Синхронізацію запущено.</p></div>
	<?php endif; ?>
	<?php if ( $test_result ) : ?>
		<div class="notice notice-<?php echo 'ok' === $test_result['status'] ? 'success' : 'error'; ?>">
			<p><?php echo esc_html( $test_result['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cp_save_settings' ); ?>
		<input type="hidden" name="action" value="cp_save_settings" />
		<table class="form-table">
			<tr>
				<th><label for="cp_keitaro_url">Keitaro URL</label></th>
				<td><input type="url" id="cp_keitaro_url" name="cp_keitaro_url" class="regular-text" value="<?php echo esc_attr( get_option( 'cp_keitaro_url', '' ) ); ?>" placeholder="https://your-keitaro.tld" /></td>
			</tr>
			<tr>
				<th><label for="cp_keitaro_api_key">Keitaro API Key</label></th>
				<td><input type="text" id="cp_keitaro_api_key" name="cp_keitaro_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'cp_keitaro_api_key', '' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="cp_sync_interval_minutes">Інтервал синку (хв)</label></th>
				<td><input type="number" min="5" id="cp_sync_interval_minutes" name="cp_sync_interval_minutes" value="<?php echo esc_attr( get_option( 'cp_sync_interval_minutes', 15 ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="cp_sync_lookback_days">Глибина синку (днів назад)</label></th>
				<td><input type="number" min="1" id="cp_sync_lookback_days" name="cp_sync_lookback_days" value="<?php echo esc_attr( get_option( 'cp_sync_lookback_days', 3 ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="cp_dashboard_page_id">ID сторінки кабінету клієнта</label></th>
				<td>
					<input type="number" id="cp_dashboard_page_id" name="cp_dashboard_page_id" value="<?php echo esc_attr( get_option( 'cp_dashboard_page_id', 0 ) ); ?>" />
					<p class="description">Сторінка, що містить шорткод <code>[cp_dashboard]</code> — сюди редіректимо клієнта після логіну.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Зберегти налаштування' ); ?>
	</form>

	<hr />
	<h2>Синхронізація</h2>
	<p>Останній синк: <strong><?php echo esc_html( get_option( 'cp_last_sync_at', 'ще не запускався' ) ); ?></strong></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right:10px;">
		<?php wp_nonce_field( 'cp_manual_sync' ); ?>
		<input type="hidden" name="action" value="cp_manual_sync" />
		<?php submit_button( 'Синхронізувати зараз', 'secondary', 'submit', false ); ?>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
		<?php wp_nonce_field( 'cp_test_keitaro' ); ?>
		<input type="hidden" name="action" value="cp_test_keitaro" />
		<?php submit_button( 'Перевірити з’єднання з Keitaro', 'secondary', 'submit', false ); ?>
	</form>
</div>
