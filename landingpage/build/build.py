# /// script
# requires-python = ">=3.11"
# dependencies = ["jinja2>=3.1", "docutils>=0.21"]
# ///
"""
Static site generator for the nr-llm landing page.

Emits a bilingual (en/de) landing page, an ADR index, one rendered HTML page per
Architecture Decision Record (RST -> HTML), a client-side search index, plus
sitemap.xml / robots.txt / llms.txt. No runtime framework; output is plain,
semantic, self-contained HTML that GitHub Pages serves as-is.

Run:  uv run landingpage/build/build.py   (from the repo root)
Env:  NRLLM_BASE   base path (default "/t3x-nr-llm/"), NRLLM_ORIGIN (default the
      project-pages origin). A custom domain would set NRLLM_BASE=/ .
"""
from __future__ import annotations

import hashlib
import html
import json
import os
import re
import shutil
from pathlib import Path

from docutils import nodes
from docutils.core import publish_parts
from docutils.parsers.rst import Directive, directives, roles
from docutils.parsers.rst.directives.body import CodeBlock
from jinja2 import Environment, FileSystemLoader, select_autoescape

# ---------------------------------------------------------------------------
# Paths & config
# ---------------------------------------------------------------------------
HERE = Path(__file__).resolve().parent            # landingpage/build
SRC = HERE.parent / "src"                          # landingpage/src
DATA = HERE / "data"
REPO = HERE.parent.parent                          # repo root
ADR_DIR = REPO / "Documentation" / "Adr"
OUT = HERE.parent / "public"                       # landingpage/public

BASE = os.environ.get("NRLLM_BASE", "/t3x-nr-llm/")
if not BASE.endswith("/"):
    BASE += "/"
ORIGIN = os.environ.get("NRLLM_ORIGIN", "https://netresearch.github.io").rstrip("/")
# Origin only (scheme + host). Absolute URLs are built as SITE_URL + url(path),
# and url() already prepends BASE — so SITE_URL must NOT include the base path.
SITE_URL = ORIGIN

LANGS = ["en", "de"]

SEARCH_INDEX = "search-index.json"
INDEX_HTML = "index.html"
SCHEMA_TYPE = "@type"


def url(path: str) -> str:
    """Absolute (base-path-aware) URL for an in-site path like 'en/' or 'adr/x.html'."""
    return BASE + path.lstrip("/")


def load(name: str):
    return json.loads((DATA / f"{name}.json").read_text(encoding="utf-8"))


