/**
 * Migrator admin UI — chunked AJAX state machine for export and import.
 */
(function () {
	'use strict';

	const cfg = window.MigratorConfig || {};
	// Fall back to WordPress's global ajaxurl if our localized config is missing it.
	if (!cfg.ajaxUrl) {
		cfg.ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
	}
	if (!cfg.pollMs) cfg.pollMs = 250;
	if (!cfg.chunkBytes) cfg.chunkBytes = 1048576; // 1 MB fallback if server didn't tell us.
	if (!cfg.i18n) cfg.i18n = {};

	console.log('[Migrator] script loaded. ajaxUrl =', cfg.ajaxUrl,
		' chunkBytes =', cfg.chunkBytes,
		' postMax =', cfg.postMax,
		' uploadMax =', cfg.uploadMax,
		' nonce set:', !!cfg.nonce);

	// Surface anything that escapes our try/catch blocks — usually a third-party
	// admin script or an unhandled async rejection on this page.
	window.addEventListener('error', (event) => {
		if (event && (event.filename || '').indexOf('migrator') !== -1) {
			console.error('[Migrator window.error]', event.message, event.error || '', event.filename + ':' + event.lineno + ':' + event.colno);
		}
	});
	window.addEventListener('unhandledrejection', (event) => {
		console.error('[Migrator unhandledrejection]', event.reason);
	});

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}

	function ajaxFetch(action, body) {
		console.log('[Migrator] →', action, cfg.ajaxUrl);
		let response;
		try {
			response = fetch(cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
		} catch (syncErr) {
			// Some browsers throw synchronously from fetch() if the URL is malformed.
			console.error('[Migrator] fetch threw synchronously for', action, 'url=', cfg.ajaxUrl, syncErr);
			return Promise.reject(new Error('Network error (' + action + '): ' + syncErr.message));
		}
		return response
			.catch((networkErr) => {
				console.error('[Migrator] network error for', action, 'url=', cfg.ajaxUrl, networkErr);
				throw new Error('Network error (' + action + '): ' + networkErr.message);
			})
			.then((r) => {
				console.log('[Migrator] ← ' + action, 'HTTP', r.status, r.headers.get('content-type'));
				return r.text().then((text) => {
					try {
						return JSON.parse(text);
					} catch (parseErr) {
						console.error('[Migrator] JSON parse failed for', action, 'HTTP', r.status, 'body was:', text.slice(0, 500), parseErr);
						// Detect the most common causes of an HTML response.
						if (r.status === 413 || /413 Request Entity Too Large|client intended to send too large body/i.test(text)) {
							throw new Error(cfg.i18n.nginxTooLarge || 'Nginx rejected the chunk: Request Entity Too Large.');
						}
						if (/POST Content-Length of \d+ bytes exceeds the limit/i.test(text)) {
							throw new Error(cfg.i18n.postTooLarge || 'Upload chunk exceeds PHP post_max_size.');
						}
						throw new Error('Invalid server response (' + action + '): ' + parseErr.message);
					}
				});
			})
			.then((json) => {
				if (!json || !json.success) {
					const msg = (json && json.data && json.data.message) || cfg.i18n.failed || 'Request failed';
					const err = new Error(msg);
					err.snapshot = json && json.data && json.data.snapshot;
					throw err;
				}
				return json.data;
			});
	}

	function postForm(action, params) {
		const body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce);
		Object.entries(params || {}).forEach(([k, v]) => {
			if (Array.isArray(v)) {
				v.forEach((item) => body.append(k + '[]', item));
			} else if (v !== undefined && v !== null) {
				body.append(k, v);
			}
		});
		return ajaxFetch(action, body);
	}

	function postChunk(action, params, fileBlob) {
		const body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce);
		Object.entries(params || {}).forEach(([k, v]) => body.append(k, v));
		// Third argument supplies a filename so $_FILES['chunk']['name'] is set on every host.
		body.append('chunk', fileBlob, 'chunk.bin');
		return ajaxFetch(action, body);
	}

	function setProgress(scope, snapshot) {
		const pct = Math.round((snapshot.overall_progress || 0) * 100);
		$('.migrator-progress-bar__fill', scope).style.width = pct + '%';
		$('.migrator-progress-percent', scope).textContent = pct + '%';
		$('.migrator-progress-label', scope).textContent = snapshot.label || '';
		$('.migrator-progress-phase', scope).textContent = snapshot.phase ? 'Phase: ' + snapshot.phase : '';
	}

	function showError(errorEl, message) {
		$('.migrator-error-message', errorEl).textContent = message;
		errorEl.hidden = false;
	}

	function delay(ms) {
		return new Promise((r) => setTimeout(r, ms));
	}

	// ---------- Export ----------
	function initExport() {
		const form = $('#migrator-export-form');
		if (!form) return;

		const progressEl = $('#migrator-export-progress');
		const doneEl = $('#migrator-export-done');
		const errorEl = $('#migrator-export-error');
		const cancelBtn = $('.migrator-cancel', progressEl);
		const downloadBtn = $('.migrator-download', doneEl);

		let activeJobId = null;
		let cancelled = false;

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			errorEl.hidden = true;
			doneEl.hidden = true;

			const params = collectExportForm(form);
			if (!params.include_database && !params.include_uploads && !params.include_themes && !params.include_plugins && !params.include_mu_plugins) {
				showError(errorEl, cfg.i18n.noInclusion);
				return;
			}

			form.hidden = true;
			progressEl.hidden = false;
			setProgress(progressEl, { overall_progress: 0, label: cfg.i18n.starting, phase: 'init' });
			cancelled = false;

			try {
				const startData = await postForm('migrator_export_start', params);
				activeJobId = startData.job_id;
				setProgress(progressEl, startData.snapshot);

				while (!cancelled) {
					const snap = await postForm('migrator_export_step', { job_id: activeJobId });
					setProgress(progressEl, snap);
					if (snap.phase === 'done') {
						progressEl.hidden = true;
						downloadBtn.href = snap.download_url;
						doneEl.hidden = false;
						return;
					}
					if (snap.phase === 'error') {
						throw new Error(snap.label);
					}
					await delay(cfg.pollMs);
				}
			} catch (err) {
				console.error('[Migrator export]', err);
				if (!cancelled) {
					progressEl.hidden = true;
					form.hidden = false;
					showError(errorEl, err.message);
				}
			}
		});

		cancelBtn.addEventListener('click', async () => {
			if (!confirm(cfg.i18n.confirmCancel)) return;
			cancelled = true;
			if (activeJobId) {
				try {
					await postForm('migrator_export_cancel', { job_id: activeJobId });
				} catch (e) { /* ignore */ }
			}
			progressEl.hidden = true;
			form.hidden = false;
		});
	}

	function collectExportForm(form) {
		const params = {};
		const checkboxes = [
			'include_database', 'include_uploads', 'include_themes', 'include_plugins', 'include_mu_plugins',
			'db_skip_spam', 'db_skip_revisions', 'db_skip_trash', 'db_skip_transients',
		];
		checkboxes.forEach((name) => {
			const cb = form.querySelector(`[name="${name}"]`);
			if (cb && cb.checked) params[name] = '1';
		});
		const ta = form.querySelector('[name="file_excludes"]');
		if (ta && ta.value.trim()) params.file_excludes = ta.value;
		return params;
	}

	// ---------- Import ----------
	function initImport() {
		const form = $('#migrator-import-form');
		if (!form) return;

		const progressEl = $('#migrator-import-progress');
		const doneEl = $('#migrator-import-done');
		const errorEl = $('#migrator-import-error');
		const cancelBtn = $('.migrator-cancel', progressEl);

		let activeJobId = null;
		let cancelled = false;

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			errorEl.hidden = true;

			const incomingSel = $('#migrator_incoming', form);
			const incoming = incomingSel ? incomingSel.value : '';
			const fileInput = $('#migrator_archive', form);
			const file = fileInput.files[0];

			if (!incoming && !file) {
				showError(errorEl, cfg.i18n.noFile || 'Please choose an archive to import.');
				return;
			}
			if (!confirm(cfg.i18n.confirmImport)) return;

			const newUrl = ($('#migrator_new_url', form).value || '').trim() || window.location.origin;

			form.hidden = true;
			progressEl.hidden = false;
			setProgress(progressEl, { overall_progress: 0, label: cfg.i18n.starting, phase: 'upload' });
			cancelled = false;

			try {
				const startData = await postForm('migrator_import_start', {
					new_url: newUrl,
					incoming_file: incoming,
				});
				activeJobId = startData.job_id;

				if (startData.source === 'upload' && file) {
					// Chunked upload from the browser. Chunk size is derived
					// server-side and capped to fit nginx client_max_body_size.
					const chunkSize = cfg.chunkBytes;
					const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
					for (let i = 0; i < totalChunks; i++) {
						if (cancelled) return;
						const start = i * chunkSize;
						const end = Math.min(file.size, start + chunkSize);
						const slice = file.slice(start, end);
						const data = await postChunk('migrator_import_upload', {
							job_id: activeJobId,
							chunk_index: i,
							total_chunks: totalChunks,
						}, slice);
						setProgress(progressEl, data.snapshot);
					}
				}
				// For 'incoming' source the archive is already on disk —
				// skip the chunked upload loop entirely.

				// Step through remaining phases.
				while (!cancelled) {
					const snap = await postForm('migrator_import_step', { job_id: activeJobId });
					setProgress(progressEl, snap);
					if (snap.phase === 'done') {
						progressEl.hidden = true;
						doneEl.hidden = false;
						return;
					}
					if (snap.phase === 'error') {
						throw new Error(snap.label);
					}
					await delay(cfg.pollMs);
				}
			} catch (err) {
				console.error('[Migrator import]', err);
				if (!cancelled) {
					progressEl.hidden = true;
					form.hidden = false;
					showError(errorEl, err.message);
				}
			}
		});

		cancelBtn.addEventListener('click', async () => {
			if (!confirm(cfg.i18n.confirmCancel)) return;
			cancelled = true;
			if (activeJobId) {
				try {
					await postForm('migrator_import_cancel', { job_id: activeJobId });
				} catch (e) { /* ignore */ }
			}
			progressEl.hidden = true;
			form.hidden = false;
		});
	}

	document.addEventListener('DOMContentLoaded', () => {
		initExport();
		initImport();
	});
})();
