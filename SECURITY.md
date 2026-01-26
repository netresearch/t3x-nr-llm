# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in this extension, please report it responsibly:

1. **Do NOT** open a public GitHub issue
2. Use GitHub's private vulnerability reporting: https://github.com/netresearch/t3x-nr-llm/security/advisories/new
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Any suggested fixes (optional)

## Response Timeline

- **Initial response**: Within 48 hours
- **Status update**: Within 7 days
- **Fix timeline**: Depends on severity (critical: ASAP, high: 2 weeks, medium: 1 month)

## Security Best Practices

When using this extension:

- **API Keys**: Store API keys securely using TYPO3's encryption or environment variables
- **Rate Limiting**: Implement rate limiting for public-facing LLM endpoints
- **Input Validation**: Always validate and sanitize user inputs before sending to LLM providers
- **Output Sanitization**: Treat LLM responses as untrusted content
- **Logging**: Avoid logging sensitive prompts or API keys

## Acknowledgments

We appreciate security researchers who help keep this project safe. Contributors will be acknowledged (with permission) in release notes.
