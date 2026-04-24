/**
 * Toggle visibility of the "new configuration" details panel based on the
 * selected radio in the chain wizard preview.
 *
 * Loaded as an external module to satisfy CSP (no inline <script>).
 */
document.addEventListener('DOMContentLoaded', function () {
    const configRadios = document.querySelectorAll('input[name="config_choice"]');
    const configNewDetails = document.getElementById('config-new-details');

    if (!configRadios.length || !configNewDetails) {
        return;
    }

    configRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            configNewDetails.style.display = this.value === 'new' ? '' : 'none';
        });
    });
});
