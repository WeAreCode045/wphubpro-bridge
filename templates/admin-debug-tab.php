<?php
/**
 * Admin debug tab template.
 *
 * Expects: $rest_url, $nonce, $current
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wphubpro-tab-content" style="margin-top:1em">
	<h2>Redirect base URL</h2>
	<p>Selecteer het domein waarnaar de "Nu Koppelen" knop redirect. Handig bij verschillende deployments (productie, dev, local).</p>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="wphubpro-base-url">Base URL</label></th>
			<td>
				<select id="wphubpro-base-url" style="min-width:280px">
					<option value="">— Laden… —</option>
				</select>
				<p class="description">Selecteer het redirect-domein voor de koppel-flow.</p>
			</td>
		</tr>
	</table>
	<p>
		<button type="button" id="wphubpro-save-base-url" class="button button-primary" disabled>Opslaan</button>
		<span id="wphubpro-save-status" style="margin-left:8px"></span>
	</p>
	<script>
	(function() {
		var restBase = <?php echo wp_json_encode( $rest_url ); ?>;
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var current = <?php echo wp_json_encode( $current ); ?>;
		var select = document.getElementById('wphubpro-base-url');
		var saveBtn = document.getElementById('wphubpro-save-base-url');
		var status = document.getElementById('wphubpro-save-status');

		function req(path, opts) {
			opts = opts || {};
			opts.headers = opts.headers || {};
			opts.headers['X-WP-Nonce'] = nonce;
			if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(opts.body);
			}
			return fetch(restBase + path, opts).then(function(r) { return r.json(); });
		}

		req('/domains').then(function(data) {
			select.innerHTML = '';
			if (data.domains && data.domains.length) {
				data.domains.forEach(function(url) {
					var opt = document.createElement('option');
					opt.value = url;
					opt.textContent = url;
					if (url === current) opt.selected = true;
					select.appendChild(opt);
				});
			} else {
				var opt = document.createElement('option');
				opt.value = current;
				opt.textContent = current || '— Geen domeinen —';
				select.appendChild(opt);
			}
			saveBtn.disabled = false;
		}).catch(function() {
			select.innerHTML = '<option value="' + current + '">' + current + '</option>';
			saveBtn.disabled = false;
		});

		saveBtn.onclick = function() {
			var url = select.value;
			if (!url) return;
			saveBtn.disabled = true;
			status.textContent = 'Bezig…';
			req('/base-url', { method: 'POST', body: { base_url: url } }).then(function(data) {
				status.textContent = 'Opgeslagen.';
				saveBtn.disabled = false;
				setTimeout(function() { status.textContent = ''; }, 2000);
			}).catch(function() {
				status.textContent = 'Fout bij opslaan.';
				saveBtn.disabled = false;
			});
		};
	})();
	</script>
</div>
