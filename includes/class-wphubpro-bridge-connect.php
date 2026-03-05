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
		$current_key = get_option( 'wphubpro_api_key' );
		$connect_url = get_rest_url( null, 'wphubpro/v1/connect' );
		$nonce       = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wphubpro-tab-content" style="margin-top:1em">
			<p>Verbind deze site met uw dashboard.</p>
			<button id="wphubpro-btn" class="button button-primary">Nu Koppelen</button>
			<?php if ( $current_key ) : ?>
				<p><strong>Actieve Key:</strong> <code><?php echo esc_html( $current_key ); ?></code></p>
			<?php endif; ?>
			<script>
				document.getElementById('wphubpro-btn').onclick = function() {
					fetch('<?php echo esc_url( $connect_url ); ?>', {
						headers: { 'X-WP-Nonce': '<?php echo esc_js( $nonce ); ?>' }
					})
					.then(function(r) { return r.json(); })
					.then(function(d) {
						if (d.redirect) window.location.href = d.redirect;
					});
				};
			</script>
		</div>
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
