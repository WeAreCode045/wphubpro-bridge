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
	<h1>WPHubPro Bridge</h1>
	<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
		<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo $tab === 'connect' ? 'nav-tab-active' : ''; ?>">Overzicht</a>
	</nav>

	<?php if ( $tab === 'connect' ) : ?>
		<?php
		$connect_url           = get_rest_url( null, 'wphubpro/v1/connect' );
		$status_url            = get_rest_url( null, 'wphubpro/v1/connection-status' );
		$disconnect_url        = get_rest_url( null, 'wphubpro/v1/disconnect' );
		$redirect_settings_url = get_rest_url( null, 'wphubpro/v1/connect/redirect-settings' );
		$check_update_url      = get_rest_url( null, 'wphubpro/v1/bridge/check-update' );
		$install_update_url    = get_rest_url( null, 'wphubpro/v1/bridge/install-update' );
		$nonce            = wp_create_nonce( 'wp_rest' );
		$bridge_version   = Config::get_bridge_version();
		include $template_dir . 'admin-connect-tab.php';
		?>
	<?php endif; ?>
</div>
