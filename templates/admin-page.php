<?php
/**
 * Admin page template – main wrapper with tab navigation.
 *
 * Expects: $tab, $base_url
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$template_dir = WPHUBPRO_BRIDGE_ABSPATH . 'templates/';
?>
<div class="wrap">
	<h1>WPHubPro Bridge</h1>
	<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
		<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo $tab === 'connect' ? 'nav-tab-active' : ''; ?>">Koppelen</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'debug', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'debug' ? 'nav-tab-active' : ''; ?>">Debug</a>
	</nav>

	<?php if ( $tab === 'connect' ) : ?>
		<?php
		$connect_url    = get_rest_url( null, 'wphubpro/v1/connect' );
		$status_url     = get_rest_url( null, 'wphubpro/v1/connection-status' );
		$disconnect_url = get_rest_url( null, 'wphubpro/v1/disconnect' );
		$nonce          = wp_create_nonce( 'wp_rest' );
		include $template_dir . 'admin-connect-tab.php';
		?>
	<?php elseif ( $tab === 'debug' ) : ?>
		<?php
		$rest_url = get_rest_url( null, 'wphubpro/v1/debug' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		$current  = get_option( 'wphubpro_redirect_base_url', 'https://wphub.pro' );
		include $template_dir . 'admin-debug-tab.php';
		?>
	<?php endif; ?>
</div>