# ---------------------------------------------------------------------------
# UI microcopy (per language)
# ---------------------------------------------------------------------------
STRINGS = {
    "en": {
        "skip": "Skip to main content",
        "nav": {"problem": "Problem", "solution": "Solution", "concepts": "Concepts",
                "dev": "Developers", "admin": "For admins", "adr": "Architecture", "faq": "FAQ"},
        "searchLabel": "Search",
        "searchPlaceholder": "Search the site and all ADRs…",
        "searchResults": "{n} results",
        "searchNoResults": "No results found",
        "themeLabel": "Switch between light and dark theme",
        "menuLabel": "Open navigation menu",
        "copy": "Copy code",
        "copied": "Copied",
        "langLabel": "Language",
        "ai": {
            "title": "Ask nr-llm",
            "badge": "On-device AI",
            "intro": "Ask a question about nr-llm and get an answer generated entirely in your "
                     "browser by Chrome's built-in AI (Gemini Nano). Answers are grounded in this "
                     "site's content.",
            "placeholder": "e.g. Where are API keys stored?",
            "ask": "Ask",
            "cancel": "Stop",
            "enable": "Enable on-device AI",
            "enableHint": "On-device AI is available but needs a one-time model download in your browser.",
            "checking": "Checking on-device AI…",
            "downloading": "Downloading on-device model…",
            "unsupported": "On-device AI needs Chrome 148+ on desktop. Your browser can't run it — "
                           "all content and every ADR on this site remain available to read.",
            "error": "The on-device model could not answer. Try rephrasing, or browse the content "
                     "and ADRs directly.",
            "empty": "No answer was produced. Try rephrasing your question.",
            "sources": "Sources",
            "privacy": "Runs entirely in your browser. Nothing is sent to any server.",
        },
        "adrIndexTitle": "Architecture Decision Records",
        "adrIndexIntro": "Every significant design decision in nr-llm is recorded as an ADR — the "
                         "context, the decision, and its consequences. Browse the full set below.",
        "adrBackToOverview": "All ADRs",
        "onThisPage": "On this page",
        "readAdrs": "Read the architecture decisions",
        "tocLabel": "Sections",
    },
    "de": {
        "skip": "Zum Hauptinhalt springen",
        "nav": {"problem": "Problem", "solution": "Lösung", "concepts": "Konzepte",
                "dev": "Entwickler", "admin": "Für Admins", "adr": "Architektur", "faq": "FAQ"},
        "searchLabel": "Suche",
        "searchPlaceholder": "Website und alle ADRs durchsuchen…",
        "searchResults": "{n} Treffer",
        "searchNoResults": "Keine Treffer",
        "themeLabel": "Zwischen hellem und dunklem Thema wechseln",
        "menuLabel": "Navigationsmenü öffnen",
        "copy": "Code kopieren",
        "copied": "Kopiert",
        "langLabel": "Sprache",
        "ai": {
            "title": "nr-llm fragen",
            "badge": "On-Device-KI",
            "intro": "Stelle eine Frage zu nr-llm. Die Antwort entsteht vollständig in deinem "
                     "Browser über die eingebaute KI von Chrome (Gemini Nano) und stützt sich auf "
                     "die Inhalte dieser Website.",
            "placeholder": "z. B. Wo werden die API-Schlüssel gespeichert?",
            "ask": "Fragen",
            "cancel": "Stopp",
            "enable": "On-Device-KI aktivieren",
            "enableHint": "Die On-Device-KI ist verfügbar, benötigt aber einen einmaligen "
                          "Modell-Download in deinem Browser.",
            "checking": "On-Device-KI wird geprüft…",
            "downloading": "On-Device-Modell wird geladen…",
            "unsupported": "Die On-Device-KI benötigt Chrome 148+ auf dem Desktop. In diesem Browser "
                           "ist sie nicht verfügbar – alle Inhalte und jedes ADR lassen sich weiterhin lesen.",
            "error": "Das On-Device-Modell konnte nicht antworten. Formuliere die Frage um oder sieh "
                     "direkt in die Inhalte und ADRs.",
            "empty": "Es wurde keine Antwort erzeugt. Formuliere die Frage bitte um.",
            "sources": "Quellen",
            "privacy": "Läuft vollständig im Browser. Es werden keine Daten an einen Server gesendet.",
        },
        "adrIndexTitle": "Architektur-Entscheidungen (ADRs)",
        "adrIndexIntro": "Jede wesentliche Design-Entscheidung in nr-llm ist als ADR festgehalten – "
                         "Kontext, Entscheidung und Konsequenzen. Die vollständige Sammlung findest "
                         "du unten. Die ADRs selbst sind auf Englisch verfasst.",
        "adrBackToOverview": "Alle ADRs",
        "onThisPage": "Auf dieser Seite",
        "readAdrs": "Architektur-Entscheidungen lesen",
        "tocLabel": "Abschnitte",
    },
}

# JS-facing subset injected as window.__NRLLM__.strings
def js_strings(lang: str) -> dict:
    s = STRINGS[lang]
    a = s["ai"]
    return {
        "searchResults": s["searchResults"], "searchNoResults": s["searchNoResults"],
        "copy": s["copy"], "copied": s["copied"],
        "aiUnsupported": a["unsupported"], "aiDownloading": a["downloading"],
        "aiEnableHint": a["enableHint"], "aiChecking": a["checking"],
        "aiError": a["error"], "aiEmpty": a["empty"], "aiSources": a["sources"],
    }


# ---------------------------------------------------------------------------
# RST -> HTML for ADRs (stub TYPO3-specific directives / roles)
# ---------------------------------------------------------------------------
class _Passthrough(Directive):
    """Directives we intentionally drop (e.g. include of shared TYPO3 boilerplate)."""
    has_content = True
    optional_arguments = 10
    required_arguments = 0
    option_spec: dict = {}

    def run(self):
        return []


class _Confval(Directive):
    """TYPO3 confval -> a small definition block."""
    has_content = True
    required_arguments = 1
    optional_arguments = 0
    final_argument_whitespace = True
    option_spec = {"type": directives.unchanged, "default": directives.unchanged,
                   "required": directives.unchanged}

    def run(self):
        name = self.arguments[0]
        container = nodes.definition_list()
        item = nodes.definition_list_item()
        item += nodes.term(text=name)
        defn = nodes.definition()
        self.state.nested_parse(self.content, self.content_offset, defn)
        item += defn
        container += item
        return [container]


