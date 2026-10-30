<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Додати клієнта</h1>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cp_save_client' ); ?>
		<input type="hidden" name="action" value="cp_save_client" />
		<table class="form-table">
			<tr>
				<th><label for="cp_login">Логін</label></th>
				<td><input type="text" id="cp_login" name="cp_login" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="cp_email">Email</label></th>
				<td><input type="email" id="cp_email" name="cp_email" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="cp_password">Пароль</label></th>
				<td><input type="text" id="cp_password" name="cp_password" class="regular-text" placeholder="Залиште пустим для автогенерації" /></td>
			</tr>
			<tr>
				<th><label for="cp_campaign_id">Keitaro Campaign ID (перша кампанія)</label></th>
				<td><input type="text" id="cp_campaign_id" name="cp_campaign_id" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cp_source_id">Keitaro Source ID</label></th>
				<td><input type="text" id="cp_source_id" name="cp_source_id" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="cp_link_label">Назва прив'язки (опційно)</label></th>
				<td><input type="text" id="cp_link_label" name="cp_link_label" class="regular-text" /></td>
			</tr>
		</table>
		<p class="description">Якщо у клієнта кілька кампаній у Keitaro — цю, першу, можна лишити пустою і додати всі кампанії на наступному кроці ("Прив'язки").</p>
		<?php submit_button( 'Створити клієнта' ); ?>
	</form>
</div>
