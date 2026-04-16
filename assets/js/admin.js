/**
 * WPHubPro Bridge — wp-admin dashboard (connection status, updates, platform URL).
 */
(function () {
	'use strict';

	var cfg = typeof window.wphubproBridgeAdmin === 'object' && window.wphubproBridgeAdmin
		? window.wphubproBridgeAdmin
		: {};
	var nonce = cfg.nonce || '';
	var urls = cfg.urls || {};

	function req( u, o ) {
		o = o || {};
		o.headers = o.headers || {};
		o.headers[ 'X-WP-Nonce' ] = nonce;
		if ( o.body && typeof o.body === 'object' ) {
			o.headers[ 'Content-Type' ] = 'application/json';
			o.body = JSON.stringify( o.body );
		}
		return fetch( u, o ).then( function ( r ) {
			return r.json();
		} );
	}

	function fmt( d ) {
		if ( ! d ) {
			return '—';
		}
		try {
			return new Date( d ).toLocaleString( 'nl-NL', { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return d;
		}
	}

	function load() {
		var ld = document.getElementById( 'wphubpro-status-loading' );
		var nc = document.getElementById( 'wphubpro-not-connected' );
		var card = document.getElementById( 'wphubpro-connected-card' );
		var err = document.getElementById( 'wphubpro-status-error' );
		if ( ! ld || ! nc || ! card || ! err ) {
			return;
		}
		ld.style.display = 'block';
		nc.style.display = 'none';
		card.style.display = 'none';
		err.style.display = 'none';
		req( urls.status )
			.then( function ( d ) {
				ld.style.display = 'none';
				if ( d.site_id ) {
					card.style.display = 'block';
					document.getElementById( 'wphubpro-site-id' ).innerHTML =
						'<code class="small">' + String( d.site_id || '—' ).replace( /</g, '&lt;' ) + '</code>';
					var bullet = document.getElementById( 'wphubpro-heartbeat-bullet' );
					var statusTxt = document.getElementById( 'wphubpro-status-text' );
					var heartbeatTxt = document.getElementById( 'wphubpro-last-heartbeat-text' );
					var versionEl = document.getElementById( 'wphubpro-bridge-version' );
					var updateNotice = document.getElementById( 'wphubpro-update-notice' );
					var latestVerEl = document.getElementById( 'wphubpro-latest-version' );
					bullet.style.backgroundColor = d.connected ? '#22c55e' : '#ef4444';
					bullet.title = d.connected ? 'Verbonden' : 'Losgekoppeld';
					statusTxt.textContent = d.connected ? 'Verbonden' : 'Losgekoppeld';
					heartbeatTxt.textContent = fmt( d.last_heartbeat_at ) || '—';
					versionEl.textContent = d.bridge_version || '—';
					if ( d.update_available && d.latest_version ) {
						updateNotice.style.display = 'block';
						latestVerEl.textContent = d.latest_version || '';
					} else {
						updateNotice.style.display = 'none';
					}
				} else {
					nc.style.display = 'block';
					var ncVer = document.getElementById( 'wphubpro-bridge-version-nc' );
					if ( ncVer ) {
						ncVer.textContent = d.bridge_version || '—';
					}
					document.getElementById( 'wphubpro-update-notice' ).style.display = 'none';
				}
			} )
			.catch( function () {
				ld.style.display = 'none';
				nc.style.display = 'block';
			} );
	}

	function bind() {
		var connectUrl = urls.connect;
		var disconnectUrl = urls.disconnect;
		var checkUpdateUrl = urls.checkUpdate;
		var installUpdateUrl = urls.installUpdate;
		var redirectSettingsUrl = urls.redirectSettings;

		var btn = document.getElementById( 'wphubpro-btn' );
		if ( btn ) {
			btn.onclick = function () {
				var tab = window.open( 'about:blank', '_blank' );
				if ( tab ) {
					tab.opener = null;
				}
				req( connectUrl )
					.then( function ( d ) {
						if ( ! d.redirect ) {
							if ( tab ) {
								tab.close();
							}
							return;
						}
						if ( tab ) {
							tab.location = d.redirect;
						} else {
							window.location.href = d.redirect;
						}
					} )
					.catch( function () {
						if ( tab ) {
							tab.close();
						}
					} );
			};
		}

		var reconnect = document.getElementById( 'wphubpro-reconnect' );
		if ( reconnect ) {
			reconnect.onclick = function () {
				req( connectUrl ).then( function ( d ) {
					if ( d.redirect ) {
						window.location.href = d.redirect;
					}
				} );
			};
		}

		var removeBtn = document.getElementById( 'wphubpro-remove' );
		if ( removeBtn ) {
			removeBtn.onclick = function () {
				if ( ! window.confirm( cfg.i18n.confirmDisconnect ) ) {
					return;
				}
				req( disconnectUrl, { method: 'POST' } ).then( function () {
					window.location.reload();
				} );
			};
		}

		if ( checkUpdateUrl ) {
			var checkBtn = document.getElementById( 'wphubpro-check-update' );
			var checkStatus = document.getElementById( 'wphubpro-check-status' );
			if ( checkBtn && checkStatus ) {
				checkBtn.onclick = function () {
					checkBtn.disabled = true;
					checkStatus.style.display = 'inline';
					checkStatus.textContent = cfg.i18n.checking;
					req( checkUpdateUrl, { method: 'POST' } )
						.then( function ( d ) {
							checkBtn.disabled = false;
							if ( d.success ) {
								checkStatus.textContent = cfg.i18n.readyVersion + d.latest_version;
								var updateNotice = document.getElementById( 'wphubpro-update-notice' );
								var latestVerEl = document.getElementById( 'wphubpro-latest-version' );
								if ( d.update_available && d.latest_version ) {
									updateNotice.style.display = 'block';
									latestVerEl.textContent = d.latest_version || '';
								} else {
									updateNotice.style.display = 'none';
								}
							} else {
								checkStatus.textContent =
									cfg.i18n.errorWithMessage + ( d.message || cfg.i18n.unknown );
							}
						} )
						.catch( function () {
							checkBtn.disabled = false;
							checkStatus.textContent = cfg.i18n.errorShort;
						} );
				};
			}
		}

		if ( installUpdateUrl ) {
			var installBtn = document.getElementById( 'wphubpro-install-update' );
			if ( installBtn ) {
				installBtn.onclick = function () {
					var el = installBtn;
					el.disabled = true;
					el.textContent = cfg.i18n.installing;
					req( installUpdateUrl, { method: 'POST' } )
						.then( function ( d ) {
							if ( d.success ) {
								window.location.reload();
							} else {
								window.alert( d.message || cfg.i18n.installFailed );
								el.disabled = false;
								el.textContent = cfg.i18n.installButton;
							}
						} )
						.catch( function () {
							el.disabled = false;
							el.textContent = cfg.i18n.installButton;
						} );
				};
			}
		}

		if ( redirectSettingsUrl ) {
			var platformUrlEl = document.getElementById( 'wphubpro-platform-url' );
			var editBtn = document.getElementById( 'wphubpro-edit-platform-url' );
			if ( platformUrlEl && editBtn ) {
				function loadPlatformUrl() {
					req( redirectSettingsUrl ).then( function ( d ) {
						var url = d.use_default
							? d.default_url || 'https://app.wphub.pro'
							: d.custom_url || '';
						platformUrlEl.textContent = url || '—';
					} );
				}
				loadPlatformUrl();
				editBtn.onclick = function () {
					req( redirectSettingsUrl ).then( function ( d ) {
						var defaultUrl = d.default_url || 'https://app.wphub.pro';
						var current = d.use_default ? '' : d.custom_url || '';
						var msg = cfg.i18n.promptLeaveEmpty.replace( '%s', defaultUrl );
						var val = window.prompt( cfg.i18n.promptPlatformUrl + '\n' + msg, current );
						if ( val === null ) {
							return;
						}
						val = val.trim();
						var body = { use_default: val === '' };
						if ( val !== '' ) {
							if ( val.indexOf( 'https://' ) !== 0 ) {
								window.alert( cfg.i18n.urlMustHttps );
								return;
							}
							body.custom_url = val;
						}
						req( redirectSettingsUrl, { method: 'POST', body: body } )
							.then( function ( res ) {
								if ( res.success ) {
									loadPlatformUrl();
								} else if ( res.message ) {
									window.alert( res.message );
								}
							} )
							.catch( function () {} );
					} );
				};
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			load();
			bind();
		} );
	} else {
		load();
		bind();
	}
})();
