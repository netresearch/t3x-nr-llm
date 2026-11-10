- Override the development-only `minimatch` lock entry to 11.0.6 to address
  [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249);
  ESLint still requires `^10`, which has no patched release. The extension does
  not ship `minimatch`.
