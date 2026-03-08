# PHP 8.2-8.5 + TYPO3 v13.4/v14 Compatibility Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Widen both `netresearch/nr-vault` and `netresearch/nr-llm` to support PHP 8.2-8.5 and TYPO3 v13.4 LTS + v14.

**Architecture:** Both extensions already use PHP 8.1+ features (readonly classes, enums, match) and TYPO3 APIs available since v12/v13. No code rewrites needed - this is a constraint-widening + testing exercise. nr-vault is upgraded first since nr-llm depends on it.

**Tech Stack:** PHP 8.2-8.5, TYPO3 v13.4 LTS / v14, PHPUnit 11/12, PHPStan, php-cs-fixer, Rector

---

## Part 1: nr-vault (netresearch/nr-vault)

Working directory: `/home/cybot/projects/t3x-nr-vault/main/`

### Task 1: Create feature branch for nr-vault

**Files:**
- None (git operation only)

**Step 1: Create worktree for the feature branch**

```bash
cd /home/cybot/projects/t3x-nr-vault
git -C .bare fetch origin
git -C .bare worktree add ../feat/php82-typo3-v13 -b feat/php82-typo3-v13 origin/main
```

**Step 2: Verify worktree**

```bash
cd /home/cybot/projects/t3x-nr-vault/feat/php82-typo3-v13
git log --oneline -3
```

Expected: Shows recent commits from main.

---

### Task 2: Update nr-vault composer.json constraints

**Files:**
- Modify: `composer.json` (lines 28-35 require, lines 37-46 require-dev)

**Step 1: Update PHP and TYPO3 version constraints**

In `composer.json`, change:

```json
"require": {
    "php": "^8.2",
    "ext-sodium": "*",
    "ext-json": "*",
    "typo3/cms-core": "^13.4 || ^14.0",
    "typo3/cms-backend": "^13.4 || ^14.0",
    "psr/http-client": "^1.0",
    "psr/http-factory": "^1.0"
},
"require-dev": {
    "typo3/testing-framework": "^8.2 || ^9.0",
    "typo3/cms-scheduler": "^13.4 || ^14.0",
    ...
}
```

**Step 2: Run `composer validate`**

```bash
composer validate --strict
```

Expected: No errors.

**Step 3: Commit**

```bash
git add composer.json
git commit -S --signoff -m "feat: widen PHP to ^8.2 and TYPO3 to ^13.4 || ^14.0"
```

---

### Task 3: Update nr-vault ext_emconf.php

**Files:**
- Modify: `ext_emconf.php` (lines 17-21)

**Step 1: Widen version constraints**

Change:
```php
'constraints' => [
    'depends' => [
        'typo3' => '13.4.0-14.99.99',
        'php' => '8.2.0-8.99.99',
    ],
```

**Step 2: Commit**

```bash
git add ext_emconf.php
git commit -S --signoff -m "feat: widen ext_emconf.php to PHP 8.2+ and TYPO3 v13.4+"
```

---

### Task 4: Convert nr-vault module labels to LLL:EXT: format

**Files:**
- Modify: `Configuration/Backend/Modules.php` (lines 35, 54, 83, 115)

**Step 1: Convert short label format to LLL:EXT: format**

The v14 short format `'nr_vault.modules.overview'` maps to the XLF file at
`Resources/Private/Language/Modules/overview.xlf`. Convert all four modules:

```php
// Line 35: Change
'labels' => 'nr_vault.modules.overview',
// To:
'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/overview.xlf',

// Line 54: Change
'labels' => 'nr_vault.modules.secrets',
// To:
'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/secrets.xlf',

// Line 83: Change
'labels' => 'nr_vault.modules.audit',
// To:
'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/audit.xlf',

// Line 115: Change
'labels' => 'nr_vault.modules.migration',
// To:
'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/migration.xlf',
```

Also update the comment at line 22-23 to remove the v14-specific mention:
```php
// Before:
 * Uses TYPO3 v14 short label format:
 * - 'nr_vault.modules.overview' maps to EXT:nr_vault/Resources/Private/Language/Modules/overview.xlf
// After:
 * Uses LLL:EXT: label format (compatible with TYPO3 v13+v14)
```