def _literal_role(name, rawtext, text, lineno, inliner, options=None, content=None):
    node = nodes.literal(rawtext, html.unescape(text))
    return [node], []


def _text_role(name, rawtext, text, lineno, inliner, options=None, content=None):
    # :ref:`label` or :ref:`Nice text <label>` -> keep the human text only.
    # Plain string ops (no backtracking regex) to strip a trailing "<target>".
    label = text
    if text.endswith(">") and "<" in text:
        label = text[:text.rindex("<")].rstrip()
    return [nodes.Text(html.unescape(label))], []


def register_rst_extensions():
    directives.register_directive("code-block", CodeBlock)
    directives.register_directive("confval", _Confval)
    directives.register_directive("include", _Passthrough)
    for r in ("bash", "code", "composer", "php", "sql", "file", "guilabel", "kbd", "typoscript"):
        roles.register_local_role(r, _literal_role)
    for r in ("ref", "doc", "t3-ref", "cite"):
        roles.register_local_role(r, _text_role)


def render_rst(text: str) -> dict:
    settings = {
        "report_level": 5,       # suppress system messages
        "halt_level": 5,         # never abort on unknown constructs
        "input_encoding": "unicode",
        "initial_header_level": 2,
        "syntax_highlight": "none",
        "embed_stylesheet": False,
        "doctitle_xform": True,
        "sectsubtitle_xform": False,
    }
    parts = publish_parts(source=text, writer_name="html5", settings_overrides=settings)
    return {"title": (parts.get("title") or "").strip(), "body": parts.get("body") or ""}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def slugify(s: str) -> str:
    s = re.sub(r"[^\w\s-]", "", s.lower()).strip()
    return re.sub(r"[\s_]+", "-", s)[:60] or "adr"


