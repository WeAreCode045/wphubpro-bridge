<?php
/**
 * Connect & site linking for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles site connect, API key validation, and admin menu.
 */
class WPHubPro_Bridge_Connect {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Validate API key from request header.
	 *
	 * @return bool
	 */
	public static function validate_api_key() {
		$stored_key   = get_option( 'wphubpro_api_key' );
		$provided_key = isset( $_SERVER['HTTP_X_WPHUB_KEY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WPHUB_KEY'] ) ) : '';
		if ( empty( $stored_key ) || empty( $provided_key ) ) {
			return false;
		}
		return hash_equals( $stored_key, $provided_key );
	}

	/**
	 * Add admin menu for WPHubPro Bridge.
	 */
	public function add_admin_menu() {
		add_menu_page(
			'WPHubPro Bridge',
			'WPHubPro Bridge',
			'manage_options',
			'wphubpro-bridge',
			array( $this, 'render_admin_page' ),
			'dashicons-admin-links',
			80
		);
	}

	/**
	 * Render the connect admin page with tabs.
	 */
	public function render_admin_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connect';
		$base_url = admin_url( 'admin.php?page=wphubpro-bridge' );
		?>
		<div class="wrap">
			<h1>WPHubPro Bridge</h1>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo $tab === 'connect' ? 'nav-tab-active' : ''; ?>">Koppelen</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'debug', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'debug' ? 'nav-tab-active' : ''; ?>">Debug</a>
			</nav>

			<?php if ( $tab === 'connect' ) : ?>
				<?php $this->render_connect_tab(); ?>
			<?php elseif ( $tab === 'debug' ) : ?>
				<?php $this->render_debug_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the connect tab.
	 */
	private function render_connect_tab() {
		$connect_url    = get_rest_url( null, 'wphubpro/v1/connect' );
		$status_url     = get_rest_url( null, 'wphubpro/v1/connection-status' );
		$disconnect_url = get_rest_url( null, 'wphubpro/v1/disconnect' );
		$nonce          = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wphubpro-tab-content" style="margin-top:1em">
			<div id="wphubpro-not-connected" style="display:none">
				<p>Verbind deze site met uw dashboard.</p>
				<button id="wphubpro-btn" class="button button-primary">Nu Koppelen</button>
			</div>
			<div id="wphubpro-connected-card" style="display:none" class="wphubpro-connection-card">
				<h2>Verbindingsstatus</h2>
				<div class="wphubpro-card" style="max-width:600px;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin:16px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)">
					<table class="widefat striped" style="margin-bottom:16px">
						<tbody>
							<tr><th style="width:180px">Platform gebruiker</th><td id="wphubpro-username">—</td></tr>
							<tr><th>Plan</th><td id="wphubpro-plan">—</td></tr>
							<tr><th>Site ID</th><td id="wphubpro-site-id"><code style="font-size:12px">—</code></td></tr>
							<tr><th>Gekoppeld op</th><td id="wphubpro-connected-at">—</td></tr>
						</tbody>
					</table>
					<h3 style="margin:16px 0 8px;font-size:14px">Actielog</h3>
					<div id="wphubpro-action-log" style="max-height:200px;overflow-y:auto;font-size:12px;background:#f6f7f7;padding:12px;border-radius:4px">
						<p style="margin:0;color:#646970">Geen acties gelogd.</p>
					</div>
					<p style="margin-top:16px">
						<button type="button" id="wphubpro-reconnect" class="button button-primary">Opnieuw koppelen</button>
						<button type="button" id="wphubpro-remove" class="button">Verwijderen van hub</button>
					</p>
				</div>
			</div>
			<div id="wphubpro-status-error" style="display:none" class="notice notice-warning inline"><p><span id="wphubpro-status-error-msg"></span></p></div>
			<div id="wphubpro-status-loading" style="margin-top:1em"><p>Status laden…</p></div>
		</div>
		<script>
		(function(){
			var connectUrl=<?php echo wp_json_encode( $connect_url ); ?>;
			var statusUrl=<?php echo wp_json_encode( $status_url ); ?>;
			var disconnectUrl=<?php echo wp_json_encode( $disconnect_url ); ?>;
			var nonce=<?php echo wp_json_encode( $nonce ); ?>;
			function req(u,o){o=o||{};o.headers=o.headers||{};o.headers['X-WP-Nonce']=nonce;if(o.body&&typeof o.body==='object'){o.headers['Content-Type']='application/json';o.body=JSON.stringify(o.body);}return fetch(u,o).then(function(r){return r.json();});}
			function fmt(d){if(!d)return'—';try{return new Date(d).toLocaleString('nl-NL',{dateStyle:'medium',timeStyle:'short'});}catch(e){return d;}}
			function renderLog(log){var el=document.getElementById('wphubpro-action-log');if(!log||!log.length){el.innerHTML='<p style="margin:0;color:#646970">Geen acties gelogd.</p>';return;}
			var h='<table class="widefat striped" style="margin:0"><thead><tr><th>Actie</th><th>Endpoint</th><th>Datum</th></tr></thead><tbody>';
			log.forEach(function(e){h+='<tr><td>'+String(e.action||'').replace(/</g,'&lt;')+'</td><td><code style="font-size:11px">'+String(e.endpoint||'').replace(/</g,'&lt;')+'</code></td><td>'+fmt(e.timestamp)+'</td></tr>';});
			el.innerHTML=h+'</tbody></table>';}
			function load(){var ld=document.getElementById('wphubpro-status-loading'),nc=document.getElementById('wphubpro-not-connected'),card=document.getElementById('wphubpro-connected-card'),err=document.getElementById('wphubpro-status-error'),errMsg=document.getElementById('wphubpro-status-error-msg');
			ld.style.display='block';nc.style.display='none';card.style.display='none';err.style.display='none';
			req(statusUrl).then(function(d){ld.style.display='none';
			if(d.connected){if(d.error){errMsg.textContent=d.error;err.style.display='block';}card.style.display='block';
			document.getElementById('wphubpro-username').textContent=d.username||'—';
			document.getElementById('wphubpro-plan').textContent=d.plan_name||'—';
			document.getElementById('wphubpro-site-id').innerHTML='<code style="font-size:12px">'+(d.site_id||'—').replace(/</g,'&lt;')+'</code>';
			document.getElementById('wphubpro-connected-at').textContent=fmt(d.connected_at);renderLog(d.action_log);}else{nc.style.display='block';}}).catch(function(){ld.style.display='none';nc.style.display='block';});}
			load();
			document.getElementById('wphubpro-btn').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
			document.getElementById('wphubpro-reconnect').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
			document.getElementById('wphubpro-remove').onclick=function(){if(!confirm('Weet je zeker dat je deze site wilt verwijderen van de hub?'))return;req(disconnectUrl,{method:'POST'}).then(function(){window.location.reload();});};
		})();
		</script>
		<?php
	}

	/**
	 * Render the debug tab with domain selection.
	 */
	private function render_debug_tab() {
		$rest_url = get_rest_url( null, 'wphubpro/v1/debug' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		$current  = get_option( 'wphubpro_redirect_base_url', 'https://wphub.pro' );
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
						<p class="description">Domeinen worden opgehaald uit platform_settings (key: redirect_domains) in Appwrite.</p>
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
		<?php
	}

	/**
	 * Handle disconnect: remove API key locally.
	 *
	 * @return array{success: bool}
	 */
	public function handle_disconnect() {
		delete_option( 'wphubpro_api_key' );
		return array( 'success' => true );
	}

	/**
	 * Handle connect request: generate API key and return redirect URL.
	 *
	 * Base URL is configurable via Debug tab (platform_settings redirect_domains).
	 *
	 * @return array{redirect: string}
	 */
	public function handle_connect() {
		error_log( '[WPHubPro Bridge] connect GET' );
		$api_key = wp_generate_password( 32, false );
		update_option( 'wphubpro_api_key', $api_key );
		$params = array(
			'site_url'   => get_site_url(),
			'user_login' => wp_get_current_user()->user_login,
			'api_key'    => $api_key,
		);
		$base    = WPHubPro_Bridge_Debug::get_redirect_base_url();
		$base    = untrailingslashit( $base );
		$redirect = $base . '/#' . add_query_arg( $params, '/connect-success' );
		return array( 'redirect' => $redirect );
	}
}
