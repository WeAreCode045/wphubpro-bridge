<?php
/**
 * Admin page template – Bridge dashboard shell.
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

			<?php
			$bridge_version = Config::get_bridge_version();
			include $template_dir . 'admin-dashboard.php';
			?>
		</div>
	</div>
</div>
