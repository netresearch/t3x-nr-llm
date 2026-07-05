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
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.init());
    } else {
      this.init();
    }
  }

  init() {
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
      const dot = document.querySelector(
        `[data-nrllm-provider="${CSS.escape(provider.identifier)}"]`,
      );
      this.setDot(dot, provider.status);
    });
  }

  /**
   * @param {Element|null} dot
   * @param {string} status
   */
  setDot(dot, status) {
    if (dot === null) {
      return;
    }
    dot.classList.remove('nrllm-ov-dot-unknown', 'nrllm-ov-dot-up', 'nrllm-ov-dot-down');
    // up = reachable, down = configured-with-key but unreachable, anything
    // else (e.g. "unconfigured") stays the neutral unknown dot.
    if (status === 'up') {
      dot.classList.add('nrllm-ov-dot-up');
    } else if (status === 'down') {
      dot.classList.add('nrllm-ov-dot-down');
    } else {
      dot.classList.add('nrllm-ov-dot-unknown');
    }
  }

  reset() {
    document.querySelectorAll('.nrllm-ov-dot').forEach((dot) => {
      dot.classList.remove('nrllm-ov-dot-up', 'nrllm-ov-dot-down');
      dot.classList.add('nrllm-ov-dot-unknown');
    });
  }
}

export default new OverviewReachability();
