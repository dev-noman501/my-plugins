/**
 * AI Support Chat widget — self-contained (styles injected by JS).
 * On WordPress it reads window.ASC_CONFIG (set by wp_localize_script).
 * On Next.js it reads data-api from its own <script> tag and fetches the
 * appearance config from GET {api}/config, so dashboard settings apply
 * everywhere.
 */
(function () {
	'use strict';

	if (window.ASC_CONFIG) {
		boot(window.ASC_CONFIG);
		return;
	}
	var tag = document.currentScript;
	var api = tag && tag.getAttribute('data-api');
	if (!api) return;
	api = api.replace(/\/$/, '');
	fetch(api + '/config')
		.then(function (r) { return r.json(); })
		.then(function (cfg) { cfg.apiBase = api; boot(cfg); })
		.catch(function () {
			boot({ apiBase: api, title: (tag && tag.getAttribute('data-name')) || 'Support' });
		});

	function boot(cfg) {
		var apiBase = (cfg.apiBase || '').replace(/\/$/, '');
		if (!apiBase) return;

		var C = /^#[0-9a-fA-F]{6}$/.test(cfg.color || '') ? cfg.color : '#2271b1';
		var title = cfg.title || 'Support';
		var greeting = cfg.greeting || ('Hi! Ask me anything about ' + title + '.');
		var side = cfg.position === 'left' ? 'left' : 'right';
		var font = (cfg.font ? cfg.font + ',' : '') + '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';

		// Darken the primary color for gradients.
		function shade(hex, f) {
			var n = parseInt(hex.slice(1), 16);
			var r = Math.min(255, Math.round(((n >> 16) & 255) * f));
			var g = Math.min(255, Math.round(((n >> 8) & 255) * f));
			var b = Math.min(255, Math.round((n & 255) * f));
			return 'rgb(' + r + ',' + g + ',' + b + ')';
		}
		var CD = shade(C, 0.72);
		var grad = 'linear-gradient(135deg,' + C + ',' + CD + ')';

		// Session ids must be unguessable — another visitor's transcript can be
		// attached to a ticket via /handoff if their id is guessed.
		function makeSid() {
			var rnd = '';
			if (window.crypto && crypto.getRandomValues) {
				var buf = new Uint32Array(4);
				crypto.getRandomValues(buf);
				for (var i = 0; i < buf.length; i++) rnd += buf[i].toString(36);
			} else {
				rnd = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
			}
			return 's_' + rnd + Date.now().toString(36);
		}

		var sid;
		try {
			sid = localStorage.getItem('asc_sid');
			if (!sid) {
				sid = makeSid();
				localStorage.setItem('asc_sid', sid);
			}
		} catch (e) {
			sid = makeSid();
		}

		var css = ''
			// Reset everything so the host page's theme CSS can't bleed in
			// (host page selectors can't reach inside the shadow root at all;
			// these resets also stop inherited properties).
			+ ':host{all:initial;}'
			+ '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}'
			+ 'button{-webkit-appearance:none;appearance:none;font-family:inherit;}'
			+ 'textarea,input{-webkit-appearance:none;appearance:none;}'
			+ '.asc-bubble{position:fixed;' + side + ':22px;bottom:22px;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:' + grad + ';color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.28);z-index:99998;display:flex;align-items:center;justify-content:center;transition:transform .2s ease;line-height:1;padding:0;}'
			+ '.asc-bubble:hover{transform:scale(1.08);}'
			+ '.asc-bubble svg{width:28px;height:28px;}'
			+ '.asc-panel{position:fixed;' + side + ':22px;bottom:94px;width:372px;max-width:calc(100vw - 32px);height:560px;max-height:calc(100vh - 130px);background:#fff;border-radius:18px;box-shadow:0 16px 48px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;z-index:99999;font-family:' + font + ';font-size:14px;color:#1d2327;opacity:0;transform:translateY(14px) scale(.97);pointer-events:none;transition:opacity .22s ease,transform .22s ease;}'
			+ '.asc-panel.asc-open{opacity:1;transform:none;pointer-events:auto;}'
			+ '.asc-head{background:' + grad + ';color:#fff;padding:14px 16px;display:flex;align-items:center;gap:11px;}'
			+ '.asc-ava{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:21px;flex-shrink:0;}'
			+ '.asc-head-info{min-width:0;}'
			+ '.asc-head-info b{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
			+ '.asc-status{font-size:11px;opacity:.9;display:flex;align-items:center;gap:5px;margin-top:2px;}'
			+ '.asc-status i{width:7px;height:7px;background:#4ade80;border-radius:50%;display:inline-block;}'
			+ '.asc-head-btns{margin-left:auto;display:flex;}'
			+ '.asc-head-btns button{background:none;border:0;color:#fff;font-size:19px;cursor:pointer;line-height:1;margin-left:12px;opacity:.85;padding:2px;}'
			+ '.asc-head-btns button:hover{opacity:1;}'
			+ '.asc-msgs{flex:1;overflow-y:auto;padding:14px;background:#f5f7fa;display:flex;flex-direction:column;}'
			+ '.asc-row{display:flex;gap:8px;margin:5px 0;align-items:flex-end;}'
			+ '.asc-row.asc-user{justify-content:flex-end;}'
			+ '.asc-mini{width:26px;height:26px;border-radius:50%;background:#fff;border:1px solid #e4e7eb;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}'
			+ '.asc-msg{max-width:78%;padding:10px 14px;border-radius:16px;white-space:pre-wrap;word-wrap:break-word;line-height:1.5;font-size:13.5px;}'
			+ '.asc-user .asc-msg{background:' + grad + ';color:#fff;border-bottom-right-radius:5px;}'
			+ '.asc-bot .asc-msg{background:#fff;border:1px solid #e9ecef;box-shadow:0 1px 2px rgba(0,0,0,.04);border-bottom-left-radius:5px;}'
			+ '.asc-dots{display:flex;gap:4px;padding:13px 14px;}'
			+ '.asc-dots span{width:7px;height:7px;border-radius:50%;background:#b6bcc4;animation:ascB 1.2s infinite;}'
			+ '.asc-dots span:nth-child(2){animation-delay:.15s;}'
			+ '.asc-dots span:nth-child(3){animation-delay:.3s;}'
			+ '@keyframes ascB{0%,60%,100%{transform:translateY(0);opacity:.5;}30%{transform:translateY(-5px);opacity:1;}}'
			+ '.asc-cta{align-self:flex-start;margin:8px 0 4px 34px;padding:9px 16px;border-radius:18px;border:1.5px solid ' + C + ';color:' + C + ';background:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;}'
			+ '.asc-cta:hover{background:#f0f6fc;}'
			+ '.asc-form{padding:12px 14px;background:#fff;border-top:1px solid #eef0f3;}'
			+ '.asc-form-head{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:600;margin-bottom:6px;}'
			+ '.asc-form-head button{background:none;border:0;color:#8c8f94;font-size:17px;cursor:pointer;line-height:1;}'
			+ '.asc-form input,.asc-form textarea{width:100%;box-sizing:border-box;margin:4px 0;padding:9px 12px;border:1px solid #e2e6ea;border-radius:10px;font-family:inherit;font-size:13.5px;outline:none;transition:border-color .15s;}'
			+ '.asc-form input:focus,.asc-form textarea:focus{border-color:' + C + ';}'
			+ '.asc-form-send{width:100%;margin-top:7px;padding:11px;border:0;border-radius:10px;background:' + grad + ';color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;}'
			+ '.asc-form-send:disabled{opacity:.6;}'
			+ '.asc-foot{display:flex;gap:8px;align-items:flex-end;padding:10px 12px;background:#fff;border-top:1px solid #eef0f3;}'
			+ '.asc-foot textarea{flex:1;border:1px solid #e2e6ea;border-radius:22px;padding:10px 15px;resize:none;font-family:inherit;font-size:13.5px;outline:none;height:40px;max-height:90px;box-sizing:border-box;transition:border-color .15s;}'
			+ '.asc-foot textarea:focus{border-color:' + C + ';}'
			+ '.asc-send{width:40px;height:40px;border-radius:50%;border:0;background:' + grad + ';color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .15s;}'
			+ '.asc-send:hover{transform:scale(1.06);}'
			+ '.asc-send:disabled{opacity:.6;transform:none;}'
			+ '.asc-send svg{width:17px;height:17px;margin-left:2px;}'
			+ '.asc-team{text-align:center;padding:7px;background:#fff;}'
			+ '.asc-team a{color:#8c8f94;font-size:11.5px;cursor:pointer;text-decoration:none;font-family:inherit;}'
			+ '.asc-team a:hover{color:' + C + ';text-decoration:underline;}';

		// Shadow DOM = full CSS isolation: the site theme cannot break the
		// widget, and widget styles never leak onto the site.
		var host = document.createElement('div');
		host.id = 'asc-widget';
		document.body.appendChild(host);
		var mount = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : host;

		var style = document.createElement('style');
		style.textContent = css;
		mount.appendChild(style);

		var chatIcon = '<svg viewBox="0 0 24 24" fill="none"><path d="M12 3C7 3 3 6.6 3 11c0 2.2 1 4.2 2.7 5.6-.1.9-.5 2.2-1.5 3.2 0 0 2.4-.1 4.3-1.3.1-.1.3-.1.4-.1.9.3 2 .5 3.1.5 5 0 9-3.6 9-8s-4-8-9-8z" fill="currentColor"/></svg>';
		var sendIcon = '<svg viewBox="0 0 24 24" fill="none"><path d="M3 20.5l19-8.5L3 3.5V10l13 2-13 2v6.5z" fill="currentColor"/></svg>';

		var bubble = document.createElement('button');
		bubble.className = 'asc-bubble';
		bubble.setAttribute('aria-label', 'Open chat');
		bubble.innerHTML = chatIcon;

		var panel = document.createElement('div');
		panel.className = 'asc-panel';
		panel.innerHTML = ''
			+ '<div class="asc-head">'
			+ '  <div class="asc-ava">🤖</div>'
			+ '  <div class="asc-head-info"><b></b><span class="asc-status"><i></i>AI Assistant — online</span></div>'
			+ '  <div class="asc-head-btns">'
			+ '    <button type="button" class="asc-new" title="Start a new chat" aria-label="New chat">↺</button>'
			+ '    <button type="button" class="asc-close" aria-label="Close">×</button>'
			+ '  </div>'
			+ '</div>'
			+ '<div class="asc-msgs"></div>'
			+ '<div class="asc-form" hidden>'
			+ '  <div class="asc-form-head"><span>Contact our team</span><button type="button" class="asc-form-close" aria-label="Close form">×</button></div>'
			+ '  <input type="text" class="asc-f-name" placeholder="Your name" maxlength="100">'
			+ '  <input type="email" class="asc-f-email" placeholder="Your email" maxlength="100">'
			+ '  <textarea class="asc-f-msg" rows="3" placeholder="Describe your issue..." maxlength="2000"></textarea>'
			+ '  <button type="button" class="asc-form-send asc-f-send">Send to team</button>'
			+ '</div>'
			+ '<div class="asc-foot">'
			+ '  <textarea placeholder="Type your question..." maxlength="1000" rows="1"></textarea>'
			+ '  <button type="button" class="asc-send" aria-label="Send">' + sendIcon + '</button>'
			+ '</div>'
			+ '<div class="asc-team"><a>💬 Talk to our team instead</a></div>';

		panel.querySelector('.asc-head-info b').textContent = title;

		mount.appendChild(bubble);
		mount.appendChild(panel);

		var msgs = panel.querySelector('.asc-msgs');
		var input = panel.querySelector('.asc-foot textarea');
		var sendBtn = panel.querySelector('.asc-send');
		var form = panel.querySelector('.asc-form');
		var greeted = false;

		function addMsg(role, text) {
			var row = document.createElement('div');
			row.className = 'asc-row ' + (role === 'user' ? 'asc-user' : 'asc-bot');
			if (role !== 'user') {
				var mini = document.createElement('div');
				mini.className = 'asc-mini';
				mini.textContent = '🤖';
				row.appendChild(mini);
			}
			var el = document.createElement('div');
			el.className = 'asc-msg';
			el.textContent = text;
			row.appendChild(el);
			msgs.appendChild(row);
			msgs.scrollTop = msgs.scrollHeight;
			return row;
		}

		function addTyping() {
			var row = document.createElement('div');
			row.className = 'asc-row asc-bot';
			row.innerHTML = '<div class="asc-mini">🤖</div><div class="asc-msg asc-dots"><span></span><span></span><span></span></div>';
			msgs.appendChild(row);
			msgs.scrollTop = msgs.scrollHeight;
			return row;
		}

		function toggle(open) {
			panel.classList.toggle('asc-open', open);
			if (open && !greeted) {
				greeted = true;
				addMsg('bot', greeting);
			}
			if (open) input.focus();
		}

		bubble.addEventListener('click', function () { toggle(!panel.classList.contains('asc-open')); });
		panel.querySelector('.asc-close').addEventListener('click', function () { toggle(false); });

		panel.querySelector('.asc-new').addEventListener('click', function () {
			sid = makeSid();
			try { localStorage.setItem('asc_sid', sid); } catch (e) {}
			msgs.innerHTML = '';
			form.hidden = true;
			form.querySelector('.asc-f-msg').value = '';
			addMsg('bot', 'New chat started. ' + greeting);
		});

		function sendMessage() {
			var text = input.value.trim();
			if (!text) return;
			input.value = '';
			addMsg('user', text);
			var typing = addTyping();
			sendBtn.disabled = true;

			fetch(apiBase + '/message', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ session_id: sid, message: text })
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					typing.remove();
					if (res && res.reply) {
						addMsg('bot', res.reply);
						if (res.handoff) offerHandoff(text);
					} else {
						addMsg('bot', (res && res.message) || 'Sorry, something went wrong. Please try again.');
					}
				})
				.catch(function () {
					typing.remove();
					addMsg('bot', 'Connection problem — please try again in a moment.');
					offerHandoff(text);
				})
				.then(function () { sendBtn.disabled = false; });
		}

		sendBtn.addEventListener('click', sendMessage);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		function showForm(prefill) {
			form.hidden = false;
			// Always overwrite with the latest unanswered question — a stale
			// value from an earlier handoff must not stick around.
			if (prefill) {
				form.querySelector('.asc-f-msg').value = prefill;
			}
			msgs.scrollTop = msgs.scrollHeight;
		}

		// Offer a small button instead of slamming the form open on handoff.
		function offerHandoff(prefill) {
			if (msgs.lastElementChild && msgs.lastElementChild.classList.contains('asc-cta')) return;
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'asc-cta';
			btn.textContent = '👤 Talk to our team';
			btn.addEventListener('click', function () {
				btn.remove();
				showForm(prefill);
			});
			msgs.appendChild(btn);
			msgs.scrollTop = msgs.scrollHeight;
		}

		panel.querySelector('.asc-form-close').addEventListener('click', function () {
			form.hidden = true;
		});

		panel.querySelector('.asc-team a').addEventListener('click', function () {
			addMsg('bot', 'Sure — leave your details below and our team will get back to you by email.');
			showForm('');
		});

		panel.querySelector('.asc-f-send').addEventListener('click', function () {
			var name = form.querySelector('.asc-f-name').value.trim();
			var email = form.querySelector('.asc-f-email').value.trim();
			var message = form.querySelector('.asc-f-msg').value.trim();
			if (!name || !email || !message) {
				addMsg('bot', 'Please fill in your name, email and message.');
				return;
			}
			var btn = this;
			btn.disabled = true;

			fetch(apiBase + '/handoff', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ session_id: sid, name: name, email: email, message: message })
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.ok) {
						form.hidden = true;
						form.querySelector('.asc-f-msg').value = '';
						addMsg('bot', 'Thanks ' + name + '! Your message has been sent to our team — we’ll reply to ' + email + ' soon.');
					} else {
						addMsg('bot', (res && res.message) || 'Could not send — please check your email address and try again.');
					}
				})
				.catch(function () {
					addMsg('bot', 'Connection problem — please try again.');
				})
				.then(function () { btn.disabled = false; });
		});
	}
})();
