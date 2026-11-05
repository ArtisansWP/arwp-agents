/**
 * Front-end chat behavior for ARWP Agents.
 *
 * @package ARWP_Agents
 */

(function () {
	const boot = () => {
		const chatContainers = document.querySelectorAll('.arwp-chat-wrapper');

		if (!chatContainers.length) {
			return;
		}

		chatContainers.forEach(container => {
			const agentId = parseInt(container.getAttribute('data-agent-id'), 10);
			const cooldownPeriod = parseInt(container.getAttribute('data-cooldown'), 10) || 0;

			const messagesContainer = container.querySelector('.arwp-chat-messages-container');
			const typingIndicator = container.querySelector('.arwp-chat-typing-container');
			const form = container.querySelector('.arwp-chat-form');
			const textarea = container.querySelector('.arwp-chat-input');
			const submitBtn = container.querySelector('.arwp-chat-submit-btn');
			const errorContainer = container.querySelector('.arwp-chat-error-message');

			if (!messagesContainer || !form || !textarea || !submitBtn) {
				return;
			}
			const tokenKey = `arwp_session_token_${agentId}`;
			let sessionToken = localStorage.getItem(tokenKey) || '';

			let isCooldownActive = false;

			// Initialize height adjustment for the textarea.
			textarea.addEventListener('input', () => {
				textarea.style.height = 'auto';
				textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
				updateSendEnabled();
			});

			// Submit on Enter, Newline on Shift+Enter.
			textarea.addEventListener('keydown', (e) => {
				// isComposing/229 guard IME input (Chinese, Japanese, Korean), where
				// Enter commits a candidate rather than sending the message.
				if (e.key !== 'Enter' || e.shiftKey || e.isComposing || e.keyCode === 229) {
					return;
				}
				e.preventDefault();
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.dispatchEvent(new Event('submit', { cancelable: true }));
				}
			});

			const scrollBottomBtn = container.querySelector('.arwp-scroll-bottom-btn');
			const fullscreenBtn = container.querySelector('.arwp-fullscreen-btn');
			const i18n = (window.arwp_globals && arwp_globals.i18n) ? arwp_globals.i18n : {};
			const t = (key, fallback) => (i18n[key] ? i18n[key] : fallback);

			let activeController = null;

			const isNearBottom = () => {
				const distance = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight;
				return distance < 80;
			};

			const updateScrollButton = () => {
				if (scrollBottomBtn) {
					scrollBottomBtn.hidden = isNearBottom();
				}
			};

			const scrollToBottom = (force) => {
				// Never yank the view away from someone reading further up the conversation.
				if (!force && !isNearBottom()) {
					updateScrollButton();
					return;
				}
				messagesContainer.scrollTop = messagesContainer.scrollHeight;
				updateScrollButton();
			};

			messagesContainer.addEventListener('scroll', updateScrollButton);

			if (scrollBottomBtn) {
				scrollBottomBtn.addEventListener('click', () => scrollToBottom(true));
			}

			const setFullscreen = (on) => {
				container.classList.toggle('arwp-is-fullscreen', on);
				document.body.classList.toggle('arwp-chat-noscroll', document.querySelectorAll('.arwp-chat-wrapper.arwp-is-fullscreen').length > 0);
				if (fullscreenBtn) {
					fullscreenBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
					fullscreenBtn.setAttribute('aria-label', on ? t('exit_fullscreen', 'Exit fullscreen') : t('fullscreen', 'Enter fullscreen'));
				}
				scrollToBottom(true);
			};

			if (fullscreenBtn) {
				fullscreenBtn.addEventListener('click', () => {
					setFullscreen(!container.classList.contains('arwp-is-fullscreen'));
				});
			}

			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && container.classList.contains('arwp-is-fullscreen')) {
					setFullscreen(false);
				}
			});

			const updateSendEnabled = () => {
				if (activeController) {
					submitBtn.disabled = false;
					return;
				}
				if (isCooldownActive) {
					submitBtn.disabled = true;
					return;
				}
				submitBtn.disabled = textarea.value.trim() === '';
			};


			// Escape HTML tags.
			const escapeHtml = (text) => {
				const map = {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#039;'
				};
				return text.replace(/[&<>"']/g, m => map[m]);
			};

			// Render paragraphs, inline code, and code blocks.
			const sanitizeHtml = (html) => {
				const doc = new DOMParser().parseFromString(html, 'text/html');
				const allowed = {
					P: true, BR: true, STRONG: true, EM: true, B: true, I: true, CODE: true, PRE: true,
					UL: true, OL: true, LI: true, A: true, IMG: true, H1: true, H2: true, H3: true,
					H4: true, H5: true, H6: true, BLOCKQUOTE: true, HR: true, TABLE: true, THEAD: true,
					TBODY: true, TR: true, TH: true, TD: true
				};

				const clean = (node) => {
					[...node.childNodes].forEach((child) => {
						if (child.nodeType !== 1) {
							return;
						}
						if (['SCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META', 'STYLE', 'FORM', 'INPUT', 'BUTTON'].indexOf(child.tagName) !== -1) {
							child.remove();
							return;
						}
						if (!allowed[child.tagName]) {
							const parent = child.parentNode;
							while (child.firstChild) {
								parent.insertBefore(child.firstChild, child);
							}
							child.remove();
							return;
						}
						[...child.attributes].forEach((attr) => {
							const name = attr.name.toLowerCase();
							if (name.startsWith('on') || name === 'srcdoc' || name === 'formaction') {
								child.removeAttribute(attr.name);
							}
						});
						if (child.tagName === 'A') {
							const href = child.getAttribute('href') || '';
							if (!/^(https?:|mailto:|#)/i.test(href)) {
								child.removeAttribute('href');
							}
							child.setAttribute('rel', 'noopener noreferrer nofollow');
							child.setAttribute('target', '_blank');
						}
						if (child.tagName === 'IMG') {
							const src = child.getAttribute('src') || '';
							if (!/^https?:/i.test(src)) {
								child.remove();
								return;
							}
						}
						clean(child);
					});
				};

				clean(doc.body);
				return doc.body.innerHTML;
			};

			const formatMessageContent = (text) => {
				let html;
				if (typeof marked !== 'undefined') {
					html = marked.parse(String(text || ''));
				} else {
					let escaped = escapeHtml(String(text || ''));
					escaped = escaped.replace(/```([\s\S]*?)```/g, (match, code) => {
						return `<pre><code>${code.trim()}</code></pre>`;
					});
					escaped = escaped.replace(/`([^`\n]+)`/g, '<code>$1</code>');
					escaped = escaped.replace(/!\[(.*?)\]\((.*?)\)/g, '<img src="$2" alt="$1" />');
					escaped = escaped.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2">$1</a>');
					escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
					escaped = escaped.replace(/\*(.*?)\*/g, '<em>$1</em>');
					html = escaped.split('\n\n').map(para => `<p>${para.replace(/\n/g, '<br>')}</p>`).join('');
				}
				return sanitizeHtml(html);
			};

			const copyIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';

			const addCopyAction = (bubble, getText) => {
				if (!navigator.clipboard || bubble.querySelector('.arwp-msg-actions')) {
					return;
				}

				const actions = document.createElement('div');
				actions.className = 'arwp-msg-actions';

				const copyBtn = document.createElement('button');
				copyBtn.type = 'button';
				copyBtn.className = 'arwp-msg-action-btn';
				copyBtn.innerHTML = copyIcon;
				const copyLabel = t('copy', 'Copy');
				const copiedLabel = t('copied', 'Copied');
				const copyFailedLabel = t('copy_failed', 'Copy failed');
				const labelText = document.createElement('span');
				labelText.textContent = copyLabel;
				copyBtn.appendChild(labelText);

				copyBtn.addEventListener('click', async() => {
					try {
						await navigator.clipboard.writeText(getText());
						labelText.textContent = copiedLabel;
						setTimeout(() => {
							labelText.textContent = copyLabel;
						}, 1500);
					} catch (err) {
						labelText.textContent = copyFailedLabel;
					}
				});

				actions.appendChild(copyBtn);
				bubble.appendChild(actions);
			};

			const headerAvatar = container.querySelector('.arwp-chat-agent-avatar');

			const fillAgentBubble = (bubble, html) => {
				const row = document.createElement('div');
				row.className = 'arwp-agent-row';

				const avatar = document.createElement('div');
				avatar.className = 'arwp-msg-avatar';
				if (headerAvatar) {
					avatar.innerHTML = headerAvatar.innerHTML;
				}

				const body = document.createElement('div');
				body.className = 'arwp-msg-body';
				body.innerHTML = html;

				row.appendChild(avatar);
				row.appendChild(body);
				bubble.replaceChildren(row);
				return body;
			};

			const appendMessage = (role, content) => {
				const bubble = document.createElement('div');
				const visualRole = role === 'assistant' ? 'agent' : role;
				bubble.classList.add('arwp-chat-bubble', `arwp-chat-bubble-${visualRole}`);
				if (role === 'assistant') {
					const body = fillAgentBubble(bubble, formatMessageContent(content));
					addCopyAction(body, () => content);
				} else {
					bubble.innerHTML = formatMessageContent(content);
				}
				messagesContainer.appendChild(bubble);
				scrollToBottom();
			};

			// Show the greeting when the agent has one and there is no history.
			const showGreeting = () => {
				const greeting = container.getAttribute('data-greeting');
				if (greeting && greeting.trim() !== '') {
					appendMessage('assistant', greeting);
				}
			};

			// Load conversation history on startup.
			const loadHistory = async(tokenToLoad) => {
				if (!tokenToLoad) {
					messagesContainer.innerHTML = '';
					showGreeting();
					return;
				}

				try {
					const response = await fetch(`${arwp_globals.api_url}history`, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': arwp_globals.nonce
						},
						body: JSON.stringify({
							agent_id: agentId,
							session_token: tokenToLoad
						})
					});

					const data = await response.json();
					messagesContainer.innerHTML = '';

					if (response.ok && data.success && data.messages) {
						if (data.messages.length === 0) {
							showGreeting();
						} else {
							data.messages.forEach(msg => {
								if (msg.role === 'user' || msg.role === 'assistant') {
									appendMessage(msg.role, msg.content);
								}
							});
							scrollToBottom(true);
						}
					} else {
						if (sessionToken === tokenToLoad) {
							sessionToken = '';
							localStorage.removeItem(tokenKey);
						}
						showGreeting();
					}
				} catch (err) {
					console.error('ARWP Agents History Loading Error:', err);
					messagesContainer.innerHTML = '';
					showGreeting();
				}
			};

			// Apply the front-end cooldown lock.
			const startCooldown = (seconds) => {
				if (seconds <= 0) {
					return;
				}

				isCooldownActive = true;
				let remaining = seconds;

				textarea.disabled = true;
				submitBtn.disabled = true;

				const originalPlaceholder = textarea.placeholder;

				const waitLabel = (seconds) => t('please_wait', 'Please wait %d seconds…').replace('%d', String(seconds));

				const timer = setInterval(() => {
					remaining--;
					if (remaining <= 0) {
						clearInterval(timer);
						isCooldownActive = false;
						textarea.disabled = false;
						submitBtn.disabled = false;
						textarea.placeholder = originalPlaceholder;
						textarea.focus();
						updateSendEnabled();
						return;
					}
					textarea.placeholder = waitLabel(remaining);
				}, 1000);

				// Initial placeholder update.
				textarea.placeholder = waitLabel(remaining);
			};

			const finishSuccessfulTurn = (data) => {
				sessionToken = data.session_token;
				localStorage.setItem(tokenKey, sessionToken);

				if (cooldownPeriod > 0) {
					startCooldown(cooldownPeriod);
				} else {
					textarea.disabled = false;
					textarea.focus();
					updateSendEnabled();
				}
			};

			const readChatStream = async(response) => {
				const reader = response.body.getReader();
				const decoder = new TextDecoder();
				let buffer = '';
				let full = '';
				let bubble = null;
				let sessionFromStream = sessionToken;
				let framePending = false;

				const ensureBubble = () => {
					if (bubble) {
						return;
					}
					bubble = document.createElement('div');
					bubble.classList.add('arwp-chat-bubble', 'arwp-chat-bubble-agent', 'arwp-chat-bubble-streaming');
					fillAgentBubble(bubble, '');
					messagesContainer.appendChild(bubble);
				};

				const paintBody = () => {
					const body = bubble && bubble.querySelector('.arwp-msg-body');
					if (!body) {
						return;
					}
					body.innerHTML = formatMessageContent(full);
				};

				// Repaint at most once per frame. Re-parsing markdown on every token
				// is quadratic and stalls long replies.
				const paint = () => {
					if (framePending) {
						return;
					}
					framePending = true;
					requestAnimationFrame(() => {
						framePending = false;
						if (!bubble) {
							return;
						}
						paintBody();
						scrollToBottom();
					});
				};

				const finalise = () => {
					ensureBubble();
					bubble.classList.remove('arwp-chat-bubble-streaming');
					const body = fillAgentBubble(bubble, formatMessageContent(full));
					addCopyAction(body, () => full);
					scrollToBottom();
				};

				const readLoop = async() => {
					while (true) {
						const { done, value } = await reader.read();
						if (done) {
							return null;
						}

						buffer += decoder.decode(value, { stream: true });
						const parts = buffer.split('\n\n');
						buffer = parts.pop();

						for (const part of parts) {
							const line = part.split('\n').find(l => l.startsWith('data: '));
							if (!line) {
								continue;
							}

							let evt;
							try {
								evt = JSON.parse(line.slice(6));
							} catch (err) {
								continue;
							}

							if (evt.type === 'delta' && evt.content) {
								typingIndicator.style.display = 'none';
								ensureBubble();
								full += evt.content;
								paint();
							} else if (evt.type === 'done') {
								typingIndicator.style.display = 'none';
								if (evt.response) {
									full = evt.response;
								}
								if (evt.session_token) {
									sessionFromStream = evt.session_token;
								}
								return evt;
							} else if (evt.type === 'error') {
								throw new Error(evt.message || 'Stream error');
							}
						}
					}
				};

				try {
					const doneEvent = await readLoop();

					if (doneEvent) {
						finalise();
						return { success: true, session_token: sessionFromStream, response: full, streamed: true };
					}
				} catch (err) {
					// A stop request keeps whatever already arrived.
					if (err && err.name === 'AbortError' && full) {
						finalise();
						return { success: true, session_token: sessionFromStream, response: full, streamed: true, aborted: true };
					}
					throw err;
				}

				if (full) {
					finalise();
					return { success: true, session_token: sessionFromStream, response: full, streamed: true };
				}

				throw new Error('Empty stream');
			};

			// A cached page can hand out an expired nonce; fetch a fresh one on 403.
			const refreshNonce = async() => {
				const res = await fetch(`${arwp_globals.api_url}nonce`, {
					headers: { Accept: 'application/json' },
					cache: 'no-store'
				});
				const data = await res.json();
				if (!data || !data.nonce) {
					return false;
				}
				arwp_globals.nonce = data.nonce;
				arwp_globals.chat_nonce = data.nonce;
				return true;
			};

			const postChat = (messageText, signal) => {
				return fetch(`${arwp_globals.api_url}chat-stream`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': arwp_globals.nonce
					},
					body: JSON.stringify({
						agent_id: agentId,
						session_token: sessionToken,
						new_message: messageText,
						nonce: arwp_globals.chat_nonce
					}),
					signal
				});
			};

			const sendChatRequest = async(messageText, signal) => {
				let response = await postChat(messageText, signal);

				if (response.status === 403 && await refreshNonce()) {
					response = await postChat(messageText, signal);
				}

				const contentType = response.headers.get('content-type') || '';
				if (contentType.includes('text/event-stream') && response.body) {
					return readChatStream(response);
				}

				const data = await response.json().catch(() => ({}));
				if (!response.ok || !data.success) {
					const error = new Error(data.message || 'An unknown error occurred.');
					error.status = response.status;
					error.payload = data;
					throw error;
				}

				return data;
			};

			const setStreamingState = (streaming) => {
				container.classList.toggle('arwp-is-streaming', streaming);
				submitBtn.setAttribute('aria-label', streaming ? t('stop', 'Stop generating') : t('send', 'Send message'));
				updateSendEnabled();
			};

			form.addEventListener('submit', async(e) => {
				e.preventDefault();

				// The send control doubles as stop while a reply is streaming.
				if (activeController) {
					activeController.abort();
					return;
				}

				if (isCooldownActive) {
					return;
				}

				const messageText = textarea.value.trim();
				if (!messageText) {
					return;
				}

				errorContainer.classList.remove('active');
				errorContainer.textContent = '';

				appendMessage('user', messageText);

				textarea.value = '';
				textarea.style.height = 'auto';

				typingIndicator.style.display = 'block';
				scrollToBottom(true);
				textarea.disabled = true;

				activeController = new AbortController();
				setStreamingState(true);

				try {
					const data = await sendChatRequest(messageText, activeController.signal);
					typingIndicator.style.display = 'none';

					if (!data.streamed && data.response) {
						appendMessage('assistant', data.response);
					}

					finishSuccessfulTurn(data);
				} catch (err) {
					typingIndicator.style.display = 'none';

					if (err && err.name === 'AbortError') {
						textarea.disabled = false;
						textarea.focus();
						return;
					}

					const payload = err.payload || {};
					errorContainer.textContent = err.message || 'Server connection lost. Please try again.';
					errorContainer.classList.add('active');

					if (err.status === 429 && payload.data && payload.data.wait_time) {
						startCooldown(parseInt(payload.data.wait_time, 10));
					} else {
						textarea.disabled = false;
						textarea.focus();
					}
				} finally {
					activeController = null;
					setStreamingState(false);
				}
			});

			loadHistory(sessionToken);
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
