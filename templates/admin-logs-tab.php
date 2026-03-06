<?php
/**
 * Admin logs tab – last 20 API calls to wphubpro/v1 in a styled table.
 *
 * Expects: $status_url, $nonce
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wphubpro-tab-content wphubpro-logs-tab" style="margin-top:1em">
	<h2>API-log</h2>
	<p class="description">Laatste 20 aanroepen naar het Bridge REST API-endpoint (<code>wphubpro/v1</code>).</p>
	<div id="wphubpro-logs-loading" class="wphubpro-logs-loading"><p>Logs laden…</p></div>
	<div id="wphubpro-logs-empty" class="wphubpro-logs-empty" style="display:none">
		<p>Geen API-aanroepen gelogd.</p>
	</div>
	<div id="wphubpro-logs-table-wrap" class="wphubpro-logs-table-wrap" style="display:none">
		<table id="wphubpro-logs-table" class="wphubpro-logs-table widefat striped">
			<thead>
				<tr>
					<th class="wphubpro-log-time">Tijd</th>
					<th class="wphubpro-log-endpoint">Endpoint</th>
					<th class="wphubpro-log-type">Type</th>
					<th class="wphubpro-log-code">Code</th>
					<th class="wphubpro-log-request">Request</th>
					<th class="wphubpro-log-response">Response</th>
				</tr>
			</thead>
			<tbody id="wphubpro-logs-tbody">
			</tbody>
		</table>
	</div>
</div>
<script>
(function(){
	var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
	var nonce = <?php echo wp_json_encode( $nonce ); ?>;
	function req(u, o) {
		o = o || {};
		o.headers = o.headers || {};
		o.headers['X-WP-Nonce'] = nonce;
		return fetch(u, o).then(function(r) { return r.json(); });
	}
	function fmt(d) {
		if (!d) return '—';
		try { return new Date(d).toLocaleString('nl-NL', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d; }
	}
	function prettyJson(v) {
		if (v === null || v === undefined) return '';
		if (typeof v === 'string') return v;
		try { return JSON.stringify(v, null, 2); } catch (x) { return String(v); }
	}
	function esc(v) {
		return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	var loading = document.getElementById('wphubpro-logs-loading');
	var empty = document.getElementById('wphubpro-logs-empty');
	var wrap = document.getElementById('wphubpro-logs-table-wrap');
	var tbody = document.getElementById('wphubpro-logs-tbody');
	req(statusUrl).then(function(d) {
		loading.style.display = 'none';
		var log = d.api_log;
		if (!log || !log.length) {
			empty.style.display = 'block';
			return;
		}
		wrap.style.display = 'block';
		var html = '';
		log.forEach(function(e) {
			var code = e.code;
			var codeClass = (code >= 200 && code < 300) ? 'wphubpro-code-ok' : (code >= 400 ? 'wphubpro-code-err' : '');
			var reqJson = esc(prettyJson(e.request));
			var resJson = esc(prettyJson(e.response));
			html += '<tr class="wphubpro-log-main-row">';
			html += '<td class="wphubpro-log-time">' + fmt(e.time) + '</td>';
			html += '<td class="wphubpro-log-endpoint"><code>' + esc(e.endpoint || '') + '</code></td>';
			html += '<td class="wphubpro-log-type">' + esc(e.type || '') + '</td>';
			html += '<td class="wphubpro-log-code ' + codeClass + '">' + esc(String(code || '')) + '</td>';
			html += '<td class="wphubpro-log-request"><button type="button" class="wphubpro-log-toggle" data-panel="request">Request</button></td>';
			html += '<td class="wphubpro-log-response"><button type="button" class="wphubpro-log-toggle" data-panel="response">Response</button></td>';
			html += '</tr>';
			html += '<tr class="wphubpro-log-detail-row"><td colspan="6" class="wphubpro-log-detail-cell">';
			html += '<div class="wphubpro-log-detail">';
			html += '<div class="wphubpro-request-panel wphubpro-panel" style="display:none"><strong>Request</strong><pre class="wphubpro-log-pre">' + reqJson + '</pre></div>';
			html += '<div class="wphubpro-response-panel wphubpro-panel" style="display:none"><strong>Response</strong><pre class="wphubpro-log-pre">' + resJson + '</pre></div>';
			html += '</div></td></tr>';
		});
		tbody.innerHTML = html;
		tbody.addEventListener('click', function(ev) {
			var btn = ev.target.closest('.wphubpro-log-toggle');
			if (!btn) return;
			var mainRow = btn.closest('.wphubpro-log-main-row');
			var detailRow = mainRow.nextElementSibling;
			if (!detailRow || !detailRow.classList.contains('wphubpro-log-detail-row')) return;
			var panel = detailRow.querySelector('.wphubpro-' + btn.getAttribute('data-panel') + '-panel');
			if (!panel) return;
			var isHidden = panel.style.display === 'none';
			panel.style.display = isHidden ? 'block' : 'none';
			btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
		});
	}).catch(function() {
		loading.style.display = 'none';
		empty.style.display = 'block';
		empty.innerHTML = '<p>Kon logs niet laden.</p>';
	});
})();
</script>