def strip_html(s: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", s)).strip()


def make_scrollables_focusable(html_fragment: str) -> str:
    """Add tabindex=0 to horizontally scrollable blocks (<pre>, <table>) so keyboard
    users can scroll them (WCAG 2.1.1 / axe scrollable-region-focusable). The trailing
    [\\s>] boundary matches only the exact tags, never <preview>/<table-foo>."""
    return re.sub(r'<(pre|table)([\s>])', r'<\1 tabindex="0"\2', html_fragment)


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------
def collect_adrs(adr_meta: dict) -> list[dict]:
    meta_by_num = {a["number"]: a for a in adr_meta["adrs"]}
    group_by_num = {}
    for g in adr_meta.get("groups", []):
        for n in g["numbers"]:
            group_by_num[n] = g["name"]
    adrs = []
    for path in sorted(ADR_DIR.glob("Adr*.rst")):
        m = re.match(r"Adr(\d+)", path.name)
        if not m:
            continue
        num = int(m.group(1))
        rendered = render_rst(path.read_text(encoding="utf-8"))
        meta = meta_by_num.get(num, {})
        title = meta.get("title") or rendered["title"] or f"ADR {num}"
        slug = f"adr{num:03d}-{slugify(title)}"
        adrs.append({
            "number": num,
            "title": title,
            "status": meta.get("status", "Accepted"),
            "summary": meta.get("summary", ""),
            "group": group_by_num.get(num, "Other"),
            "slug": slug,
            "file": f"adr/{slug}.html",
            "url": url(f"adr/{slug}.html"),
            "body": make_scrollables_focusable(rendered["body"]),
        })
    return adrs


def group_adrs(adrs: list[dict], order: list[str]) -> list[dict]:
    by_group: dict[str, list] = {}
    for a in adrs:
        by_group.setdefault(a["group"], []).append(a)
    result = []
    seen = set()
    for name in order + sorted(by_group.keys()):
        if name in by_group and name not in seen:
            seen.add(name)
            result.append({"name": name, "adrs": sorted(by_group[name], key=lambda x: x["number"])})
    return result


def faq_jsonld(faq_items: list[dict]) -> str:
    return json.dumps({
        "@context": "https://schema.org",
        SCHEMA_TYPE: "FAQPage",
        "mainEntity": [
            {SCHEMA_TYPE: "Question", "name": q["q"],
             "acceptedAnswer": {SCHEMA_TYPE: "Answer", "text": q["a"]}}
            for q in faq_items
        ],
    }, ensure_ascii=False, indent=2)


def build_search_docs(content_by_lang: dict, adrs: list[dict]) -> list[dict]:
    docs = []
    for lang in LANGS:
        c = content_by_lang[lang]
        landing = url(f"{lang}/")
        sects = [
            ("problem", c["problem"]["heading"], c["problem"]["intro"] + " " + " ".join(c["problem"]["bullets"])),
            ("solution", c["solution"]["heading"], c["solution"]["intro"] + " " + " ".join(c["solution"]["paragraphs"])),
            ("audience", c["audience"]["heading"], c["audience"]["intro"] + " " + " ".join(u["title"] + " " + u["body"] for u in c["audience"]["useCases"])),
            ("dev-kickstart", c["devKickstart"]["heading"], c["devKickstart"]["intro"] + " " + " ".join(s["title"] + " " + s["body"] for s in c["devKickstart"]["steps"])),
            ("admin", c["adminOverview"]["heading"], c["adminOverview"]["intro"] + " " + " ".join(f["title"] + " " + f["body"] for f in c["adminOverview"]["features"])),
            ("faq", c["faq"]["heading"], " ".join(q["q"] + " " + q["a"] for q in c["faq"]["items"])),
        ]
        for cid, heading, text in sects:
            docs.append({"id": f"{lang}-{cid}", "title": heading, "section": heading,
                         "text": strip_html(text)[:1200], "url": f"{landing}#{cid}", "kind": "section", "lang": lang})
        for item in c["concepts"]["items"]:
            docs.append({"id": f"{lang}-concept-{slugify(item['title'])}", "title": item["title"],
                         "section": c["concepts"]["heading"], "text": strip_html(item["body"])[:600],
                         "url": f"{landing}#concepts", "kind": "section", "lang": lang})
    for a in adrs:
        docs.append({"id": f"adr-{a['number']}", "title": f"ADR {a['number']}: {a['title']}",
                     "section": a["group"], "text": (a["summary"] or strip_html(a["body"]))[:800],
                     "url": a["url"], "kind": "adr", "lang": "all"})
    # normalise section labels
    for d in docs:
        if not d["section"]:
            d["section"] = ""
    return docs


def main():
    register_rst_extensions()
    content = {"en": load("content"), "de": load("content_de") if (DATA / "content_de.json").exists() else load("content")}
    adr_meta = load("adr")
    brand = load("brand")
    seo = load("seo")

    adrs = collect_adrs(adr_meta)
    group_order = [g["name"] for g in adr_meta.get("groups", [])]
    grouped = group_adrs(adrs, group_order)

    # Autoescaping is enabled for html/xml below; raw HTML (ADR bodies, JSON-LD) is
    # our own generated content, injected explicitly via |safe.
    env = Environment(  # nosemgrep: python.flask.security.xss.audit.direct-use-of-jinja2.direct-use-of-jinja2
        loader=FileSystemLoader(str(SRC / "templates")),
        autoescape=select_autoescape(["html", "xml"]),
        trim_blocks=True, lstrip_blocks=True,
    )
    env.globals.update(BASE=BASE, SITE_URL=SITE_URL, url=url)

    # Clean output
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)

    # Assets
    shutil.copytree(SRC / "assets", OUT / "assets")

    # Cache-busting version derived from asset content (CSS + JS).
    hasher = hashlib.sha256()
    for rel in ("css/site.css", "js/search.js", "js/ai-assistant.js", "js/site.js", "js/minisearch.min.js"):
        p = OUT / "assets" / rel
        if p.exists():
            hasher.update(p.read_bytes())
    env.globals["ASSET_VER"] = hasher.hexdigest()[:10]

    jsonld_common = seo["jsonLdBlocks"]

    # Landing pages
    for lang in LANGS:
        s = STRINGS[lang]
        c = content[lang]
        meta = seo["metaEn"] if lang == "en" else seo["metaDe"]
        alternates = [
            {"lang": "en", "href": SITE_URL + url("en/")},
            {"lang": "de", "href": SITE_URL + url("de/")},
            {"lang": "x-default", "href": SITE_URL + BASE},
        ]
        blocks = list(jsonld_common) + [{"name": "FAQPage", "json": faq_jsonld(c["faq"]["items"])}]
        page = env.get_template("landing.html.j2").render(
            lang=lang, s=s, c=c, brand=brand, meta=meta, adrs=adrs, grouped=grouped,
            canonical=SITE_URL + url(f"{lang}/"), alternates=alternates,
            jsonld_blocks=blocks, adr_index_url=url("adr/"),
            show_nav=True, is_landing=True, nav_prefix="#",
            js_config={"indexUrl": url(SEARCH_INDEX), "lang": lang, "strings": js_strings(lang)},
        )
        outdir = OUT / lang
        outdir.mkdir(parents=True, exist_ok=True)
        (outdir / INDEX_HTML).write_text(page, encoding="utf-8")

    # ADR index (English content, but reachable from both languages)
    idx = env.get_template("adr_index.html.j2").render(
        lang="en", s=STRINGS["en"], brand=brand, grouped=grouped, total=len(adrs),
        canonical=SITE_URL + url("adr/"),
        alternates=[{"lang": "x-default", "href": SITE_URL + url("adr/")}],
        jsonld_blocks=jsonld_common,
        meta={"title": f"{STRINGS['en']['adrIndexTitle']} — nr-llm",
              "description": "All architecture decision records for the nr-llm TYPO3 extension: "
                             "provider abstraction, configuration, services, tools, security and more."},
        show_nav=True, is_landing=False, nav_prefix=url("en/") + "#",
        js_config={"indexUrl": url(SEARCH_INDEX), "lang": "en", "strings": js_strings("en")},
    )
    (OUT / "adr").mkdir(parents=True, exist_ok=True)
    (OUT / "adr" / INDEX_HTML).write_text(idx, encoding="utf-8")

    # ADR pages
    for a in adrs:
        crumb = [{"name": "nr-llm", "href": url("en/")},
                 {"name": STRINGS["en"]["adrIndexTitle"], "href": url("adr/")},
                 {"name": f"ADR {a['number']}", "href": a["url"]}]
        breadcrumb_ld = json.dumps({
            "@context": "https://schema.org", SCHEMA_TYPE: "BreadcrumbList",
            "itemListElement": [
                {SCHEMA_TYPE: "ListItem", "position": i + 1, "name": x["name"], "item": SITE_URL + x["href"]}
                for i, x in enumerate(crumb)
            ],
        }, ensure_ascii=False)
        page = env.get_template("adr_page.html.j2").render(
            lang="en", s=STRINGS["en"], brand=brand, adr=a, crumb=crumb,
            canonical=SITE_URL + a["url"],
            alternates=[{"lang": "x-default", "href": SITE_URL + a["url"]}],
            jsonld_blocks=[{"name": "BreadcrumbList", "json": breadcrumb_ld}],
            meta={"title": f"ADR {a['number']}: {a['title']} — nr-llm",
                  "description": (a["summary"] or strip_html(a["body"])[:150] or a["title"])[:155]},
            show_nav=True, is_landing=False, nav_prefix=url("en/") + "#",
            js_config={"indexUrl": url(SEARCH_INDEX), "lang": "en", "strings": js_strings("en")},
        )
        (OUT / "adr" / f"{a['slug']}.html").write_text(page, encoding="utf-8")

    # Root chooser (x-default)
    root = env.get_template("root.html.j2").render(
        brand=brand, canonical=SITE_URL + BASE,
        alternates=[
            {"lang": "en", "href": SITE_URL + url("en/")},
            {"lang": "de", "href": SITE_URL + url("de/")},
            {"lang": "x-default", "href": SITE_URL + BASE},
        ],
        en_url=url("en/"), de_url=url("de/"), meta=seo["metaEn"],
    )
    (OUT / INDEX_HTML).write_text(root, encoding="utf-8")

    # Search index
    docs = build_search_docs(content, adrs)
    (OUT / SEARCH_INDEX).write_text(
        json.dumps({"documents": docs}, ensure_ascii=False), encoding="utf-8")

    # sitemap.xml
    paths = list(seo.get("sitemapPaths", [])) + [SITE_URL + a["url"] for a in adrs]
    urls_xml = "\n".join(
        f"  <url><loc>{html.escape(p)}</loc></url>" for p in paths)
    (OUT / "sitemap.xml").write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
        f"{urls_xml}\n</urlset>\n", encoding="utf-8")

    # robots.txt
    (OUT / "robots.txt").write_text(seo.get("robotsTxt", "User-agent: *\nAllow: /\n"), encoding="utf-8")

    # llms.txt
    (OUT / "llms.txt").write_text(seo.get("llmsTxt", "# nr-llm\n"), encoding="utf-8")

    # .nojekyll so GitHub Pages serves _-prefixed paths untouched
    (OUT / ".nojekyll").write_text("", encoding="utf-8")

    print(f"Built {len(adrs)} ADRs + {len(LANGS)} landing pages -> {OUT}")
    print(f"Search docs: {len(docs)} | base: {BASE} | origin: {ORIGIN}")


if __name__ == "__main__":
    main()
