/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Overview provider reachability dots (ES6 module).
 *
 * Loads the reachability status asynchronously so a slow or unreachable
 * provider never blocks the overview render. The backend probe is token-free
 * (it pings each provider's model-list/health endpoint, no completion) and is
 * cached server-side. On any error the dots stay in their neutral "unknown"
 * state.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class OverviewReachability {
  constructor() {
    // Status words for the aria-label (screen-reader status, not colour-only).
    // English fallbacks; the template may override with localized values via
    // data-label-* on the reachability container.
    this.labels = { up: 'reachable', down: 'unreachable', unknown: 'status unknown' };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.init());
    } else {
      this.init();
    }
  }

  init() {
    const container = document.querySelector('.nrllm-ov-reach');
    if (container !== null) {
      this.labels = {
        up: container.dataset.labelUp || this.labels.up,
        down: container.dataset.labelDown || this.labels.down,
        unknown: container.dataset.labelUnknown || this.labels.unknown,
      };
    }

    this.load();

    const recheck = document.querySelector('[data-nrllm-recheck]');
    if (recheck !== null) {
      recheck.addEventListener('click', (event) => {
        event.preventDefault();
        this.reset();
        this.load();
      });
    }
  }

  load() {
    const url = TYPO3.settings?.ajaxUrls?.['nrllm_overview_reachability'];
    if (!url) {
      return;
    }

    new AjaxRequest(url)
      .get()
      .then((response) => response.resolve())
      .then((data) => this.apply(data))
      .catch(() => {
        /* leave dots in the neutral "unknown" state on failure */
      });
  }

  /**
   * @param {{providers?: Array<{identifier: string, status: string}>}} data
   */
  apply(data) {
    const providers = Array.isArray(data?.providers) ? data.providers : [];
    providers.forEach((provider) => {
      if (!provider || typeof provider.identifier !== 'string') {
        return;
      }
      // Attribute-selector-safe lookup: identifiers are [a-z0-9_-] tokens.
      const badge = document.querySelector(
        `[data-nrllm-provider="${CSS.escape(provider.identifier)}"]`,
      );
      this.setBadge(badge, provider.status);
    });
  }

  /**
   * Set a provider badge's state. Status is reflected by the glyph (via the
   * state class) AND announced to assistive tech via aria-label, so it never
   * relies on colour alone (WCAG 1.4.1).
   *
   * @param {Element|null} badge
   * @param {string} status
   */
  setBadge(badge, status) {
    if (badge === null) {
      return;
    }
    const name = badge.textContent.trim();
    const word = this.labels[status] || this.labels.unknown;
    badge.classList.remove('is-unknown', 'is-up', 'is-down');
    // up = reachable, down = configured but unreachable, anything else
    // (e.g. "unconfigured") stays the neutral unknown badge.
    if (status === 'up') {
      badge.classList.add('is-up');
    } else if (status === 'down') {
      badge.classList.add('is-down');
    } else {
      badge.classList.add('is-unknown');
    }
    badge.setAttribute('aria-label', `${name}: ${word}`);
    badge.setAttribute('title', word);
  }

  reset() {
    const word = this.labels.unknown;
    document.querySelectorAll('.nrllm-ov-reach-badge').forEach((badge) => {
      badge.classList.remove('is-up', 'is-down');
      badge.classList.add('is-unknown');
      const name = badge.textContent.trim();
      badge.setAttribute('aria-label', `${name}: ${word}`);
      badge.setAttribute('title', word);
    });
  }
}

export default new OverviewReachability();
