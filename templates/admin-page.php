<?php
/**
 * Admin page template – main wrapper with tab navigation.
 *
 * Expects: $tab, $base_url
 *
 * @package WPHubPro
 */

use WPHubPro\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$template_dir = WPHUBPRO_BRIDGE_ABSPATH . 'templates/';
?>
<div class="wrap wphubpro-admin-wrap">
	<div id="wphubpro-bridge-app" class="wphubpro-ubold" data-bs-theme="light">
		<h1 class="visually-hidden"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<div class="container-fluid py-3">
			<div class="page-title-head d-flex align-items-center mb-3">
				<div class="flex-grow-1">
					<h4 class="fs-xl fw-bold m-0">WPHubPro Bridge</h4>
				</div>
				<div class="text-end d-none d-md-block">
					<ol class="breadcrumb m-0 py-0">
						<li class="breadcrumb-item"><span class="text-muted">WPHubPro</span></li>
						<li class="breadcrumb-item active" aria-current="page">Bridge</li>
					</ol>
				</div>
			</div>

			<ul class="nav nav-tabs nav-bordered mb-3" role="tablist">
				<li class="nav-item" role="presentation">
					<a
						href="<?php echo esc_url( $base_url ); ?>"
						class="nav-link <?php echo $tab === 'connect' ? 'active' : ''; ?>"
						<?php echo $tab === 'connect' ? 'aria-current="page"' : ''; ?>
					>Overzicht</a>
				</li>
			</ul>

			<?php if ( $tab === 'connect' ) : ?>
				<?php
				$connect_url           = get_rest_url( null, 'wphubpro/v1/connect' );
				$status_url            = get_rest_url( null, 'wphubpro/v1/connection-status' );
				$disconnect_url        = get_rest_url( null, 'wphubpro/v1/disconnect' );
				$redirect_settings_url = get_rest_url( null, 'wphubpro/v1/connect/redirect-settings' );
				$check_update_url      = get_rest_url( null, 'wphubpro/v1/bridge/check-update' );
				$install_update_url    = get_rest_url( null, 'wphubpro/v1/bridge/install-update' );
				$nonce                 = wp_create_nonce( 'wp_rest' );
				$bridge_version        = Config::get_bridge_version();
				include $template_dir . 'admin-connect-tab.php';
				?>
			<?php endif; ?>
		</div>
	</div>
</div>
