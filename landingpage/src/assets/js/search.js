/*
 * Client-side full-text search for the nr-llm landing site.
 * - Loads a prebuilt JSON index and builds a MiniSearch instance (shared singleton).
 * - Enhances any [data-search] element into an accessible combobox.
 * Progressive enhancement: without JS the page stays fully usable; the search UI
 * is only revealed once this script runs.
 *
 * Runtime config is injected by the page as window.__NRLLM__:
 *   { indexUrl: string, lang: string, strings: { … } }
 */
(function () {
  'use strict';

  var CFG = window.__NRLLM__ || {};
  var S = CFG.strings || {};

  /* ---- Shared index singleton (also consumed by the AI assistant) ---- */
  var indexPromise = null;

  function loadIndex() {
    if (indexPromise) return indexPromise;
    if (!window.MiniSearch || !CFG.indexUrl) {
      indexPromise = Promise.reject(new Error('search unavailable'));
      return indexPromise;
    }
    indexPromise = fetch(CFG.indexUrl, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('index HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        var docs = data.documents || data || [];
        var mini = new window.MiniSearch({
          idField: 'id',
          fields: ['title', 'text', 'section'],
          storeFields: ['title', 'text', 'url', 'section', 'kind', 'lang'],
          searchOptions: {
            boost: { title: 3, section: 2 },
            prefix: true,
            fuzzy: 0.2,
          },
        });
        mini.addAll(docs);
        return { mini: mini, docs: docs };
      });
    return indexPromise;
  }

  /* Filter to the active language plus language-neutral docs (ADRs). */
  function langFilter(result) {
    if (!result.lang || result.lang === 'all') return true;
    return result.lang === (CFG.lang || 'en');
  }

  function runSearch(query, limit) {
    return loadIndex().then(function (idx) {
      var hits = idx.mini.search(query, { filter: langFilter });
      return hits.slice(0, limit || 8);
    });
  }

  /* Expose for the AI assistant (RAG-lite grounding). */
  window.NrllmSearch = { load: loadIndex, search: runSearch };

  /* ---- Accessible combobox UI ---- */
  function clearNode(el) { while (el.firstChild) el.removeChild(el.firstChild); }

  function highlight(text, query) {
    var frag = document.createDocumentFragment();
    if (!text) return frag;
    var terms = query.trim().split(/\s+/).filter(Boolean).map(function (t) {
      return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    });
    if (!terms.length) { frag.appendChild(document.createTextNode(text)); return frag; }
    // terms are regex-escaped literals joined by alternation — no ReDoS surface
    var re = new RegExp('(' + terms.join('|') + ')', 'ig'); // nosemgrep: javascript.lang.security.audit.detect-non-literal-regexp.detect-non-literal-regexp
    var last = 0;
    Array.from(text.matchAll(re)).forEach(function (m) {
      if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
      var mark = document.createElement('mark');
      mark.textContent = m[0];
      frag.appendChild(mark);
      last = m.index + m[0].length;
    });
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    return frag;
  }

  function snippet(text, max) {
    if (!text) return '';
    text = text.replace(/\s+/g, ' ').trim();
    return text.length > max ? text.slice(0, max - 1).trimEnd() + '…' : text;
  }

  function enhance(root) {
    var input = root.querySelector('[data-search-input]');
    var list = root.querySelector('[data-search-results]');
    var status = root.querySelector('[data-search-status]');
    if (!input || !list) return;

    var listId = list.id || 'nrllm-search-results';
    list.id = listId;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('autocomplete', 'off');
    list.setAttribute('role', 'listbox');
    root.hidden = false;

    var active = -1;
    var current = [];
    var debounceTimer = null;

    function close() {
      clearNode(list);
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
      current = [];
    }

    function announce(n, q) {
      if (!status) return;
      status.textContent = q
        ? (n ? (S.searchResults || '{n} results').replace('{n}', n) : (S.searchNoResults || 'No results'))
        : '';
    }

    function render(results, q) {
      clearNode(list);
      current = results;
      if (!results.length) { close(); announce(0, q); return; }
      results.forEach(function (r, i) {
        var li = document.createElement('li');
        li.id = listId + '-opt-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.className = 'search-result';
        var a = document.createElement('a');
        a.href = r.url;
        a.tabIndex = -1;
        var t = document.createElement('span');
        t.className = 'search-result__title';
        t.appendChild(highlight(r.title, q));
        var meta = document.createElement('span');
        meta.className = 'search-result__meta';
        meta.textContent = r.section || (r.kind === 'adr' ? 'ADR' : '');
        var ex = document.createElement('span');
        ex.className = 'search-result__excerpt';
        ex.appendChild(highlight(snippet(r.text, 120), q));
        a.appendChild(t); a.appendChild(meta); a.appendChild(ex);
        li.appendChild(a);
        li.addEventListener('mousedown', function (e) { e.preventDefault(); window.location.href = r.url; });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      announce(results.length, q);
    }

    function setActive(i) {
      var opts = list.querySelectorAll('[role="option"]');
      opts.forEach(function (o) { o.setAttribute('aria-selected', 'false'); o.classList.remove('is-active'); });
      if (i < 0 || i >= opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
      active = i;
      opts[i].setAttribute('aria-selected', 'true');
      opts[i].classList.add('is-active');
      opts[i].scrollIntoView({ block: 'nearest' });
      input.setAttribute('aria-activedescendant', opts[i].id);
    }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      clearTimeout(debounceTimer);
      if (q.length < 2) { close(); announce(0, ''); return; }
      debounceTimer = setTimeout(function () {
        runSearch(q, 8).then(function (results) { render(results, q); })
          .catch(function () { close(); });
      }, 120);
    });

    input.addEventListener('keydown', function (e) {
      var opts = list.querySelectorAll('[role="option"]');
      if (e.key === 'ArrowDown') { e.preventDefault(); if (opts.length) setActive((active + 1) % opts.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (opts.length) setActive((active - 1 + opts.length) % opts.length); }
      else if (e.key === 'Enter') { if (active >= 0 && current[active]) { e.preventDefault(); window.location.href = current[active].url; } }
      else if (e.key === 'Escape') { close(); input.blur(); }
    });

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) close();
    });
    list.hidden = true;
  }

  function init() {
    var roots = document.querySelectorAll('[data-search]');
    if (!roots.length) return;
    var warmed = false;
    roots.forEach(function (root) {
      enhance(root);
      var input = root.querySelector('[data-search-input]');
      if (input) input.addEventListener('focus', function () {
        if (!warmed) { warmed = true; loadIndex().catch(function () {}); }
      }, { once: true });
    });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
