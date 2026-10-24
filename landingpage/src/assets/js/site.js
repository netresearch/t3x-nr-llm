/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/*
 * Progressive enhancement for the nr-llm landing site:
 *   - theme choice (system/light/dark), defaulting to the OS preference, persisted in localStorage
 *   - section scrollspy that marks the current in-page nav link
 *   - mobile navigation toggle
 * Everything degrades gracefully: without JS the page renders in the OS-preferred theme
 * (via CSS prefers-color-scheme) and all navigation links work as plain anchors.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'nr-theme';
  var root = document.documentElement;

  /* ---- Theme ---- */
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
    else root.removeAttribute('data-theme');
  }

  function storedTheme() {
    try {
      var theme = localStorage.getItem(STORAGE_KEY);
      return theme === null ? localStorage.getItem('nrllm-theme') : theme;
    } catch (e) { return null; }
  }

  function initTheme() {
    applyTheme(storedTheme());
    var control = document.querySelector('[data-theme-control]');
    if (!control) return;
    var saved = storedTheme();
    control.value = saved === 'light' || saved === 'dark' ? saved : 'system';
    control.closest('.theme-toggle').hidden = false;
    control.addEventListener('change', function () {
      applyTheme(control.value);
      try { localStorage.setItem(STORAGE_KEY, control.value); } catch (e) {}
    });
  }

  /* ---- Scrollspy ---- */
  function initScrollspy() {
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-spy] a[href^="#"]'));
    if (!links.length || !('IntersectionObserver' in window)) return;
    var map = {};
    var targets = [];
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1);
      var el = id && document.getElementById(id);
      if (el) { map[id] = a; targets.push(el); }
    });
    var current = null;
    var visible = {};
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) visible[entry.target.id] = true;
        else delete visible[entry.target.id];
      });
      // Highlight the first in-band section in document order; clear the marker
      // entirely when none is in the band (scrolled to the very top or bottom).
      var activeId = null;
      for (var i = 0; i < targets.length; i++) { if (visible[targets[i].id]) { activeId = targets[i].id; break; } }
      if (current) { current.removeAttribute('aria-current'); current = null; }
      if (activeId && map[activeId]) { map[activeId].setAttribute('aria-current', 'true'); current = map[activeId]; }
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
    targets.forEach(function (el) { obs.observe(el); });
  }

  /* ---- Mobile nav ---- */
  function initNavToggle() {
    var btn = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');
    if (!btn || !nav) return;
    var openLabel = btn.getAttribute('aria-label');
    function setOpen(open) {
      nav.setAttribute('data-open', open ? 'true' : 'false');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? btn.getAttribute('data-close-label') : openLabel);
    }
    btn.addEventListener('click', function () {
      var open = nav.getAttribute('data-open') === 'true';
      setOpen(!open);
    });
    nav.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        setOpen(false);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.getAttribute('data-open') === 'true') {
        setOpen(false);
        btn.focus();
      }
    });
  }

  /* ---- Copy-to-clipboard for code blocks ---- */
  function initCopy() {
    if (!navigator.clipboard) return;
    var strings = (window.__NRLLM__ || {}).strings || {};
    var buttons = document.querySelectorAll('[data-copy]');
    if (!buttons.length) return;
    // A single polite live region announces copy success — mutating the focused
    // button's own aria-label is not reliably announced by screen readers.
    var live = document.createElement('div');
    live.className = 'visually-hidden';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);
    buttons.forEach(function (btn) {
      btn.hidden = false;
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-copy');
        var pre = sel && document.getElementById(sel);
        if (!pre) return;
        navigator.clipboard.writeText(pre.textContent).then(function () {
          btn.classList.add('is-copied');
          live.textContent = '';
          live.textContent = strings.copied || 'Copied';
          var prev = btn.getAttribute('aria-label');
          btn.setAttribute('aria-label', strings.copied || 'Copied');
          setTimeout(function () {
            btn.setAttribute('aria-label', prev || (strings.copy || 'Copy'));
            btn.classList.remove('is-copied');
          }, 1500);
        }).catch(function () {});
      });
    });
  }

  function init() {
    initTheme();
    initScrollspy();
    initNavToggle();
    // Collapse navigation only after its controls have working listeners. If
    // this file fails to load, the plain links and hidden inert buttons remain.
    root.classList.add('js');
    initCopy();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
