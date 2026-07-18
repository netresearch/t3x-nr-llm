# nr-llm landing page

Static, bilingual (EN/DE) landing page for the `nr-llm` TYPO3 extension, plus a
rendered set of all Architecture Decision Records (ADRs). Built with a small Python
generator and deployed to GitHub Pages by `.github/workflows/pages.yml`.

## Build

```bash
uv run landingpage/build/build.py
# output -> landingpage/public/
```

Environment variables (both optional; the Pages workflow sets them from the Pages
configuration so a repo rename or custom domain needs no code change):

| Var | Default | Meaning |
|-----|---------|---------|
| `NRLLM_BASE` | `/t3x-nr-llm/` | Base path the site is served under (`/` for a custom domain). |
| `NRLLM_ORIGIN` | `https://netresearch.github.io` | Scheme + host, used for canonical / OG / sitemap URLs. |

Local preview (root-relative URLs so a plain file server works):

```bash
NRLLM_BASE=/ NRLLM_ORIGIN=http://localhost:8137 uv run landingpage/build/build.py
python3 -m http.server 8137 -d landingpage/public
# open http://localhost:8137/en/
```

## Layout

```
landingpage/
├── build/
│   ├── build.py          # generator (Jinja2 + docutils), PEP 723 inline deps
│   └── data/*.json        # content model: content(.en), content_de, adr, brand, seo
├── src/
│   ├── templates/*.j2     # base, landing, adr_index, adr_page, root
│   └── assets/            # css, js (search + on-device AI + site), fonts, img
└── public/                # build output (git-ignored)
```

## Content

Landing copy lives in `build/data/content.json` (English) and `content_de.json`
(German). Both share the same shape; templates render them into `/en/` and `/de/`.
ADR pages are rendered from `Documentation/Adr/*.rst` at build time (docutils, with
stub directives/roles for TYPO3-specific RST constructs).

## Features

- Separate `/en/` + `/de/` pages with `hreflang` alternates and an `x-default` root.
- SEO: canonical, Open Graph / Twitter cards, `sitemap.xml`, `robots.txt`, semantic
  landmarks, one `<h1>` per page.
- Machine-readable / GEO: JSON-LD (`SoftwareApplication`, `Organization`, `FAQPage`,
  `BreadcrumbList`) and an `llms.txt` project profile.
- Accessibility: WCAG 2.1 AA (verified with axe-core — 0 violations), keyboard
  operation, visible focus, light/dark themes, `prefers-reduced-motion`.
- Client-side full-text search over the landing content and all ADRs (MiniSearch,
  vendored locally).
- Optional on-device Q&A ("Ask nr-llm") using Chrome's built-in Prompt API
  (Gemini Nano); progressive enhancement with a graceful fallback when unavailable.

## Deployment

Pushing to `main` with changes under `landingpage/**` triggers the **Landing Page**
workflow, which builds the site and publishes it to GitHub Pages. Enable Pages once in
repository settings with **Source: GitHub Actions**.
