<?php
/**
 * Admin connect tab template.
 *
 * Expects: $connect_url, $status_url, $disconnect_url, $nonce
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
	function load(){var ld=document.getElementById('wphubpro-status-loading'),nc=document.getElementById('wphubpro-not-connected'),card=document.getElementById('wphubpro-connected-card'),err=document.getElementById('wphubpro-status-error'),errMsg=document.getElementById('wphubpro-status-error-msg');
	ld.style.display='block';nc.style.display='none';card.style.display='none';err.style.display='none';
	req(statusUrl).then(function(d){ld.style.display='none';
	if(d.connected){if(d.error){errMsg.textContent=d.error;err.style.display='block';}card.style.display='block';
	document.getElementById('wphubpro-username').textContent=d.username||'—';
	document.getElementById('wphubpro-plan').textContent=d.plan_name||'—';
	document.getElementById('wphubpro-site-id').innerHTML='<code style="font-size:12px">'+(d.site_id||'—').replace(/</g,'&lt;')+'</code>';
	document.getElementById('wphubpro-connected-at').textContent=fmt(d.connected_at);}else{nc.style.display='block';}}).catch(function(){ld.style.display='none';nc.style.display='block';});}
	load();
	document.getElementById('wphubpro-btn').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
	document.getElementById('wphubpro-reconnect').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
	document.getElementById('wphubpro-remove').onclick=function(){if(!confirm('Weet je zeker dat je deze site wilt verwijderen van de hub?'))return;req(disconnectUrl,{method:'POST'}).then(function(){window.location.reload();});};
})();
</script>
