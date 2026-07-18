/*
 * Progressive enhancement for the nr-llm landing site:
 *   - theme toggle (light/dark), defaulting to the OS preference, persisted in localStorage
 *   - section scrollspy that marks the current in-page nav link
 *   - mobile navigation toggle
 * Everything degrades gracefully: without JS the page renders in the OS-preferred theme
 * (via CSS prefers-color-scheme) and all navigation links work as plain anchors.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'nrllm-theme';
  var root = document.documentElement;

  /* ---- Theme ---- */
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') root.setAttribute('data-theme', theme);
    else root.removeAttribute('data-theme');
  }

  function storedTheme() {
    try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
  }

  function initTheme() {
    applyTheme(storedTheme());
    var toggle = document.querySelector('[data-theme-toggle]');
    if (!toggle) return;
    function currentIsDark() {
      var explicit = root.getAttribute('data-theme');
      if (explicit) return explicit === 'dark';
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    function sync() {
      var dark = currentIsDark();
      toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    sync();
    toggle.addEventListener('click', function () {
      var next = currentIsDark() ? 'light' : 'dark';
      applyTheme(next);
      try { localStorage.setItem(STORAGE_KEY, next); } catch (e) {}
      sync();
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
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          if (current) current.removeAttribute('aria-current');
          var link = map[entry.target.id];
          if (link) { link.setAttribute('aria-current', 'true'); current = link; }
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
    targets.forEach(function (el) { obs.observe(el); });
  }

  /* ---- Mobile nav ---- */
  function initNavToggle() {
    var btn = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');
    if (!btn || !nav) return;
    btn.addEventListener('click', function () {
      var open = nav.getAttribute('data-open') === 'true';
      nav.setAttribute('data-open', open ? 'false' : 'true');
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    nav.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        nav.setAttribute('data-open', 'false');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---- Copy-to-clipboard for code blocks ---- */
  function initCopy() {
    if (!navigator.clipboard) return;
    var strings = (window.__NRLLM__ || {}).strings || {};
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.hidden = false;
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-copy');
        var pre = sel && document.getElementById(sel);
        if (!pre) return;
        navigator.clipboard.writeText(pre.textContent).then(function () {
          var prev = btn.getAttribute('aria-label');
          btn.setAttribute('aria-label', strings.copied || 'Copied');
          btn.classList.add('is-copied');
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
    initCopy();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
