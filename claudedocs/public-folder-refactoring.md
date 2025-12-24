# Public Folder Refactoring

## Summary

Refactored the `public/` folder structure according to TYPO3 DDEV best practices. The landing page is now generated at container build time and copied at runtime, rather than being committed to the repository.

## Changes Made

### 1. Landing Page Location
- **Before**: `/public/index.html` (committed to git)
- **After**: `/.ddev/web-build/index.html` (committed, deployed at runtime)

### 2. Branding Update
- Applied Netresearch branding to landing page:
  - Turquoise color scheme (#2F99A4)
  - Raleway font family
  - Netresearch branding footer

### 3. Docker Configuration
- **File**: `.ddev/web-build/Dockerfile`
- **Change**: Added `COPY index.html /tmp/landing-index.html` to copy landing page to temporary location during container build

### 4. Runtime Deployment
- **File**: `.ddev/config.yaml`
- **Change**: Added post-start hook to copy landing page from `/tmp/landing-index.html` to `/var/www/html/index.html` on every `ddev start`

### 5. Volume Binding
- **File**: `.ddev/docker-compose.web.yaml`
- **Change**: Removed bind-mount for `public/` folder (was binding `../public` to `/var/www/html`)

### 6. Git Ignore
- **File**: `.gitignore`
- **Change**: Updated to ignore entire `public/` directory while preserving `.git-info.json`:
  ```
  public/
  !public/.git-info.json
  ```

### 7. Directory Cleanup
- **Action**: Deleted entire `public/` directory from repository

## How It Works

1. **Build Time**: When DDEV builds the web container, the Dockerfile copies `index.html` from `.ddev/web-build/` to `/tmp/landing-index.html`

2. **Runtime**: On every `ddev start`, the post-start hook copies `/tmp/landing-index.html` to `/var/www/html/index.html`

3. **Git Info**: The existing git info hook still creates `/var/www/html/.git-info.json` at runtime

## Benefits

- Landing page is no longer committed in `public/` folder
- Follows TYPO3 DDEV skill best practices
- Clean separation between committed config and runtime files
- Easy to update landing page (edit in `.ddev/web-build/index.html`)
- Netresearch branding consistently applied

## Files Modified

1. `/.ddev/web-build/index.html` (created)
2. `/.ddev/web-build/Dockerfile` (modified)
3. `/.ddev/config.yaml` (modified)
4. `/.ddev/docker-compose.web.yaml` (modified)
5. `/.gitignore` (modified)
6. `/public/` (deleted)

## Testing

After applying these changes:

1. Rebuild DDEV container: `ddev restart --rebuild`
2. Check landing page: Visit `https://nr-llm.ddev.site/`
3. Verify branding: Page should show Netresearch turquoise colors and Raleway font
4. Verify git info: Check that `.git-info.json` is still created at `/var/www/html/.git-info.json`

## Rollback

If needed, to rollback:
1. Restore `public/index.html` from git history
2. Restore original `.ddev/docker-compose.web.yaml` bind-mount
3. Remove landing page copy from `.ddev/config.yaml` post-start hook
4. Remove COPY command from `.ddev/web-build/Dockerfile`
5. Restore original `.gitignore` entry
