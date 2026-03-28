<?php
/**
 * Bridge admin dashboard content (connection status, docs).
 *
 * Expects: $bridge_version (optional; defaults via Config).
 *
 * @package WPHubPro
 */

use WPHubPro\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bridge_version = $bridge_version ?? ( class_exists( Config::class ) ? Config::get_bridge_version() : '' );
$bridge_docs_url = 'https://docs.wphub.pro';
?>
<div class="wphubpro-bridge-dashboard">
	<div id="wphubpro-update-notice" style="display:none" class="alert alert-info mb-3" role="alert">
		<p class="mb-2"><strong>Update beschikbaar:</strong> Er is een nieuwere versie van de WPHubPro Bridge beschikbaar (v<span id="wphubpro-latest-version"></span>).</p>
		<button type="button" id="wphubpro-install-update" class="btn btn-primary btn-sm">Nu installeren</button>
	</div>

	<div class="row g-3 align-items-stretch">
		<div class="col-12 col-lg-7 d-flex flex-column gap-3">
	<div id="wphubpro-not-connected" style="display:none" class="card mb-0">
		<div class="card-body">
			<p class="mb-2">Verbind deze site met uw dashboard.</p>
			<p class="text-muted small mb-3">Geïnstalleerde bridge versie: <strong id="wphubpro-bridge-version-nc"><?php echo esc_html( $bridge_version ?: '—' ); ?></strong></p>
			<button type="button" id="wphubpro-btn" class="btn btn-primary">Nu Koppelen</button>
		</div>
	</div>

	<div id="wphubpro-connected-card" style="display:none" class="card mb-0 wphubpro-connection-card">
		<div class="card-header border-bottom py-3">
			<h5 class="card-title mb-0">Verbindingsstatus</h5>
		</div>
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-striped table-bordered table-hover mb-0 align-middle">
					<tbody>
						<tr>
							<th scope="row" class="text-nowrap" style="width:11rem">Site ID</th>
							<td id="wphubpro-site-id"><code class="small">—</code></td>
						</tr>
						<tr>
							<th scope="row">Status</th>
							<td id="wphubpro-status">
								<span id="wphubpro-heartbeat-bullet" class="wphubpro-ht-bullet rounded-circle d-inline-block align-middle me-1" title="wphub_status" style="width:10px;height:10px"></span>
								<span id="wphubpro-status-text">—</span>
							</td>
						</tr>
						<tr>
							<th scope="row">Bridge versie</th>
							<td>
								<span id="wphubpro-bridge-version">—</span>
								<button type="button" id="wphubpro-check-update" class="btn btn-soft-primary btn-icon btn-sm ms-2 align-middle" title="Controleren op updates" aria-label="Controleren op updates">
									<i class="ti ti-refresh fs-lg" aria-hidden="true"></i>
								</button>
								<span id="wphubpro-check-status" class="ms-2 small text-muted" style="display:none"></span>
							</td>
						</tr>
						<tr>
							<th scope="row">Laatste heartbeat</th>
							<td id="wphubpro-last-heartbeat-text">—</td>
						</tr>
						<tr>
							<th scope="row">Platform URL</th>
							<td>
								<span id="wphubpro-platform-url">—</span>
								<button type="button" id="wphubpro-edit-platform-url" class="btn btn-soft-primary btn-icon btn-sm ms-2 align-middle" title="Platform URL bewerken" aria-label="Platform URL bewerken">
									<i class="ti ti-link-plus fs-lg" aria-hidden="true"></i>
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="mt-3 d-flex flex-wrap gap-2">
				<button type="button" id="wphubpro-reconnect" class="btn btn-primary">Opnieuw koppelen</button>
				<button type="button" id="wphubpro-remove" class="btn btn-soft-danger">Verwijderen van hub</button>
			</div>
		</div>
	</div>

	<div id="wphubpro-status-error" style="display:none" class="alert alert-warning mb-0" role="alert">
		<p class="mb-0"><span id="wphubpro-status-error-msg"></span></p>
	</div>

	<div id="wphubpro-status-loading" class="text-muted py-2 mb-0">
		<p class="mb-0">Status laden…</p>
	</div>
		</div>
		<div class="col-12 col-lg-5 d-flex">
			<div class="card mb-0 w-100 h-100">
				<div class="card-header border-bottom py-3">
					<h5 class="card-title mb-0">Documentatie</h5>
				</div>
				<div class="card-body d-flex flex-column">
					<p class="text-muted mb-3 flex-grow-1">
						Op het WPHub.Pro-platform vindt u uitleg over de Bridge-plugin: installeren, koppelen met uw dashboard,
						heartbeat en veelgestelde vragen. Raadpleeg de officiële documentatie voor de meest actuele stappen en technische details.
					</p>
					<a href="<?php echo esc_url( $bridge_docs_url ); ?>" class="btn btn-soft-primary btn-sm d-inline-flex align-items-center gap-1 align-self-start" target="_blank" rel="noopener noreferrer">
						<i class="ti ti-external-link fs-lg" aria-hidden="true"></i>
						<span>Documentatie op docs.wphub.pro</span>
					</a>
				</div>
			</div>
		</div>
	</div>
</div>
