<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cp_api                 = new CP_Keitaro_Api();
$cp_configured          = $cp_api->is_configured();
$cp_campaign_options    = $cp_configured ? $cp_api->get_campaign_options() : array();
$cp_source_options      = $cp_configured ? $cp_api->get_source_options() : array();
$cp_streams_by_campaign = $cp_configured ? $cp_api->get_all_stream_options() : array();
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
				<th><label for="cp_campaign_id">Keitaro Campaign (перша кампанія)</label></th>
				<td>
					<?php if ( ! empty( $cp_campaign_options ) ) : ?>
						<select id="cp_campaign_id" name="cp_campaign_id" class="regular-text">
							<option value="">— не вказано —</option>
							<?php foreach ( $cp_campaign_options as $cp_id => $cp_name ) : ?>
								<option value="<?php echo esc_attr( $cp_id ); ?>"><?php echo esc_html( $cp_name ); ?> (#<?php echo esc_html( $cp_id ); ?>)</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="text" id="cp_campaign_id" name="cp_campaign_id" class="regular-text" placeholder="Campaign ID" />
						<p class="description">Не вдалось завантажити список кампаній із Keitaro (перевірте налаштування API) — вкажіть ID вручну.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="cp_stream_id">Keitaro Stream (потік клієнта)</label></th>
				<td>
					<?php if ( ! empty( $cp_campaign_options ) ) : ?>
						<select id="cp_stream_id" name="cp_stream_id" class="regular-text">
							<option value="">— спершу оберіть кампанію —</option>
						</select>
						<p class="description">Саме потік визначає, яку частину трафіку цієї кампанії побачить клієнт — не Source.</p>
					<?php else : ?>
						<input type="text" id="cp_stream_id" name="cp_stream_id" class="regular-text" placeholder="Stream ID" />
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="cp_source_id">Keitaro Source (опційно)</label></th>
				<td>
					<?php if ( ! empty( $cp_source_options ) ) : ?>
						<select id="cp_source_id" name="cp_source_id" class="regular-text">
							<option value="">— не фільтрувати за джерелом —</option>
							<?php foreach ( $cp_source_options as $cp_id => $cp_name ) : ?>
								<option value="<?php echo esc_attr( $cp_id ); ?>"><?php echo esc_html( $cp_name ); ?> (#<?php echo esc_html( $cp_id ); ?>)</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="text" id="cp_source_id" name="cp_source_id" class="regular-text" placeholder="Source ID" />
					<?php endif; ?>
				</td>
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

<?php if ( ! empty( $cp_campaign_options ) ) : ?>
<script>
( function () {
	var streamsByCampaign = <?php echo wp_json_encode( $cp_streams_by_campaign ); ?>;
	var campaignEl = document.getElementById( 'cp_campaign_id' );
	var streamEl = document.getElementById( 'cp_stream_id' );
	if ( ! campaignEl || ! streamEl ) {
		return;
	}

	function renderStreams( campaignId ) {
		var streams = streamsByCampaign[ campaignId ] || {};
		var html = campaignId
			? '<option value="">— усі потоки кампанії —</option>'
			: '<option value="">— спершу оберіть кампанію —</option>';
		Object.keys( streams ).forEach( function ( id ) {
			html += '<option value="' + id + '">' + streams[ id ] + ' (#' + id + ')</option>';
		} );
		streamEl.innerHTML = html;
	}

	campaignEl.addEventListener( 'change', function () {
		renderStreams( campaignEl.value );
	} );
	renderStreams( campaignEl.value );
} )();
</script>
<?php endif; ?>
