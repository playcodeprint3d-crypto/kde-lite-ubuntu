/**
 * Play Code - Codespaces, Linux Desktop & Antigravity AI Dashboard Script
 * Version: 1.2.0
 *
 * Handles both the "Mi Escritorio Linux" view (desktop) and the
 * "Build - Antigravity 2.0" view (build). Shared AJAX helpers are
 * scoped inside each initView() closure so they don't interfere.
 */
(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		if (document.getElementById('playcode-cs-app')) {
			initView('desktop', 'playcode-cs-');
		}
		if (document.getElementById('playcode-cs-build-app')) {
			initView('build', 'playcode-cs-build-');
		}
	});

	function initView(viewType, prefix) {
		const container = document.getElementById(prefix + 'app');
		if (!container) return;

		let pollTimer      = null;
		let currentCodespace = null;
		let autoOpenAgyPending = false;
		let countdownTimer = null;

		/** Shorthand to get an element by the prefixed ID (e.g. prefix + 'loading-bar') */
		const $ = function(idSuffix) {
			return document.getElementById(prefix + idSuffix);
		};

		bindEvents();

		const isConnected = container.getAttribute('data-connected') === '1';
		if (isConnected) {
			fetchStatus();
		}

		/* ------------------------------------------------------------------ */
		/* AJAX                                                                */
		/* ------------------------------------------------------------------ */

		function postAjax(action, data, callback) {
			if (!window.PlayCodeCS || !window.PlayCodeCS.ajax_url) {
				callback(new Error('PlayCodeCS no está configurado'), null);
				return;
			}
			const formData = new FormData();
			formData.append('action', action);
			formData.append('nonce', window.PlayCodeCS.nonce);
			for (const key in data) {
				if (Object.prototype.hasOwnProperty.call(data, key)) {
					formData.append(key, data[key]);
				}
			}
			fetch(window.PlayCodeCS.ajax_url, {
				method: 'POST',
				body:   formData
			})
			.then(response => response.json())
			.then(res  => callback(null, res))
			.catch(err => callback(err, null));
		}

		/* ------------------------------------------------------------------ */
		/* STATE HELPERS                                                       */
		/* ------------------------------------------------------------------ */

		function showLoadingState(msg) {
			const loader = $('loading-bar');
			if (loader) {
				loader.style.display = 'flex';
				const text = loader.querySelector('.playcode-cs-loading-text');
				if (text && msg) text.textContent = msg;
			}
		}

		function hideLoadingState() {
			const loader = $('loading-bar');
			if (loader) loader.style.display = 'none';
		}

		function showErrorState(msg) {
			hideLoadingState();
			const errBox = $('error-alert');
			if (errBox) {
				errBox.style.display = 'block';
				errBox.textContent = msg || 'Ocurrió un error al cargar el estado de Codespaces.';
			}
		}

		/* ------------------------------------------------------------------ */
		/* FETCH STATUS                                                        */
		/* ------------------------------------------------------------------ */

		function fetchStatus() {
			showLoadingState(
				viewType === 'build'
					? 'Consultando entorno de Build...'
					: 'Consultando estado de la máquina virtual en GitHub...'
			);
			postAjax('playcode_cs_get_status', {}, function(err, response) {
				if (err || !response || !response.success) {
					showErrorState(response ? response.data : 'Error de conexión con GitHub');
					return;
				}
				renderCodespaceData(response.data);
			});
		}

		/* ------------------------------------------------------------------ */
		/* RENDER                                                              */
		/* ------------------------------------------------------------------ */

		function renderCodespaceData(data) {
			const targetCs = data.codespace;
			currentCodespace = targetCs;

			const userAvatar = $('user-avatar');
			const userLogin  = $('user-login');
			if (userAvatar && data.user && data.user.avatar_url) userAvatar.src = data.user.avatar_url;
			if (userLogin  && data.user && data.user.login)       userLogin.textContent = '@' + data.user.login;

			const noCsBox    = $('no-codespace');
			const csDetailsBox = $('details');

			if (!targetCs) {
				if (noCsBox)     noCsBox.style.display = 'block';
				if (csDetailsBox) csDetailsBox.style.display = 'none';
				hideLoadingState();
				return;
			}

			if (noCsBox)     noCsBox.style.display = 'none';
			if (csDetailsBox) csDetailsBox.style.display = 'block';

			// Desktop-specific info fields
			const csName     = $('name');
			const csMachine  = $('machine');
			const csLastUsed = $('last-used');

			if (csName) csName.textContent = targetCs.name;
			if (csMachine && targetCs.machine) {
				const m = targetCs.machine;
				csMachine.textContent = `${m.display_name} (${m.cpus} CPUs, ${Math.round(m.memory_in_bytes / 1073741824)}GB RAM)`;
			}
			if (csLastUsed) {
				csLastUsed.textContent = targetCs.last_used_at
					? new Date(targetCs.last_used_at).toLocaleString()
					: 'Reciente';
			}

			updateStateUI(targetCs.state);
			hideLoadingState();

			// Build view: If codespace just became ready and Antigravity is pending → launch it
			const s = (targetCs.state || '').toLowerCase();
			if (autoOpenAgyPending && (s === 'available' || s === 'running')) {
				autoOpenAgyPending = false;
				launchAntigravity();
			}

			// Build view: Auto-launch Antigravity if already running on first load
			if (viewType === 'build' && (s === 'available' || s === 'running')) {
				const iframe = document.getElementById('playcode-agy-iframe');
				if (iframe && (iframe.src === 'about:blank' || !iframe.src)) {
					launchAntigravity();
				}
			}

			if (isTransientState(targetCs.state)) {
				startPolling();
			} else {
				stopPolling();
			}
		}

		function isTransientState(state) {
			const s = (state || '').toLowerCase();
			return s === 'starting' || s === 'queued' || s === 'awaiting'
				|| s === 'provisioning' || s === 'rebuilding';
		}

		/* ------------------------------------------------------------------ */
		/* COUNTDOWN TIMER (180s)                                             */
		/* ------------------------------------------------------------------ */

		function getCountdownStorageKey() {
			return currentCodespace && currentCodespace.name
				? 'playcode_cs_countdown_' + currentCodespace.name
				: null;
		}

		function getRemainingCountdown() {
			const key = getCountdownStorageKey();
			if (!key) return 0;
			try {
				const target = localStorage.getItem(key);
				if (!target) return 0;
				const remaining = Math.ceil((parseInt(target, 10) - Date.now()) / 1000);
				return remaining > 0 ? remaining : 0;
			} catch (e) {
				return 0;
			}
		}

		function startCountdown(seconds) {
			const key = getCountdownStorageKey();
			if (!key) return;
			try {
				const target = Date.now() + (seconds * 1000);
				localStorage.setItem(key, target.toString());
			} catch (e) {}
			runCountdown();
		}

		function clearCountdown() {
			if (countdownTimer) {
				clearInterval(countdownTimer);
				countdownTimer = null;
			}
			const key = getCountdownStorageKey();
			if (key) {
				try { localStorage.removeItem(key); } catch (e) {}
			}
			const btnOpenDesktop = $('btn-open-desktop');
			if (btnOpenDesktop) {
				btnOpenDesktop.classList.remove('is-waiting');
				btnOpenDesktop.innerHTML = '🌐 Abrir Máquina Virtual Linux (KDE)';
				btnOpenDesktop.removeAttribute('title');
			}
		}

		function runCountdown() {
			if (countdownTimer) clearInterval(countdownTimer);

			function tick() {
				const remaining = getRemainingCountdown();
				const btnOpenDesktop = $('btn-open-desktop');

				if (remaining > 0) {
					if (btnOpenDesktop) {
						btnOpenDesktop.style.display = 'inline-flex';
						btnOpenDesktop.classList.add('is-waiting');
						btnOpenDesktop.innerHTML = `⏳ Preparando Escritorio Linux (${remaining}s)...`;
						btnOpenDesktop.setAttribute(
							'title',
							`El escritorio KDE Plasma se está iniciando en la nube. Por favor espera ${remaining} segundos para ingresar.`
						);
					}
				} else {
					clearCountdown();
					if (btnOpenDesktop && currentCodespace) {
						btnOpenDesktop.classList.remove('is-waiting');
						btnOpenDesktop.innerHTML = '🌐 Abrir Máquina Virtual Linux (KDE)';
						btnOpenDesktop.removeAttribute('title');
						btnOpenDesktop.href = `https://${currentCodespace.name}-8080.app.github.dev/vnc.html?autoconnect=true&resize=remote`;
					}
				}
			}

			tick();
			countdownTimer = setInterval(tick, 1000);
		}

		function updateStateUI(state) {
			const badge          = $('state-badge');
			const btnStart       = $('btn-start');
			const btnStop        = $('btn-stop');
			const transientAlert = $('transient-alert');
			const s              = (state || '').toLowerCase();

			if (s === 'available' || s === 'running') {
				if (badge) {
					badge.className = 'playcode-cs-badge badge-green';
					badge.innerHTML = '<span class="playcode-cs-dot dot-green"></span> En ejecución';
				}
				if (btnStart) btnStart.style.display = 'none';
				if (btnStop)  btnStop.style.display  = 'inline-flex';
				if (transientAlert) transientAlert.style.display = 'none';

				// Desktop-only links
				const btnOpenDesktop = $('btn-open-desktop');
				const btnOpenVscode  = $('btn-open-vscode');
				if (btnOpenDesktop && currentCodespace) {
					btnOpenDesktop.style.display = 'inline-flex';
					btnOpenDesktop.href = `https://${currentCodespace.name}-8080.app.github.dev/vnc.html?autoconnect=true&resize=remote`;
					const rem = getRemainingCountdown();
					if (rem > 0) {
						runCountdown();
					} else {
						btnOpenDesktop.classList.remove('is-waiting');
						btnOpenDesktop.innerHTML = '🌐 Abrir Máquina Virtual Linux (KDE)';
						btnOpenDesktop.removeAttribute('title');
					}
				}
				if (btnOpenVscode && currentCodespace) {
					btnOpenVscode.href = currentCodespace.web_url || '#';
				}

				// Build: enable Invoke button
				const btnInvokeAgy = document.getElementById('playcode-cs-btn-invoke-agy');
				if (btnInvokeAgy) {
					btnInvokeAgy.disabled = false;
					btnInvokeAgy.classList.remove('is-disabled');
				}

			} else if (isTransientState(s)) {
				if (badge) {
					badge.className = 'playcode-cs-badge badge-yellow';
					badge.innerHTML = '<span class="playcode-cs-dot dot-yellow"></span> Iniciando...';
				}
				if (btnStart) btnStart.style.display = 'none';
				if (btnStop)  btnStop.style.display  = 'none';
				if (transientAlert) {
					transientAlert.style.display = 'block';
					transientAlert.innerHTML = viewType === 'build'
						? '<strong>⏳ Tu entorno de Build se está iniciando...</strong> Esto toma entre 10 y 20 segundos. Antigravity cargará automáticamente cuando esté listo.'
						: '<strong>⏳ Tu máquina virtual se está levantando...</strong> Se está configurando el escritorio KDE Plasma. El botón se habilitará automáticamente al finalizar el segundero.';
				}
				const btnOpenDesktop = $('btn-open-desktop');
				if (btnOpenDesktop) {
					if (viewType === 'desktop') {
						btnOpenDesktop.style.display = 'inline-flex';
						if (getRemainingCountdown() === 0) {
							startCountdown(180);
						} else {
							runCountdown();
						}
					} else {
						btnOpenDesktop.style.display = 'none';
					}
				}

			} else { // Stopped / Unknown
				if (badge) {
					badge.className = 'playcode-cs-badge badge-gray';
					badge.innerHTML = '<span class="playcode-cs-dot dot-gray"></span> Apagado';
				}
				if (btnStart) btnStart.style.display = 'inline-flex';
				if (btnStop)  btnStop.style.display  = 'none';
				if (transientAlert) transientAlert.style.display = 'none';

				const btnOpenDesktop = $('btn-open-desktop');
				if (btnOpenDesktop) {
					clearCountdown();
					btnOpenDesktop.style.display = 'none';
				}
			}
		}

		/* ------------------------------------------------------------------ */
		/* ACTIONS                                                             */
		/* ------------------------------------------------------------------ */

		function startCodespace(name) {
			updateStateUI('Starting');
			postAjax('playcode_cs_start', { name: name }, function(err, response) {
				if (err || !response || !response.success) {
					alert(response ? response.data : 'No se pudo iniciar el Codespace.');
					fetchStatus();
					return;
				}
				startPolling();
			});
		}

		function stopCodespace(name) {
			showLoadingState('Apagando máquina virtual...');
			clearCountdown();
			postAjax('playcode_cs_stop', { name: name }, function(err, response) {
				hideLoadingState();
				if (err || !response || !response.success) {
					alert(response ? response.data : 'No se pudo apagar el Codespace.');
				}
				fetchStatus();
			});
		}

		function saveToken(token) {
			showLoadingState('Guardando y validando token...');
			postAjax('playcode_cs_save_token', { token: token }, function(err, response) {
				hideLoadingState();
				if (err || !response || !response.success) {
					alert(response ? response.data : 'Token inválido o sin permisos de Codespaces.');
					return;
				}
				window.location.reload();
			});
		}

		function disconnectAccount() {
			showLoadingState('Desconectando...');
			postAjax('playcode_cs_disconnect', {}, function() {
				window.location.reload();
			});
		}

		/* ------------------------------------------------------------------ */
		/* POLLING                                                             */
		/* ------------------------------------------------------------------ */

		function startPolling() {
			if (pollTimer) clearInterval(pollTimer);
			pollTimer = setInterval(function() {
				postAjax('playcode_cs_get_status', {}, function(err, response) {
					if (response && response.success && response.data) {
						renderCodespaceData(response.data);
						if (!isTransientState(response.data.codespace ? response.data.codespace.state : '')) {
							stopPolling();
						}
					}
				});
			}, 3500);
		}

		function stopPolling() {
			if (pollTimer) {
				clearInterval(pollTimer);
				pollTimer = null;
			}
		}

		/* ------------------------------------------------------------------ */
		/* ANTIGRAVITY IFRAME LAUNCHER (Build only)                           */
		/* ------------------------------------------------------------------ */

		function launchAntigravity() {
			if (!currentCodespace || !currentCodespace.name) return;

			const agyUrl      = `https://${currentCodespace.name}-3000.app.github.dev/`;
			const agyContainer = document.getElementById('playcode-agy-container');
			const iframe      = document.getElementById('playcode-agy-iframe');
			const btnNewTab   = document.getElementById('playcode-agy-btn-newtab');
			const btnInvokeAgy = document.getElementById('playcode-cs-btn-invoke-agy');

			if (agyContainer) agyContainer.style.display = 'block';

			if (iframe && (iframe.src === 'about:blank' || !iframe.src ||
				iframe.src.indexOf('-3000.app.github.dev') === -1)) {
				iframe.src = agyUrl;
			}
			if (btnNewTab) btnNewTab.href = agyUrl;

			if (btnInvokeAgy) {
				btnInvokeAgy.innerHTML = '⚡ Antigravity Activo (Puerto 3000)';
				btnInvokeAgy.disabled  = false;
			}

			if (agyContainer) {
				agyContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		/* ------------------------------------------------------------------ */
		/* EVENT BINDINGS                                                      */
		/* ------------------------------------------------------------------ */

		function bindEvents() {
			// Token save form
			const tokenForm = $('token-form');
			if (tokenForm) {
				tokenForm.addEventListener('submit', function(e) {
					e.preventDefault();
					const tokenInput = $('token-input');
					if (!tokenInput || !tokenInput.value.trim()) return;
					saveToken(tokenInput.value.trim());
				});
			}

			// Disconnect
			const btnDisconnect = $('btn-disconnect');
			if (btnDisconnect) {
				btnDisconnect.addEventListener('click', function(e) {
					e.preventDefault();
					if (confirm('¿Estás seguro de desconectar tu cuenta de GitHub?')) {
						disconnectAccount();
					}
				});
			}

			// Desktop-only: prevent clicking while waiting / countdown active
			const btnOpenDesktop = $('btn-open-desktop');
			if (btnOpenDesktop) {
				btnOpenDesktop.addEventListener('click', function(e) {
					if (getRemainingCountdown() > 0 || btnOpenDesktop.classList.contains('is-waiting')) {
						e.preventDefault();
						e.stopPropagation();
						return false;
					}
				});
			}

			// Start
			const btnStart = $('btn-start');
			if (btnStart) {
				btnStart.addEventListener('click', function(e) {
					e.preventDefault();
					if (!currentCodespace || !currentCodespace.name) return;
					startCountdown(180);
					startCodespace(currentCodespace.name);
				});
			}

			// Stop
			const btnStop = $('btn-stop');
			if (btnStop) {
				btnStop.addEventListener('click', function(e) {
					e.preventDefault();
					if (!currentCodespace || !currentCodespace.name) return;
					if (confirm('¿Deseas apagar tu máquina virtual ahora? Puedes volver a encenderla en cualquier momento.')) {
						clearCountdown();
						stopCodespace(currentCodespace.name);
					}
				});
			}

			// Refresh
			const btnRefresh = $('btn-refresh');
			if (btnRefresh) {
				btnRefresh.addEventListener('click', function(e) {
					e.preventDefault();
					fetchStatus();
				});
			}

			// Create
			const btnCreate = $('btn-create');
			if (btnCreate) {
				btnCreate.addEventListener('click', function(e) {
					e.preventDefault();
					btnCreate.disabled = true;
					showLoadingState('Creando tu máquina virtual en GitHub Codespaces... por favor espera.');
					postAjax('playcode_cs_create', {}, function(err, response) {
						btnCreate.disabled = false;
						if (err || !response || !response.success) {
							hideLoadingState();
							alert(response ? response.data : 'Iniciando creación en GitHub...');
							window.open('https://codespaces.new/portadordelsello-stack/linux-kde-lite', '_blank');
							return;
						}
						if (response.data && response.data.name) {
							try {
								localStorage.setItem('playcode_cs_countdown_' + response.data.name, (Date.now() + 180000).toString());
							} catch (e) {}
						}
						fetchStatus();
						startPolling();
					});
				});
			}

			// Toggle manual token panel
			const btnToggleToken = $('toggle-token');
			const panelToken     = $('manual-token-panel');
			if (btnToggleToken && panelToken) {
				btnToggleToken.addEventListener('click', function(e) {
					e.preventDefault();
					panelToken.style.display =
						(panelToken.style.display === 'none' || !panelToken.style.display) ? 'block' : 'none';
				});
			}

			// BUILD: "Invocar a Antigravity" button
			const btnInvokeAgy = document.getElementById('playcode-cs-btn-invoke-agy');
			if (btnInvokeAgy) {
				btnInvokeAgy.addEventListener('click', function(e) {
					e.preventDefault();
					if (!currentCodespace || !currentCodespace.name) {
						alert('No se encontró ninguna máquina virtual activa. Por favor crea una primero.');
						return;
					}
					const s = (currentCodespace.state || '').toLowerCase();
					if (s === 'available' || s === 'running') {
						launchAntigravity();
					} else {
						// Machine is stopped/transient → start it, then auto-launch
						autoOpenAgyPending = true;
						startCodespace(currentCodespace.name);
					}
				});
			}

			// BUILD: Fullscreen toggle
			const btnFullscreen = document.getElementById('playcode-agy-btn-fullscreen');
			if (btnFullscreen) {
				btnFullscreen.addEventListener('click', function(e) {
					e.preventDefault();
					const agyContainer = document.getElementById('playcode-agy-container');
					if (agyContainer) {
						agyContainer.classList.toggle('playcode-agy-fullscreen');
						const isFs = agyContainer.classList.contains('playcode-agy-fullscreen');
						btnFullscreen.innerHTML = isFs ? '🗗 Salir de Pantalla Completa' : '🔲 Pantalla Completa';
					}
				});
			}

			// BUILD: Reload iframe
			const btnReload = document.getElementById('playcode-agy-btn-reload');
			if (btnReload) {
				btnReload.addEventListener('click', function(e) {
					e.preventDefault();
					const iframe = document.getElementById('playcode-agy-iframe');
					if (iframe && iframe.src && iframe.src !== 'about:blank') {
						const currentUrl = iframe.src;
						iframe.src = 'about:blank';
						setTimeout(function() {
							iframe.src = currentUrl;
						}, 150);
					}
				});
			}
		}
	}

	// Global ESC handler for Antigravity fullscreen
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			const agyContainer = document.getElementById('playcode-agy-container');
			if (agyContainer && agyContainer.classList.contains('playcode-agy-fullscreen')) {
				agyContainer.classList.remove('playcode-agy-fullscreen');
				const btnFullscreen = document.getElementById('playcode-agy-btn-fullscreen');
				if (btnFullscreen) {
					btnFullscreen.innerHTML = '🔲 Pantalla Completa';
				}
			}
		}
	});

})();
