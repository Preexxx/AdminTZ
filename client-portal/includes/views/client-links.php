<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Прив’язки Keitaro — <?php echo esc_html( $client->display_name ); ?></h1>

	<?php if ( isset( $_GET['created'] ) ) : ?>
		<div class="notice notice-success"><p>Клієнта створено. Додайте всі його кампанії нижче.</p></div>
	<?php endif; ?>
	<?php echo CP_Admin::render_password_notice_html( $client->id ); ?>

	<?php if ( isset( $_GET['added'] ) ) : ?>
		<div class="notice notice-success"><p>Прив’язку додано.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success"><p>Прив’язку видалено.</p></div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:700px;">
		<thead>
			<tr>
				<th>Campaign ID</th>
				<th>Source ID</th>
				<th>Назва (Keitaro)</th>
				<th>Назва (ваша)</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $links ) ) : ?>
				<tr><td colspan="5">Поки немає жодної прив’язки.</td></tr>
			<?php else : ?>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td><?php echo esc_html( $link->keitaro_campaign_id ); ?></td>
						<td><?php echo esc_html( $link->keitaro_source_id ); ?></td>
						<td><?php echo $link->campaign_name ? esc_html( $link->campaign_name ) : '<span style="color:#999;">— підтягнеться після синку —</span>'; ?></td>
						<td><?php echo esc_html( $link->label ); ?></td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cp_delete_link&link_id=' . $link->id ), 'cp_delete_link' ) ); ?>" class="button button-small" onclick="return confirm('Видалити прив’язку?');">Видалити</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<h2>Додати кампанію</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cp_add_link' ); ?>
		<input type="hidden" name="action" value="cp_add_link" />
		<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="cp_campaign_id">Keitaro Campaign ID</label></th>
				<td><input type="text" id="cp_campaign_id" name="cp_campaign_id" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cp_source_id">Keitaro Source ID</label></th>
				<td><input type="text" id="cp_source_id" name="cp_source_id" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cp_link_label">Назва (опційно)</label></th>
				<td><input type="text" id="cp_link_label" name="cp_link_label" class="regular-text" /></td>
			</tr>
		</table>
		<?php submit_button( 'Додати' ); ?>
	</form>

	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cp-clients' ) ); ?>">← До списку клієнтів</a></p>
</div>
