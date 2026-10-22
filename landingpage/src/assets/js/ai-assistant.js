/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/*
 * "Ask nr-llm" — on-device Q&A using the browser's built-in Prompt API
 * (Gemini Nano, stable since Chrome 148). Pure client side, no backend, no cost.
 *
 * Progressive enhancement + graceful fallback:
 *   - No `LanguageModel` global / 'unavailable'  -> show a note, rely on full-text search.
 *   - 'downloadable' / 'after-download'          -> user-initiated "Enable" button (consented download).
 *   - 'available'                                -> answer questions, grounded (RAG-lite) via the
 *                                                   search index (window.NrllmSearch).
 *
 * The on-device model has no inherent knowledge of nr-llm; every answer is grounded in
 * retrieved context snippets and constrained to them.
 *
 * Config: window.__NRLLM__ = { lang, strings: { aiPlaceholder, aiAsk, aiEnable, … } }
 */
(function () {
  'use strict';

  var CFG = window.__NRLLM__ || {};
  var S = CFG.strings || {};
  var LANG = CFG.lang || 'en';
  var LANG_NAME = LANG === 'de' ? 'German' : 'English';

  function t(key, fallback) { return S[key] || fallback; }

  function supported() {
    return typeof self !== 'undefined' && self.LanguageModel
      && typeof self.LanguageModel.availability === 'function'
      && typeof self.LanguageModel.create === 'function';
  }

  function setEl(el, text) { if (el) el.textContent = text; }

  /* ---- Minimal, safe Markdown -> HTML (the model answers in Markdown) ---- */
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  // Allowlist URL schemes so a link (from model output OR the search index) can
  // never be a javascript:/data: sink. '' for anything not http(s)/mailto/#/relative.
  function safeUrl(u) {
    u = (u == null ? '' : String(u)).trim();
    return /^(https?:|mailto:|#|\/)/i.test(u) ? u : '';
  }
  function inlineMd(escaped) {
    // Input is already HTML-escaped; only our own known tags are introduced.
    return escaped
      .replace(/`([^`]+)`/g, function (_, c) { return '<code>' + c + '</code>'; })
      .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (_, t, u) {
        u = safeUrl(u);
        if (!u) return t; // drop unsafe schemes
        return '<a href="' + u + '" rel="noopener nofollow">' + t + '</a>';
      })
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*\s][^*]*?)\*/g, '$1<em>$2</em>');
  }
  function renderMarkdown(md) {
    md = md.replace(/\r\n?/g, '\n');
    // Pull fenced code blocks out first so their contents are never parsed as
    // Markdown. Handles ```lang\n…\n``` and a still-open trailing fence (streaming).
    var blocks = [];
    md = md.replace(/```[ \t]*[\w+-]*[ \t]*\n?([\s\S]*?)```/g, function (_, code) {
      blocks.push(code.replace(/\n$/, ''));
      return '\u0000B' + (blocks.length - 1) + '\u0000';
    });
    var openFence = md.match(/```[ \t]*[\w+-]*[ \t]*\n?([\s\S]*)$/);
    if (openFence) {
      blocks.push(openFence[1]);
      md = md.slice(0, openFence.index) + '\u0000B' + (blocks.length - 1) + '\u0000';
    }
    var out = '', list = null, para = [];
    function flushPara() { if (para.length) { out += '<p>' + inlineMd(para.join(' ')) + '</p>'; para = []; } }
    function closeList() { if (list) { out += '</' + list + '>'; list = null; } }
    function codeHtml(i) { return '<pre class="ai-code" tabindex="0"><code>' + escapeHtml(blocks[i]) + '</code></pre>'; }
    md.split('\n').forEach(function (line) {
      var block = line.match(/^\u0000B(\d+)\u0000$/);
      var ul = line.match(/^\s*[-*]\s+(.*)$/);
      var ol = line.match(/^\s*(\d+)[.)]\s+(.*)$/);
      if (block) { flushPara(); closeList(); out += codeHtml(+block[1]); }
      else if (ul) { flushPara(); if (list !== 'ul') { closeList(); out += '<ul>'; list = 'ul'; } out += '<li>' + inlineMd(escapeHtml(ul[1])) + '</li>'; }
      else if (ol) { flushPara(); if (list !== 'ol') { closeList(); out += '<ol>'; list = 'ol'; } out += '<li value="' + ol[1] + '">' + inlineMd(escapeHtml(ol[2])) + '</li>'; }
      else if (line.trim() === '') { flushPara(); closeList(); }
      else { closeList(); para.push(escapeHtml(line.trim())); }
    });
    flushPara(); closeList();
    // Any inline placeholders (fence mid-line) fall back to block rendering.
    return out.replace(/\u0000B(\d+)\u0000/g, function (_, i) { return codeHtml(+i); });
  }
  function setHtml(el, htmlStr) {
    while (el.firstChild) el.removeChild(el.firstChild);
    var doc = new DOMParser().parseFromString('<body>' + htmlStr + '</body>', 'text/html');
    Array.prototype.slice.call(doc.body.childNodes).forEach(function (n) {
      el.appendChild(document.importNode(n, true));
    });
  }

  function buildSystemPrompt() {
    return [
      'You are a concise assistant for "nr-llm", a TYPO3 extension that provides a shared',
      'AI/LLM foundation (central provider management, encrypted API keys, and services for',
      'chat, translation, vision and embeddings).',
      'Answer the user question using ONLY the provided context snippets.',
      'If the answer is not contained in the context, say you do not have that information and',
      'suggest checking the documentation or GitHub repository. Do not invent APIs or features.',
      'Keep answers short (a few sentences). Answer in ' + LANG_NAME + '.',
    ].join(' ');
  }

  function grounding(query) {
    if (!window.NrllmSearch) return Promise.resolve('');
    return window.NrllmSearch.search(query, 5).then(function (hits) {
      return hits.map(function (h, i) {
        var body = (h.text || '').replace(/\s+/g, ' ').trim().slice(0, 700);
        return '[' + (i + 1) + '] ' + (h.title || '') +
          (h.section ? ' (' + h.section + ')' : '') + ': ' + body;
      }).join('\n\n');
    }).catch(function () { return ''; });
  }

  function enhance(root) {
    var form = root.querySelector('[data-ai-form]');
    var input = root.querySelector('[data-ai-input]');
    var output = root.querySelector('[data-ai-output]');
    var status = root.querySelector('[data-ai-status]');
    var submitBtn = root.querySelector('[data-ai-submit]');
    var cancelBtn = root.querySelector('[data-ai-cancel]');
    var enableWrap = root.querySelector('[data-ai-enable]');
    var enableBtn = root.querySelector('[data-ai-enable-btn]');
    var sources = root.querySelector('[data-ai-sources]');
    if (!form || !input || !output) return;

    root.hidden = false;

    if (!supported()) {
      root.setAttribute('data-state', 'unsupported');
      setEl(status, t('aiUnsupported',
        'On-device AI is not available in this browser. Use the search instead — it covers the whole site.'));
      form.hidden = true;
      return;
    }

    var session = null;
    var controller = null;
    var ready = false;
    var pendingQuery = null; // a question asked before the model finished warming up
    var focusMoved = false;  // did we hand focus to Cancel when disabling the form?

    function busy(on) {
      output.setAttribute('aria-busy', on ? 'true' : 'false');
      if (submitBtn) submitBtn.disabled = on;
      if (cancelBtn) cancelBtn.hidden = !on;
      input.disabled = on;
      // Don't strand keyboard focus on the control we just disabled: hand it to
      // Cancel (the only live control mid-stream) if focus was in the widget, and
      // give it back to the input once the answer completes.
      if (on) {
        if (cancelBtn && root.contains(document.activeElement)) { cancelBtn.focus(); focusMoved = true; }
      } else if (focusMoved) {
        input.focus(); focusMoved = false;
      }
    }

    function ensureSession() {
      if (session) return Promise.resolve(session);
      return window.LanguageModel.create({
        initialPrompts: [{ role: 'system', content: buildSystemPrompt() }],
        expectedInputs: [{ type: 'text', languages: ['en', LANG] }],
        expectedOutputs: [{ type: 'text', languages: [LANG] }],
        monitor: function (m) {
          var lastBucket = -1;
          m.addEventListener('downloadprogress', function (e) {
            var pct = Math.round((e.loaded || 0) * 100);
            // status is a polite live region — announce at ~10% steps (plus the
            // final 100%) so a screen reader isn't flooded with every tick.
            var bucket = Math.floor(pct / 10);
            if (bucket === lastBucket && pct !== 100) return;
            lastBucket = bucket;
            setEl(status, t('aiDownloading', 'Downloading on-device model…') + ' ' + pct + '%');
          });
        },
      }).then(function (s) { session = s; return s; });
    }

    function checkAvailability() {
      try {
        return window.LanguageModel.availability({
          expectedInputs: [{ type: 'text', languages: ['en', LANG] }],
          expectedOutputs: [{ type: 'text', languages: [LANG] }],
        }).catch(function () { return 'unavailable'; });
      } catch (e) {
        // A partial LanguageModel implementation can throw synchronously — degrade
        // to the search fallback instead of leaving the widget stuck on "Checking…".
        return Promise.resolve('unavailable');
      }
    }

    function markReady() {
      ready = true;
      root.setAttribute('data-state', 'ready');
      if (enableWrap) enableWrap.hidden = true;
      if (form) form.hidden = false;
      if (submitBtn) submitBtn.disabled = false;
      input.disabled = false;
      setEl(status, '');
      if (pendingQuery) { var q = pendingQuery; pendingQuery = null; input.value = q; askAnswer(q); }
    }

    function offerEnable() {
      root.setAttribute('data-state', 'downloadable');
      if (form) form.hidden = true;
      if (enableWrap) enableWrap.hidden = false;
      setEl(status, t('aiEnableHint',
        'On-device AI is available but needs a one-time model download in your browser.'));
    }

    function askAnswer(query) {
      busy(true);
      setEl(output, '');
      if (sources) { sources.hidden = true; while (sources.firstChild) sources.removeChild(sources.firstChild); }
      controller = new AbortController();
      var acc = '';
      grounding(query).then(function (ctx) {
        if (sources && ctx) renderSources(query);
        var prompt = (ctx ? ('Context:\n' + ctx + '\n\n') : '') + 'Question: ' + query;
        var stream = session.promptStreaming(prompt, { signal: controller.signal });
        // The Prompt API has shipped both cumulative snapshots and incremental
        // deltas across Chrome versions (the current docs don't pin it down).
        // Detect the mode from the first two chunks, then lock it for the whole
        // stream — this avoids a per-chunk heuristic swallowing a repeated token.
        var mode = null; // 'cumulative' | 'delta'
        return (async function () {
          for await (var chunk of stream) {
            if (typeof chunk !== 'string') chunk = String(chunk);
            if (!acc) {
              acc = chunk; // first chunk is identical under either mode
            } else if (mode === null) {
              mode = (chunk.length >= acc.length && chunk.indexOf(acc) === 0) ? 'cumulative' : 'delta';
              acc = mode === 'cumulative' ? chunk : acc + chunk;
            } else if (mode === 'cumulative') {
              acc = chunk;
            } else {
              acc += chunk;
            }
            setHtml(output, renderMarkdown(acc));
          }
        })();
      }).then(function () {
        busy(false);
        if (!acc) setEl(output, t('aiEmpty', 'No answer was produced. Try rephrasing your question.'));
      }).catch(function (err) {
        busy(false);
        if (err && err.name === 'AbortError') return;
        setEl(status, t('aiError', 'The on-device model could not answer. Please try the search instead.'));
      });
    }

    function renderSources(query) {
      if (!window.NrllmSearch || !sources) return;
      window.NrllmSearch.search(query, 3).then(function (hits) {
        if (!hits.length) return;
        while (sources.firstChild) sources.removeChild(sources.firstChild);
        var label = document.createElement('span');
        label.className = 'ai-sources__label';
        label.textContent = t('aiSources', 'Sources');
        sources.appendChild(label);
        var ul = document.createElement('ul');
        hits.forEach(function (h) {
          var li = document.createElement('li');
          var a = document.createElement('a');
          a.href = safeUrl(h.url) || '#';
          a.textContent = h.title + (h.section ? ' — ' + h.section : '');
          li.appendChild(a);
          ul.appendChild(li);
        });
        sources.appendChild(ul);
        sources.hidden = false;
      }).catch(function () {});
    }

    // Warm up on first sign of interest: model session + grounding index load
    // ahead of the first question, so an immediate ask isn't dropped or answered
    // before the model is ready.
    var probed = false;
    function probe() {
      if (probed) return; probed = true;
      if (window.NrllmSearch) window.NrllmSearch.load().catch(function () {}); // preload grounding index
      setEl(status, t('aiChecking', 'Checking on-device AI…'));
      checkAvailability().then(function (a) {
        if (a === 'available') {
          root.setAttribute('data-state', 'preparing');
          if (submitBtn) submitBtn.disabled = true;
          setEl(status, t('aiPreparing', 'Preparing the on-device AI — the first answer can take a few seconds…'));
          ensureSession().then(markReady).catch(function () {
            if (submitBtn) submitBtn.disabled = false;
            setEl(status, t('aiError', 'The on-device model is unavailable.'));
          });
        } else if (a === 'downloadable' || a === 'after-download' || a === 'downloading') {
          offerEnable();
        } else {
          root.setAttribute('data-state', 'unsupported');
          if (form) form.hidden = true;
          setEl(status, t('aiUnsupported',
            'On-device AI is not available on this device. Use the search instead.'));
        }
      });
    }

    input.addEventListener('focus', probe, { once: true });
    var toggle = root.querySelector('[data-ai-toggle]');
    if (toggle) toggle.addEventListener('click', probe, { once: true });
    // Start warming as soon as the widget scrolls near the viewport.
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) { io.disconnect(); probe(); } });
      }, { rootMargin: '250px' });
      io.observe(root);
    }

    if (enableBtn) enableBtn.addEventListener('click', function () {
      setEl(status, t('aiDownloading', 'Downloading on-device model…'));
      enableBtn.disabled = true;
      ensureSession().then(function () { markReady(); input.focus(); })
        .catch(function () { enableBtn.disabled = false; setEl(status, t('aiError', 'Download failed.')); });
    });

    if (cancelBtn) cancelBtn.addEventListener('click', function () {
      if (controller) controller.abort();
      busy(false);
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = input.value.trim();
      if (!q) return;
      if (!ready) {
        // Don't drop the question — remember it and answer once the model is ready.
        pendingQuery = q;
        if (!probed) probe();
        if (submitBtn) submitBtn.disabled = true;
        setEl(status, t('aiPreparing', 'Preparing the on-device AI — your question will be answered in a moment…'));
        return;
      }
      askAnswer(q);
    });
  }

  function init() {
    var roots = document.querySelectorAll('[data-ai-assistant]');
    roots.forEach(enhance);
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
