# Resources Directory

> Static assets and templates for nr_llm TYPO3 extension

## Overview

Fluid templates, language files, icons, CSS, and JavaScript for the backend module.

## Structure

```
Resources/
├── Private/
│   ├── Language/           # XLIFF translation files
│   │   ├── locallang.xlf
│   │   ├── locallang_tca.xlf
│   │   ├── locallang_mod.xlf
│   │   └── locallang_mod_*.xlf
│   ├── Templates/Backend/  # Fluid templates
│   │   ├── Index.html
│   │   ├── Provider/List.html
│   │   ├── Model/List.html
│   │   ├── Configuration/List.html
│   │   ├── Task/List.html
│   │   └── SetupWizard/Index.html
│   └── Data/
│       └── DefaultPrompts.php
└── Public/
    ├── Icons/              # SVG icons
    │   ├── Extension.svg   # Netresearch logo
    │   ├── Provider.svg
    │   ├── Model.svg
    │   └── provider-*.svg  # Provider-specific icons
    ├── Css/Backend/
    │   └── SetupWizard.css
    └── JavaScript/Backend/
        ├── ProviderList.js
        ├── ModelList.js
        ├── ConfigurationList.js
        └── SetupWizard.js
```

## Language Files

| File | Purpose |
|------|---------|
| `locallang.xlf` | General labels |
| `locallang_tca.xlf` | TCA field labels |
| `locallang_mod.xlf` | Backend module labels |
| `locallang_mod_*.xlf` | Module-specific labels |

## Patterns

### Adding a Translation

```xml
<!-- locallang.xlf -->
<trans-unit id="my.label" resname="my.label">
    <source>My Label</source>
</trans-unit>
```

### Using in Fluid

```html
<f:translate key="LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:my.label" />
```

### Adding Icons

Place SVG files in `Public/Icons/` and register in `Configuration/Icons.php`:

```php
return [
    'nr-llm-my-icon' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_llm/Resources/Public/Icons/MyIcon.svg',
    ],
];
```

## Critical Rules

1. **Extension.svg** must be Netresearch symbol-only logo
2. All icons in SVG format
3. XLIFF 1.2 format for translations
4. JavaScript uses ES modules via `@typo3/` imports
5. CSS uses TYPO3 backend variables for consistency
