/*
 * The Aldervast chat widget: a round chat button fixed to the bottom right of any page, which opens
 * a small chat panel. Questions go to /chat on the server that this file came from, and the answers
 * are written by Claude from the Aldervast wiki (src/Web/Chat.php).
 *
 * Add it to a page with one line, anywhere in <body>:
 *   <script src="https://aldervast.versyxdigital.net/widget.js" defer></script>
 *
 * Everything lives inside a Shadow DOM, so the page's CSS can't change the widget and the widget's
 * CSS can't change the page. The page must be listed in ALDERVAST_CHAT_ORIGINS on the server, or
 * the browser refuses to send the question (README.md, "The chat widget").
 */
(function () {
  'use strict';
  if (window.__aldervastChat) return; // Added twice by mistake? Only build one widget.
  window.__aldervastChat = true;

  // The server is wherever this script was loaded from.
  const script = document.currentScript;
  const server = new URL(script ? script.src : location.href).origin;
  const MAX_CHARS = 500;

  const host = document.createElement('div');
  host.id = 'aldervast-chat';
  document.body.appendChild(host);
  const root = host.attachShadow({ mode: 'open' });

  root.innerHTML = `
    <style>
      :host { all: initial; }
      * { box-sizing: border-box; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
      .wrap {
        --bg: #ffffff; --soft: #f4f1ea; --text: #1f1d1a; --muted: #6b665d; --line: #e2ddd2;
        --accent: #8a5a12; --accent-text: #ffffff; --error: #a8321d;
        position: fixed; right: 20px; bottom: 20px; z-index: 2147483000; color: var(--text);
      }
      @media (prefers-color-scheme: dark) {
        .wrap {
          --bg: #201e1b; --soft: #2a2723; --text: #ece8e0; --muted: #a39d92; --line: #3a3631;
          --accent: #d9a24a; --accent-text: #161513; --error: #f08a74;
        }
      }
      .launcher {
        width: 58px; height: 58px; border-radius: 50%; border: 0; cursor: pointer;
        background: var(--accent); color: var(--accent-text); box-shadow: 0 6px 20px rgba(0,0,0,.25);
        display: grid; place-items: center; margin-left: auto; transition: transform .15s;
      }
      .launcher:hover { transform: scale(1.06); }
      .launcher:focus-visible, button:focus-visible, textarea:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
      .launcher svg { width: 26px; height: 26px; }
      .panel {
        position: absolute; right: 0; bottom: 74px; width: 380px; height: 560px;
        max-height: calc(100vh - 110px); background: var(--bg); border: 1px solid var(--line);
        border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.28); display: flex; flex-direction: column; overflow: hidden;
      }
      .panel[hidden] { display: none; }
      header { display: flex; align-items: center; gap: 10px; padding: 14px 16px; background: var(--accent); color: var(--accent-text); }
      header h2 { font-size: 16px; margin: 0; flex: 1; }
      header p { font-size: 12px; margin: 2px 0 0; opacity: .85; }
      .close { background: none; border: 0; color: inherit; font-size: 22px; line-height: 1; cursor: pointer; padding: 4px; }
      .log { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: var(--soft); }
      .msg { max-width: 88%; padding: 10px 12px; border-radius: 12px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
      .msg.user { align-self: flex-end; background: var(--accent); color: var(--accent-text); border-bottom-right-radius: 4px; white-space: pre-wrap; }
      .msg.bot { align-self: flex-start; background: var(--bg); border: 1px solid var(--line); border-bottom-left-radius: 4px; }
      .msg.bot :first-child { margin-top: 0; } .msg.bot :last-child { margin-bottom: 0; }
      .msg.bot a { color: var(--accent); }
      .msg.bot ul, .msg.bot ol { padding-left: 20px; }
      .msg.bot table { border-collapse: collapse; display: block; overflow-x: auto; font-size: 13px; }
      .msg.bot th, .msg.bot td { border: 1px solid var(--line); padding: 3px 6px; text-align: left; }
      .msg.error { color: var(--error); }
      .tools { font-size: 11px; color: var(--muted); margin-top: 6px; }
      .typing span { display: inline-block; width: 6px; height: 6px; margin: 0 2px; border-radius: 50%; background: var(--muted); animation: blink 1.2s infinite; }
      .typing span:nth-child(2) { animation-delay: .2s; } .typing span:nth-child(3) { animation-delay: .4s; }
      @keyframes blink { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }
      @media (prefers-reduced-motion: reduce) { .typing span { animation: none; } .launcher { transition: none; } }
      form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--line); background: var(--bg); }
      textarea {
        flex: 1; resize: none; height: 42px; max-height: 120px; padding: 10px; border-radius: 10px;
        border: 1px solid var(--line); background: var(--soft); color: var(--text); font-size: 14px;
      }
      .send { border: 0; border-radius: 10px; padding: 0 14px; background: var(--accent); color: var(--accent-text); font-weight: 600; cursor: pointer; }
      .send:disabled { opacity: .5; cursor: wait; }
      .note { font-size: 11px; color: var(--muted); padding: 0 12px 10px; background: var(--bg); }
      @media (max-width: 480px) {
        .wrap { right: 12px; bottom: 12px; }
        .panel { position: fixed; inset: 0; width: auto; height: auto; max-height: none; border-radius: 0; }
      }
    </style>
    <div class="wrap">
      <section class="panel" role="dialog" aria-label="Ask about Aldervast" hidden>
        <header>
          <div><h2>Ask about Aldervast</h2><p>Answers from the Aldervast wiki</p></div>
          <button class="close" type="button" aria-label="Close chat">×</button>
        </header>
        <div class="log" aria-live="polite"></div>
        <form>
          <textarea maxlength="${MAX_CHARS}" placeholder="Ask a question…" aria-label="Your question"></textarea>
          <button class="send" type="submit">Send</button>
        </form>
        <p class="note">Written by Claude, an AI, and it can be wrong. Each question is answered on its own.</p>
      </section>
      <button class="launcher" type="button" aria-label="Open the Aldervast chat" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </button>
    </div>`;

  const panel = root.querySelector('.panel');
  const launcher = root.querySelector('.launcher');
  const log = root.querySelector('.log');
  const form = root.querySelector('form');
  const input = root.querySelector('textarea');
  const send = root.querySelector('.send');
  let greeted = false;

  function setOpen(open) {
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    if (open) {
      if (!greeted) {
        addBot('Hi! Ask me anything about Aldervast: classes, quests, dungeons, bosses, items or servers.');
        greeted = true;
      }
      input.focus();
    }
  }
  launcher.addEventListener('click', () => setOpen(panel.hidden));
  root.querySelector('.close').addEventListener('click', () => { setOpen(false); launcher.focus(); });
  root.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); launcher.focus(); } });

  function add(className, build) {
    const el = document.createElement('div');
    el.className = 'msg ' + className;
    build(el);
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }
  function addUser(text) { return add('user', (el) => { el.textContent = text; }); }
  function addBot(text) { return add('bot', (el) => { el.textContent = text; }); }

  // The answer is Markdown. It is turned into HTML here, from plain text only: every character is
  // escaped first, and then only a few safe patterns (links, bold, lists, headings, tables) become
  // tags, so nothing in an answer can run code on the page.
  function escape(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function inline(s) {
    return escape(s)
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
      .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<code>$1</code>');
  }
  function markdown(text) {
    const lines = text.replace(/\r/g, '').split('\n');
    let html = '';
    let list = null;
    let table = null;
    const closeList = () => { if (list) { html += `</${list}>`; list = null; } };
    const closeTable = () => { if (table) { html += '</table>'; table = null; } };
    for (const line of lines) {
      const bullet = line.match(/^\s*[-*]\s+(.*)/);
      const number = line.match(/^\s*\d+\.\s+(.*)/);
      const heading = line.match(/^#{1,6}\s+(.*)/);
      const row = line.match(/^\s*\|(.*)\|\s*$/);
      if (row) {
        closeList();
        if (/^[\s|:-]+$/.test(row[1])) continue; // the |---|---| line under the headings
        const cells = row[1].split('|').map((c) => inline(c.trim()));
        const tag = table ? 'td' : 'th';
        if (!table) { html += '<table>'; table = true; }
        html += '<tr>' + cells.map((c) => `<${tag}>${c}</${tag}>`).join('') + '</tr>';
        continue;
      }
      closeTable();
      if (bullet || number) {
        const kind = bullet ? 'ul' : 'ol';
        if (list !== kind) { closeList(); html += `<${kind}>`; list = kind; }
        html += `<li>${inline((bullet || number)[1])}</li>`;
      } else {
        closeList();
        if (heading) html += `<p><strong>${inline(heading[1])}</strong></p>`;
        else if (line.trim() !== '') html += `<p>${inline(line)}</p>`;
      }
    }
    closeList();
    closeTable();
    return html;
  }

  async function ask(question) {
    addUser(question);
    const waiting = add('bot typing', (el) => { el.innerHTML = '<span></span><span></span><span></span>'; el.setAttribute('aria-label', 'Looking it up'); });
    send.disabled = true;
    try {
      const response = await fetch(server + '/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question }),
      });
      const data = await response.json().catch(() => ({ error: 'The server sent an unreadable reply.' }));
      waiting.remove();
      if (!response.ok || data.error) {
        add('bot error', (el) => { el.textContent = data.error || 'Something went wrong.'; });
        return;
      }
      add('bot', (el) => {
        el.innerHTML = markdown(data.answer);
        if (data.tools && data.tools.length) {
          const tools = document.createElement('div');
          tools.className = 'tools';
          tools.textContent = 'Looked up with: ' + data.tools.join(', ');
          el.appendChild(tools);
        }
      });
    } catch (e) {
      waiting.remove();
      add('bot error', (el) => { el.textContent = 'Could not reach the chat server. Please try again.'; });
    } finally {
      send.disabled = false;
      input.focus();
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const question = input.value.trim();
    if (!question || send.disabled) return;
    input.value = '';
    ask(question.slice(0, MAX_CHARS));
  });
  // Enter sends; Shift+Enter starts a new line.
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
})();
