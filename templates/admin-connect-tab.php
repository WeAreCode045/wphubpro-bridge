<?php
/**
 * Admin connect tab template.
 *
 * Expects: $connect_url, $status_url, $disconnect_url, $redirect_settings_url, $check_update_url, $install_update_url, $nonce, $bridge_version
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$bridge_version = $bridge_version ?? ( class_exists( 'WPHubPro_Bridge_Config' ) ? WPHubPro_Bridge_Config::get_bridge_version() : '' );
$redirect_settings_url = $redirect_settings_url ?? '';
$check_update_url = $check_update_url ?? '';
$install_update_url = $install_update_url ?? '';
?>
<div class="wphubpro-tab-content" style="margin-top:1em">
	<div id="wphubpro-update-notice" style="display:none" class="notice notice-info inline" role="alert">
		<p><strong>Update beschikbaar:</strong> Er is een nieuwere versie van de WPHubPro Bridge beschikbaar (v<span id="wphubpro-latest-version"></span>).</p>
		<p style="margin-top:8px">
			<button type="button" id="wphubpro-install-update" class="button button-primary">Nu installeren</button>
		</p>
	</div>
	<div id="wphubpro-not-connected" style="display:none">
		<p>Verbind deze site met uw dashboard.</p>
		<p style="color:#646970;font-size:13px">Geïnstalleerde bridge versie: <strong id="wphubpro-bridge-version-nc"><?php echo esc_html( $bridge_version ?: '—' ); ?></strong></p>
		<button id="wphubpro-btn" class="button button-primary">Nu Koppelen</button>
	</div>
	<div id="wphubpro-connected-card" style="display:none" class="wphubpro-connection-card">
		<h2>Verbindingsstatus</h2>
		<div class="wphubpro-card" style="max-width:600px;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin:16px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)">
			<table class="widefat striped" style="margin-bottom:16px">
				<tbody>
					<tr><th style="width:180px">Site ID</th><td id="wphubpro-site-id"><code style="font-size:12px">—</code></td></tr>
					<tr><th>Status</th><td id="wphubpro-status"><span id="wphubpro-heartbeat-bullet" class="wphubpro-ht-bullet" title="wphub_status" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle"></span><span id="wphubpro-status-text">—</span></td></tr>
					<tr><th>Bridge versie</th><td><span id="wphubpro-bridge-version">—</span> <button type="button" id="wphubpro-check-update" class="button button-small" style="padding:0 6px;margin-left:6px;vertical-align:middle" title="Controleren op updates" aria-label="Controleren op updates"><span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px"></span></button><span id="wphubpro-check-status" style="margin-left:6px;font-size:12px;color:#646970;display:none"></span></td></tr>
					<tr><th>Laatste heartbeat</th><td id="wphubpro-last-heartbeat-text">—</td></tr>
					<tr><th>Platform URL</th><td><span id="wphubpro-platform-url">—</span> <button type="button" id="wphubpro-edit-platform-url" class="button button-small" style="padding:0 6px;margin-left:6px;vertical-align:middle" title="Platform URL bewerken" aria-label="Platform URL bewerken"><span class="dashicons dashicons-edit" style="font-size:16px;width:16px;height:16px"></span></button></td></tr>
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
	var redirectSettingsUrl=<?php echo wp_json_encode( $redirect_settings_url ); ?>;
	var checkUpdateUrl=<?php echo wp_json_encode( $check_update_url ); ?>;
	var installUpdateUrl=<?php echo wp_json_encode( $install_update_url ); ?>;
	var nonce=<?php echo wp_json_encode( $nonce ); ?>;
	function req(u,o){o=o||{};o.headers=o.headers||{};o.headers['X-WP-Nonce']=nonce;if(o.body&&typeof o.body==='object'){o.headers['Content-Type']='application/json';o.body=JSON.stringify(o.body);}return fetch(u,o).then(function(r){return r.json();});}
	function fmt(d){if(!d)return'—';try{return new Date(d).toLocaleString('nl-NL',{dateStyle:'medium',timeStyle:'short'});}catch(e){return d;}}
	function load(){var ld=document.getElementById('wphubpro-status-loading'),nc=document.getElementById('wphubpro-not-connected'),card=document.getElementById('wphubpro-connected-card'),err=document.getElementById('wphubpro-status-error'),errMsg=document.getElementById('wphubpro-status-error-msg');
	ld.style.display='block';nc.style.display='none';card.style.display='none';err.style.display='none';
	req(statusUrl).then(function(d){ld.style.display='none';
	if(d.site_id){card.style.display='block';
	document.getElementById('wphubpro-site-id').innerHTML='<code style="font-size:12px">'+(d.site_id||'—').replace(/</g,'&lt;')+'</code>';
	var bullet=document.getElementById('wphubpro-heartbeat-bullet'),statusTxt=document.getElementById('wphubpro-status-text'),heartbeatTxt=document.getElementById('wphubpro-last-heartbeat-text'),versionEl=document.getElementById('wphubpro-bridge-version'),updateNotice=document.getElementById('wphubpro-update-notice'),latestVerEl=document.getElementById('wphubpro-latest-version');
	bullet.style.backgroundColor=d.connected?'#22c55e':'#ef4444';
	bullet.title=d.connected?'Verbonden':'Losgekoppeld';
	statusTxt.textContent=d.connected?'Verbonden':'Losgekoppeld';
	heartbeatTxt.textContent=fmt(d.last_heartbeat_at)||'—';
	versionEl.textContent=d.bridge_version||'—';
	if(d.update_available&&d.latest_version){updateNotice.style.display='block';latestVerEl.textContent=d.latest_version||'';}else{updateNotice.style.display='none';}
	}else{nc.style.display='block';
	var ncVer=document.getElementById('wphubpro-bridge-version-nc');if(ncVer)ncVer.textContent=d.bridge_version||'—';
	document.getElementById('wphubpro-update-notice').style.display='none';}}).catch(function(){ld.style.display='none';nc.style.display='block';});}
	load();
	document.getElementById('wphubpro-btn').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
	document.getElementById('wphubpro-reconnect').onclick=function(){req(connectUrl).then(function(d){if(d.redirect)window.location.href=d.redirect;});};
	document.getElementById('wphubpro-remove').onclick=function(){if(!confirm('Weet je zeker dat je deze site wilt verwijderen van de hub?'))return;req(disconnectUrl,{method:'POST'}).then(function(){window.location.reload();});};

	if(checkUpdateUrl){
		var checkBtn=document.getElementById('wphubpro-check-update');
		var checkStatus=document.getElementById('wphubpro-check-status');
		checkBtn.onclick=function(){
			checkBtn.disabled=true;
			checkStatus.style.display='inline';
			checkStatus.textContent='Controleren…';
			req(checkUpdateUrl,{method:'POST'}).then(function(d){
				checkBtn.disabled=false;
				if(d.success){
					checkStatus.textContent='Gereed. v'+d.latest_version;
					var updateNotice=document.getElementById('wphubpro-update-notice'),latestVerEl=document.getElementById('wphubpro-latest-version');
					if(d.update_available&&d.latest_version){updateNotice.style.display='block';latestVerEl.textContent=d.latest_version||'';}
					else{updateNotice.style.display='none';}
				}
				else{checkStatus.textContent='Fout: '+(d.message||'Onbekend');}
			}).catch(function(){checkBtn.disabled=false;checkStatus.textContent='Fout';});
		};
	}
	if(installUpdateUrl){
		document.getElementById('wphubpro-install-update').onclick=function(){
			var btn=this;
			btn.disabled=true;
			btn.textContent='Installeren…';
			req(installUpdateUrl,{method:'POST'}).then(function(d){
				if(d.success){window.location.reload();}
				else{alert(d.message||'Installatie mislukt');btn.disabled=false;btn.textContent='Nu installeren';}
			}).catch(function(){btn.disabled=false;btn.textContent='Nu installeren';});
		};
	}

	// Platform URL (redirect settings)
	if(redirectSettingsUrl){
		var platformUrlEl=document.getElementById('wphubpro-platform-url');
		var editBtn=document.getElementById('wphubpro-edit-platform-url');
		function loadPlatformUrl(){
			req(redirectSettingsUrl).then(function(d){
				var url=d.use_default?(d.default_url||'https://app.wphub.pro'):(d.custom_url||'');
				platformUrlEl.textContent=url||'—';
			});
		}
		loadPlatformUrl();
		editBtn.onclick=function(){
			req(redirectSettingsUrl).then(function(d){
				var defaultUrl=d.default_url||'https://app.wphub.pro';
				var current=d.use_default?'':(d.custom_url||'');
				var msg='Leeg laten voor standaard ('+defaultUrl+').';
				var val=prompt('Platform URL (redirect na koppelen):\n'+msg,current);
				if(val===null)return;
				val=val.trim();
				var body={use_default:val===''};
				if(val!==''){if(val.indexOf('https://')!==0){alert('URL moet met https:// beginnen.');return;}body.custom_url=val;}
				req(redirectSettingsUrl,{method:'POST',body:body}).then(function(res){
					if(res.success){loadPlatformUrl();}
					else if(res.message){alert(res.message);}
				}).catch(function(){});
			});
		};
	}
})();
</script>
