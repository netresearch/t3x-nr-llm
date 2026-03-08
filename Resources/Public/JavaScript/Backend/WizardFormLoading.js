/**
 * Loading state handler for the AI wizard form.
 *
 * Shows a spinner and elapsed time counter when the form is submitted,
 * since LLM generation can take 10-30 seconds.
 */
(function () {
    'use strict';

    function init() {
        const form = document.getElementById('wizard-form');
        const btn = document.getElementById('wizard-submit-btn');
        const loading = document.getElementById('wizard-loading');
        const elapsed = document.getElementById('wizard-elapsed');

        if (!form || !btn) return;

        form.addEventListener('submit', function (event) {
            const textarea = document.getElementById('wizard-description');
            if (textarea && textarea.value.trim() === '') {
                event.preventDefault();
                textarea.focus();
                textarea.classList.add('is-invalid');
                return;
            }

            const btnText = btn.querySelector('.btn-text');
            const btnLoading = btn.querySelector('.btn-loading');
            if (btnText) btnText.classList.add('d-none');
            if (btnLoading) btnLoading.classList.remove('d-none');
            btn.disabled = true;

            if (textarea) {
                textarea.readOnly = true;
                textarea.classList.remove('is-invalid');
            }

            if (loading) {
                loading.classList.remove('d-none');
                loading.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            if (elapsed) {
                const start = Date.now();
                const timer = setInterval(function () {
                    elapsed.textContent = String(Math.floor((Date.now() - start) / 1000));
                }, 1000);
                // Clear timer on page unload to prevent leaks
                window.addEventListener('pagehide', function () {
                    clearInterval(timer);
                }, { once: true });
            }
        });

        // Remove invalid state when user starts typing
        const textarea = document.getElementById('wizard-description');
        if (textarea) {
            textarea.addEventListener('input', function () {
                textarea.classList.remove('is-invalid');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
