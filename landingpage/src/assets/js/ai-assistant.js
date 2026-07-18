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
    return typeof self !== 'undefined' && 'LanguageModel' in self;
  }

  function setEl(el, text) { if (el) el.textContent = text; }

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
      if (form) form.hidden = true;
      return;
    }

    var session = null;
    var controller = null;
    var ready = false;

    function busy(on) {
      output.setAttribute('aria-busy', on ? 'true' : 'false');
      if (submitBtn) submitBtn.disabled = on;
      if (cancelBtn) cancelBtn.hidden = !on;
      input.disabled = on;
    }

    function ensureSession() {
      if (session) return Promise.resolve(session);
      return window.LanguageModel.create({
        initialPrompts: [{ role: 'system', content: buildSystemPrompt() }],
        expectedInputs: [{ type: 'text', languages: ['en', LANG] }],
        expectedOutputs: [{ type: 'text', languages: [LANG] }],
        monitor: function (m) {
          m.addEventListener('downloadprogress', function (e) {
            var pct = Math.round((e.loaded || 0) * 100);
            setEl(status, t('aiDownloading', 'Downloading on-device model…') + ' ' + pct + '%');
          });
        },
      }).then(function (s) { session = s; return s; });
    }

    function checkAvailability() {
      return window.LanguageModel.availability({
        expectedInputs: [{ type: 'text', languages: ['en', LANG] }],
        expectedOutputs: [{ type: 'text', languages: [LANG] }],
      }).catch(function () { return 'unavailable'; });
    }

    function markReady() {
      ready = true;
      root.setAttribute('data-state', 'ready');
      if (enableWrap) enableWrap.hidden = true;
      if (form) form.hidden = false;
      setEl(status, '');
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
            output.textContent = acc;
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
          a.href = h.url;
          a.textContent = h.title + (h.section ? ' — ' + h.section : '');
          li.appendChild(a);
          ul.appendChild(li);
        });
        sources.appendChild(ul);
        sources.hidden = false;
      }).catch(function () {});
    }

    // Lazy: only probe availability after the user shows intent (focus/expand).
    var probed = false;
    function probe() {
      if (probed) return; probed = true;
      setEl(status, t('aiChecking', 'Checking on-device AI…'));
      checkAvailability().then(function (a) {
        if (a === 'available') {
          ensureSession().then(markReady).catch(function () {
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
      if (!ready) { probe(); return; }
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
