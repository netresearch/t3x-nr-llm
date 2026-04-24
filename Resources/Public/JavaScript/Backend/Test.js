/**
 * Wires the test prompt form to the nrllm_test AJAX endpoint and renders
 * the response (success, error, usage details).
 *
 * Loaded as an external module to satisfy CSP (no inline <script>).
 * The script is included via HeaderAssets, so it runs before <body> is
 * parsed — wrap in DOMContentLoaded and bail out if required elements
 * or the AJAX URL are missing.
 */
document.addEventListener('DOMContentLoaded', function () {
    const testForm = document.getElementById('testForm');
    const ajaxUrl = TYPO3?.settings?.ajaxUrls?.['nrllm_test'];
    if (!testForm || !ajaxUrl) {
        return;
    }

    testForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const provider = document.getElementById('provider').value;
        const prompt = document.getElementById('prompt').value;

        document.getElementById('loadingIndicator').style.display = 'block';
        document.getElementById('responseContainer').style.display = 'none';

        try {
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ provider, prompt }),
            });

            const data = await response.json();

            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('responseContainer').style.display = 'block';

            if (data.success) {
                document.getElementById('responseSuccess').style.display = 'block';
                document.getElementById('responseError').style.display = 'none';
                document.getElementById('responseDetails').style.display = 'block';

                document.getElementById('responseContent').textContent = data.content;
                document.getElementById('responseModel').textContent = data.model;
                document.getElementById('promptTokens').textContent = data.usage.promptTokens;
                document.getElementById('completionTokens').textContent = data.usage.completionTokens;
                document.getElementById('totalTokens').textContent = data.usage.totalTokens;
            } else {
                document.getElementById('responseSuccess').style.display = 'none';
                document.getElementById('responseError').style.display = 'block';
                document.getElementById('responseDetails').style.display = 'none';
                document.getElementById('errorMessage').textContent = data.error;
            }
        } catch (error) {
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('responseContainer').style.display = 'block';
            document.getElementById('responseSuccess').style.display = 'none';
            document.getElementById('responseError').style.display = 'block';
            document.getElementById('responseDetails').style.display = 'none';
            document.getElementById('errorMessage').textContent = error.message;
        }
    });
});
