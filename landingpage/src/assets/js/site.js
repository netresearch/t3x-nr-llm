/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/*
 * Progressive enhancement for the nr-llm landing site:
 * Theme and global navigation are owned by the shared site shell.
 *   - section scrollspy that marks the current in-page nav link
 *   - mobile navigation toggle
 * Everything degrades gracefully: without JS the page renders in the OS-preferred theme
 * (via CSS prefers-color-scheme) and all navigation links work as plain anchors.
 */
(function () {
  'use strict';

  /* ---- Scrollspy ---- */
  function initScrollspy() {
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-nr-page-navigation] a[href^="#"]'));
    if (!links.length || !('IntersectionObserver' in window)) return;
    var map = {};
    var targets = [];
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1);
      var el = id && document.getElementById(id);
      if (el) { if (!map[id]) { map[id] = []; targets.push(el); } map[id].push(a); }
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
      if (current) { current.forEach(function (link) { link.removeAttribute('aria-current'); }); current = null; }
      if (activeId && map[activeId]) { current = map[activeId]; current.forEach(function (link) { link.setAttribute('aria-current', 'true'); }); }
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
    targets.forEach(function (el) { obs.observe(el); });
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
    initScrollspy();
    initCopy();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