**Step 2: Commit**

```bash
git add Configuration/Backend/Modules.php
git commit -S --signoff -m "fix: use LLL:EXT: module labels for TYPO3 v13 compatibility"
```

---

### Task 5: Update nr-vault php-cs-fixer config

**Files:**
- Modify: `.php-cs-fixer.dist.php` (line 26)

**Step 1: Change PHP migration set from 8.5 to 8.2**

```php
// Line 26: Change
'@PHP8x5Migration' => true,
// To:
'@PHP82Migration' => true,
```

**Step 2: Run php-cs-fixer to verify no issues**

```bash
.Build/bin/php-cs-fixer fix --dry-run --diff
```

Expected: Either clean or shows only cosmetic differences (if any 8.5 migration rules had been applied).

**Step 3: If php-cs-fixer reports changes, apply them**

```bash
.Build/bin/php-cs-fixer fix
```

**Step 4: Commit**

```bash
git add .php-cs-fixer.dist.php
# If code was reformatted, add those files too
git commit -S --signoff -m "chore: change php-cs-fixer migration set to PHP 8.2"
```

---

### Task 6: Update nr-vault PHPStan config

**Files:**
- Modify: `phpstan.neon` (line 18)

**Step 1: Change PHP version from 80500 to 80200**

```yaml
# Line 18: Change
phpVersion: 80500
# To:
phpVersion: 80200
```

**Step 2: Run PHPStan to check for new errors**

```bash
.Build/bin/phpstan analyse
```

Expected: Either clean or shows new errors that need baseline updates.

**Step 3: If new errors appear, regenerate baseline**

```bash
.Build/bin/phpstan analyse --generate-baseline
```

**Step 4: Commit**

```bash
git add phpstan.neon phpstan-baseline.neon
git commit -S --signoff -m "chore: target PHPStan analysis at PHP 8.2"
```

---

### Task 7: Update nr-vault Rector config

**Files:**
- Modify: `rector.php` (line 23)

**Step 1: Change PHP set from 8.5 to 8.2**

```php
// Line 23: Change
->withPhpSets(php85: true)
// To:
->withPhpSets(php82: true)
```

Note: Keep `Typo3LevelSetList::UP_TO_TYPO3_14` - Rector TYPO3 sets handle both v13 and v14.

**Step 2: Dry-run Rector to verify**

```bash
.Build/bin/rector process --dry-run
```

Expected: Either clean or shows changes. Review carefully - some changes may downgrade PHP features we want to keep.

**Step 3: Commit**

```bash
git add rector.php
git commit -S --signoff -m "chore: target Rector at PHP 8.2 minimum"
```

---

### Task 8: Update nr-vault Site Sets for v13 compat

**Files:**
- Modify: `Configuration/Sets/NrVault/config.yaml` (line 2)

**Step 1: Remove v14-specific comment**

```yaml
# Before:
# TYPO3 v14 Site Sets pattern
# After:
# TYPO3 v13+ Site Sets
```

**Step 2: Verify settings.yaml compatibility**

Site Sets `settings.yaml` was named `settings.definitions.yaml` in early TYPO3 v13 but `settings.yaml` is the standard name since v13.3. Since we target v13.4 LTS, `settings.yaml` should work. No rename needed.

**Step 3: Commit**

```bash
git add Configuration/Sets/NrVault/config.yaml
git commit -S --signoff -m "chore: update Site Sets comment for v13+ compat"
```

---

### Task 9: Update nr-vault CI workflow

**Files:**
- Modify: `.github/workflows/ci.yml` (lines 12-13)

**Step 1: Expand PHP and TYPO3 version matrix**

```yaml
    with:
      php-versions: '["8.2", "8.3", "8.4", "8.5"]'
      typo3-versions: '["^13.4", "^14.0"]'
      typo3-packages: '["typo3/cms-core", "typo3/cms-backend"]'
      php-extensions: intl, mbstring, xml, sodium, json
      run-functional-tests: true
```

**Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -S --signoff -m "ci: expand matrix to PHP 8.2-8.5 and TYPO3 v13.4/v14"
```

---

### Task 10: Install dependencies and run tests for nr-vault

**Step 1: Install composer dependencies**

```bash
composer install
```

Expected: Successful install (may need `--no-scripts` if grumphp or other hooks fail).

**Step 2: Run unit tests**

```bash
composer ci:test:php:unit
```

Expected: All tests pass.

**Step 3: Run PHPStan**

```bash
composer ci:test:php:phpstan
```

Expected: Clean or only baseline issues.

**Step 4: Run coding standards**

```bash
composer ci:test:php:cgl
```

Expected: Clean.

**Step 5: Push branch**

```bash
git push -u origin feat/php82-typo3-v13
```

---

## Part 2: nr-llm (netresearch/nr-llm)

Working directory: `/home/cybot/projects/t3x-nr-llm/main/`

### Task 11: Create feature branch for nr-llm

**Files:**
- None (git operation only)

**Step 1: Create worktree for the feature branch**

```bash
cd /home/cybot/projects/t3x-nr-llm
git -C .bare fetch origin
git -C .bare worktree add ../feat/php82-typo3-v13 -b feat/php82-typo3-v13 origin/main
```

**Step 2: Verify worktree**

```bash
cd /home/cybot/projects/t3x-nr-llm/feat/php82-typo3-v13
git log --oneline -3
```

---

### Task 12: Update nr-llm composer.json constraints

**Files:**
- Modify: `composer.json` (lines 28-34 require, lines 36-50 require-dev)

**Step 1: Update PHP and TYPO3 version constraints**

```json
"require": {
    "php": "^8.2",
    "netresearch/nr-vault": "^0.4.0",
    "psr/http-client": "^1.0",
    "psr/http-factory": "^1.0",
    "psr/log": "^2.0 || ^3.0",
    "typo3/cms-core": "^13.4 || ^14.0"
},
```

Note: Update `nr-vault` to `^0.4.0` (the version that will include v13 support from Part 1).

**Step 2: Update require-dev**

```json
"require-dev": {
    ...
    "phpunit/phpunit": "^11.0 || ^12.0",
    ...
    "typo3/cms-install": "^13.4 || ^14.0",
    "typo3/testing-framework": "^8.2 || ^9.0"
}
```

**Step 3: Run `composer validate`**

```bash
composer validate --strict
```

**Step 4: Commit**

```bash
git add composer.json
git commit -S --signoff -m "feat: widen PHP to ^8.2 and TYPO3 to ^13.4 || ^14.0"
```

---

### Task 13: Update nr-llm ext_emconf.php

**Files:**
- Modify: `ext_emconf.php` (lines 17-21)

**Step 1: Widen version constraints**

```php
'constraints' => [
    'depends' => [
        'typo3' => '13.4.0-14.99.99',
        'php' => '8.2.0-8.99.99',
    ],
```

**Step 2: Commit**

```bash
git add ext_emconf.php
git commit -S --signoff -m "feat: widen ext_emconf.php to PHP 8.2+ and TYPO3 v13.4+"
```

---

### Task 14: Update nr-llm ext_localconf.php comment

**Files:**
- Modify: `ext_localconf.php` (line 15)

**Step 1: Update comment**

```php
// Before:
    // Cache configuration is in Configuration/Caching.php (TYPO3 v14+)
// After:
    // Cache configuration is in Configuration/Caching.php (TYPO3 v13+)
```

**Step 2: Commit**

```bash
git add ext_localconf.php
git commit -S --signoff -m "fix: correct cache config comment to v13+"
```

---

### Task 15: Update nr-llm PHPStan config

**Files:**
- Modify: `Build/phpstan/phpstan.neon` (line 17)

**Step 1: Change PHP version from 80500 to 80200**

```yaml
# Line 17: Change
  phpVersion: 80500
# To:
  phpVersion: 80200
```

Also update comments:
```yaml
# Before (lines 6-7):
# - PHP 8.5 compatibility
# After:
# - PHP 8.2+ compatibility
```

And line 16:
```yaml
# Before:
  # PHP 8.5 (TYPO3 v14 minimum)
# After:
  # PHP 8.2 (TYPO3 v13.4 minimum)
```

**Step 2: Run PHPStan**

```bash
composer ci:test:php:phpstan
```

Expected: Clean or new baseline issues.

**Step 3: If needed, regenerate baseline**

```bash
composer ci:test:php:phpstan:baseline
```

**Step 4: Commit**

```bash
git add Build/phpstan/phpstan.neon phpstan-baseline.neon
git commit -S --signoff -m "chore: target PHPStan analysis at PHP 8.2"
```

---

### Task 16: Update nr-llm Rector config

**Files:**
- Modify: `Build/rector/rector.php` (lines 36, 60-61)

**Step 1: Change PHP version target**

```php
// Line 36: Change
->withPhpVersion(PhpVersion::PHP_85)
// To:
->withPhpVersion(PhpVersion::PHP_82)
```

**Step 2: Update ExtEmConfRector**

```php
// Lines 60-61: Change
ExtEmConfRector::PHP_VERSION_CONSTRAINT => '8.5.0-8.99.99',
ExtEmConfRector::TYPO3_VERSION_CONSTRAINT => '14.0.0-14.99.99',
// To:
ExtEmConfRector::PHP_VERSION_CONSTRAINT => '8.2.0-8.99.99',
ExtEmConfRector::TYPO3_VERSION_CONSTRAINT => '13.4.0-14.99.99',
```

Also update comments at lines 11-12:
```php
// Before:
 * - Automated TYPO3 v14 migrations
 * - PHP 8.5 modernization
// After:
 * - Automated TYPO3 v13/v14 migrations
 * - PHP 8.2+ modernization
```

And line 35:
```php
// Before:
    // PHP 8.5 - TYPO3 v14 minimum
// After:
    // PHP 8.2 - TYPO3 v13.4 minimum
```

**Step 3: Dry-run Rector**

```bash
composer ci:test:php:rector
```

**Step 4: Commit**

```bash
git add Build/rector/rector.php
git commit -S --signoff -m "chore: target Rector at PHP 8.2 and TYPO3 v13.4+"
```

---

### Task 17: Update nr-llm CI workflow

**Files:**
- Modify: `.github/workflows/ci.yml` (lines 12-13)

**Step 1: Expand PHP and TYPO3 version matrix**

```yaml
    with:
      php-versions: '["8.2", "8.3", "8.4", "8.5"]'
      typo3-versions: '["^13.4", "^14.0"]'
```

**Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -S --signoff -m "ci: expand matrix to PHP 8.2-8.5 and TYPO3 v13.4/v14"
```

---

### Task 18: Install dependencies and run tests for nr-llm

**Step 1: Install composer dependencies**

```bash
composer install
```

Note: This will fail if nr-vault ^0.4.0 hasn't been released yet. In that case, temporarily use `dev-feat/php82-typo3-v13` as the version constraint, or add a repository entry pointing to the local nr-vault.

**Step 2: Run unit tests**

```bash
composer ci:test:php:unit
```

**Step 3: Run PHPStan**

```bash
composer ci:test:php:phpstan
```

**Step 4: Run coding standards**

```bash
composer ci:test:php:cgl
```

**Step 5: Push branch**

```bash
git push -u origin feat/php82-typo3-v13
```

---

## Post-Implementation Checklist

- [ ] nr-vault: All unit tests pass on PHP 8.2
- [ ] nr-vault: PHPStan clean on PHP 8.2
- [ ] nr-vault: CI matrix green for all PHP/TYPO3 combos
- [ ] nr-vault: Release new version (0.4.0) with widened constraints
- [ ] nr-llm: Update nr-vault dependency to released version
- [ ] nr-llm: All unit tests pass on PHP 8.2
- [ ] nr-llm: PHPStan clean on PHP 8.2
- [ ] nr-llm: CI matrix green for all PHP/TYPO3 combos
- [ ] Both: Verify TYPO3 v14 minimum PHP requirement (exclude invalid combos if needed)
