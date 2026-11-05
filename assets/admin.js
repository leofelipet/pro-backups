/* Pro Backups admin: chunked upload + job runner. No dependencies. */
(function () {
	'use strict';
	var t = PBK.i18n;
	var busy = false;

	function fmt(s, v) { return s.replace(/%[sd]/, v); }
	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function $(sel, root) { return (root || document).querySelector(sel); }

	/** POST to admin-ajax. Resolves with data, rejects with Error(message) (err.fatal = no point retrying). */
	function post(action, fields, file) {
		var body = fields instanceof FormData ? fields : new FormData();
		body.set('action', action);
		body.set('nonce', PBK.nonce);
		if (!(fields instanceof FormData)) {
			Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
		}
		if (file) { body.append('chunk', file, 'chunk'); }
		return fetch(PBK.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (res) {
			return res.text().then(function (text) {
				var json = null;
				try { json = JSON.parse(text.slice(text.indexOf('{'))); } catch (e) { /* not JSON: PHP fatal / proxy page */ }
				if (json && json.success) { return json.data; }
				var err = new Error(json && json.data && json.data.message ? json.data.message : 'HTTP ' + res.status);
				err.fatal = !!(json && json.data && json.data.message) && res.status < 500;
				throw err;
			});
		});
	}

	/** Retries network/server hiccups with backoff; server-reported errors are final. */
	function retry(fn, tries) {
		var attempt = 0;
		function go() {
			return fn().catch(function (err) {
				attempt++;
				if (err.fatal || attempt >= (tries || 8)) { throw err; }
				modal.message(fmt(t.retrying, attempt));
				return sleep(Math.min(30000, 1000 * Math.pow(2, attempt))).then(go);
			});
		}
		return go();
	}

	var modal = {
		el: function () { return $('#pbk-modal'); },
		open: function (title) {
			var el = this.el();
			el.hidden = false;
			$('#pbk-modal-title').textContent = title;
			this.message('');
			this.progress(null);
			this.form(null);
			this.actions([]);
			$('.pbk-log-wrap', el).hidden = true;
		},
		close: function () { this.el().hidden = true; },
		message: function (m) { $('.pbk-modal-message', this.el()).textContent = m || ''; },
		progress: function (p) {
			var box = $('.pbk-progress', this.el());
			box.hidden = p === null || p === undefined;
			if (!box.hidden) {
				$('.pbk-progress-bar', box).style.width = p + '%';
				$('.pbk-progress-text', box).textContent = Math.floor(p) + '%';
			}
		},
		log: function (lines) {
			var wrap = $('.pbk-log-wrap', this.el());
			wrap.hidden = !lines || !lines.length;
			$('.pbk-log', wrap).textContent = (lines || []).join('\n');
		},
		form: function (build) {
			var f = $('.pbk-modal-form', this.el());
			f.innerHTML = '';
			f.hidden = !build;
			if (build) { build(f); }
		},
		actions: function (list) {
			var box = $('.pbk-modal-actions', this.el());
			box.innerHTML = '';
			list.forEach(function (a) {
				var b = document.createElement(a.href ? 'a' : 'button');
				b.className = 'button' + (a.primary ? ' button-primary' : '');
				b.textContent = a.label;
				if (a.href) { b.href = a.href; } else { b.type = 'button'; b.addEventListener('click', a.click); }
				box.appendChild(b);
			});
			var first = box.querySelector('.button-primary, input');
			if (first) { first.focus(); }
		}
	};

	function closeAndReload() { modal.close(); location.reload(); }

	/** Waits for the user to answer a paused job. Resolves with the fields to send, or null (cancel). */
	function ask(status) {
		return new Promise(function (resolve) {
			modal.message(status.message);
			if (status.need === 'password') {
				modal.form(function (f) {
					f.innerHTML = '<label>' + t.password + ' <input type="password" class="regular-text" autocomplete="off" required></label>';
					f.onsubmit = function (e) { e.preventDefault(); resolve({ password: $('input', f).value }); };
				});
				modal.actions([
					{ label: t.cancel, click: function () { resolve(null); } },
					{ label: t.unlock, primary: true, click: function () { resolve({ password: $('.pbk-modal-form input').value }); } }
				]);
				$('.pbk-modal-form input').focus();
			} else {
				modal.actions([
					{ label: t.cancel, click: function () { resolve(null); } },
					{ label: t.confirm, primary: true, click: function () { resolve({ confirm: 1 }); } }
				]);
			}
		});
	}

	/** Drives a job until done / error, answering prompts. */
	function runJob(job) {
		busy = true;
		var auth = { id: job.id, token: job.token };
		function render(s) {
			modal.message(s.message);
			modal.progress(s.progress);
			modal.log(s.log);
		}
		function loop(s) {
			render(s);
			if (s.status === 'running') {
				return retry(function () { return post('pbk_run', auth); }, 10).then(loop);
			}
			if (s.status === 'input') {
				return ask(s).then(function (answer) {
					modal.form(null);
					modal.actions([]);
					if (!answer) {
						return post('pbk_cancel', auth).then(finish, finish);
					}
					return post('pbk_input', Object.assign({}, auth, answer)).then(loop);
				});
			}
			return finish(s);
		}
		function finish(s) {
			busy = false;
			render(s);
			var acts = [];
			if (s.status === 'done' && s.result && s.result.login_url) {
				acts.push({ label: t.login, primary: true, href: s.result.login_url });
			} else if (s.status === 'done' && s.result && s.result.download_url) {
				acts.push({ label: t.download, primary: true, href: s.result.download_url });
				acts.push({ label: t.close, click: closeAndReload });
			} else {
				acts.push({ label: t.close, primary: s.status !== 'error', click: closeAndReload });
			}
			modal.actions(acts);
			if (s.status === 'error') { modal.message(t.error + ': ' + s.message); }
		}
		return loop(job).catch(function (err) {
			busy = false;
			modal.progress(null);
			modal.message(t.error + ': ' + err.message);
			modal.actions([{ label: t.close, click: closeAndReload }]);
		});
	}

	function startRestore(file) {
		modal.open(t.confirm + ': ' + file);
		return post('pbk_restore_start', { file: file }).then(runJob, function (err) {
			modal.message(t.error + ': ' + err.message);
			modal.actions([{ label: t.close, click: closeAndReload }]);
		});
	}

	function upload(file) {
		if (!/\.wpress$/i.test(file.name)) { window.alert(t.notWpress); return; }
		if (PBK.free !== null && file.size > PBK.free) { window.alert(t.noSpace); return; }
		busy = true;
		modal.open(fmt(t.uploading, file.name));
		modal.progress(0);
		post('pbk_upload_start', { name: file.name, size: file.size }).then(function (u) {
			var size = u.chunk_size;
			function next(state) {
				modal.progress(100 * state.offset / file.size);
				modal.message(fmt(t.uploading, file.name));
				if (state.done) { return u.file; }
				var blob = file.slice(state.offset, Math.min(file.size, state.offset + size));
				return retry(function () {
					return post('pbk_upload_chunk', { upload_id: u.upload_id, offset: state.offset }, blob).catch(function (err) {
						// Lost track (e.g. response dropped after write): ask the server where we are.
						return post('pbk_upload_status', { upload_id: u.upload_id }).then(function (s) {
							if (s.offset !== state.offset) { return s; }
							throw err;
						});
					});
				}, 12).then(next);
			}
			return next({ offset: 0, done: false });
		}).then(function (name) {
			busy = false;
			return startRestore(name);
		}, function (err) {
			busy = false;
			modal.progress(null);
			modal.message(fmt(t.uploadFailed, err.message));
			modal.actions([{ label: t.close, click: closeAndReload }]);
		});
	}

	window.addEventListener('beforeunload', function (e) {
		if (busy) { e.preventDefault(); e.returnValue = t.leave; return t.leave; }
	});

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.pbk-restore');
		if (btn) { startRestore(btn.getAttribute('data-file')); }
		var del = e.target.closest('.pbk-delete');
		if (del && !window.confirm(t.deleteAsk)) { e.preventDefault(); }
	});

	var input = $('#pbk-file');
	if (input) {
		input.addEventListener('change', function () { if (input.files[0]) { upload(input.files[0]); } input.value = ''; });
	}
	var drop = $('#pbk-drop');
	if (drop) {
		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('pbk-over'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('pbk-over'); });
		});
		drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) { upload(e.dataTransfer.files[0]); } });
	}

	var exportForm = $('#pbk-export-form');
	if (exportForm) {
		exportForm.addEventListener('submit', function (e) {
			e.preventDefault();
			window.PBKRunJob(t.creating, 'pbk_export_start', new FormData(exportForm));
		});
	}

	// Other screens start jobs through this hook.
	window.PBKRunJob = function (title, action, fields) {
		modal.open(title);
		return post(action, fields).then(runJob, function (err) {
			modal.message(t.error + ': ' + err.message);
			modal.actions([{ label: t.close, click: closeAndReload }]);
		});
	};
})();
