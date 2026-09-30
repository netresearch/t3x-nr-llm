# Security Policy

## Supported Versions

Only the latest release line receives bug fixes and security fixes. A fix
ships as the next patch or minor release from `main`; no fix is backported to
an older release line.

| Version | Bug fixes          | Security fixes     |
| ------- | ------------------ | ------------------ |
| 0.38.x  | :white_check_mark: | :white_check_mark: |
| < 0.38  | :x:                | :x:                |

- **End of support:** a release line `0.N.x` stops receiving bug fixes and
  security fixes on the day `0.(N+1).0` is released. From then on, the fix for
  a vulnerability is in the newest release only, and users of an older line
  upgrade to it.
- **Upgrading within 0.x:** while the extension is pre-1.0, a minor release
  may contain breaking changes, and each one is listed in `CHANGELOG.md` under
  a BREAKING heading ([API stability](Documentation/Api/Stability.rst)).
- **Getting support:** questions and bug reports go to the
  [issue tracker](https://github.com/netresearch/t3x-nr-llm/issues) or
  [discussions](https://github.com/netresearch/t3x-nr-llm/discussions);
  vulnerabilities are reported privately as described below.

The table is updated by the release commit of every minor release;
`Tests/Unit/VersionConsistencyTest.php` fails when it names a line other than
the one `ext_emconf.php` is on.

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

- **API Keys**: Enter provider API keys through the provider record, the setup
  wizard or `vendor/bin/typo3 nrllm:provider:set-key` (reads the key from
  STDIN). All three store the key in nr-vault; the provider record keeps only
  the vault identifier, never the key itself
- **Rate Limiting**: Implement rate limiting for public-facing LLM endpoints
- **Input Validation**: Always validate and sanitize user inputs before sending to LLM providers
- **Output Sanitization**: Treat LLM responses as untrusted content
- **Logging**: Avoid logging sensitive prompts or API keys

## Acknowledgments

We appreciate security researchers who help keep this project safe. Contributors will be acknowledged (with permission) in release notes.
