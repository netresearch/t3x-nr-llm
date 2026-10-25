#!/usr/bin/env python3
#
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH

"""Build gate for the landing page.

Checks the rendered artefact, not the sources: the point is what a visitor and a
crawler actually receive. Exit code 1 fails the build.

    python3 landingpage/build/verify.py
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path
from urllib.parse import urljoin, urlsplit

sys.path.insert(0, str(Path(__file__).resolve().parent))
from check_accessibility import check_accessibility  # noqa: E402
from check_site_shell import check_business_contacts, check_family_navigation, check_locale_navigation, check_noindex, check_site_shell, has_status_facts  # noqa: E402

HERE = Path(__file__).resolve().parent
PUBLIC = HERE.parent / "public"
REPO = HERE.parent.parent

errors: list[str] = []
symbol_path = PUBLIC / "assets" / "site-shell" / "netresearch-symbol-only.svg"
header_symbol = symbol_path.read_bytes() if symbol_path.is_file() else b""

PLACEHOLDERS = ("Loading…", "Loading...", "TBD", "Lorem ipsum")

# Copy must not carry a version, a stage or a requirement of its own — those come
# from the manifest. An unresolved placeholder means a substitution was missed.
UNRESOLVED = re.compile(r"\{(VERSION|LATEST_RELEASE|TYPO3_VERSIONS|PHP_VERSION|CONTACT_[A-Z]+|ADR_COUNT)\}")

REQUIRED_META = (
    (r'<link rel="canonical" href="[^"]+"', "canonical"),
    (r'<meta name="description" content="[^"]+"', "meta description"),
    (r'hreflang="x-default"', "x-default hreflang"),
    (r'<meta property="og:image" content="[^"]+"', "og:image"),
    (r'<meta name="twitter:card"', "twitter:card"),
    (r'<script type="application/ld\+json"', "JSON-LD"),
)

LANDING_PAGES = ("en/index.html", "de/index.html")

# A Python object's repr in the output means a template looked up a name that
# resolved to something other than the intended value. Jinja's `s.copy` on a
# dict returns the dict's copy() method, not the "copy" key, and every copy
# button then carried "<built-in method copy of dict object at 0x…>" as its
# accessible name.
PYTHON_REPR = re.compile(r"(?:<|&lt;)(?:built-in method|bound method|function) ")


def strip_markup(html: str) -> str:
    html = re.sub(r"<script\b[^>]*>.*?</script\b[^>]*>", " ", html, flags=re.S | re.I)
    html = re.sub(r"<style\b[^>]*>.*?</style\b[^>]*>", " ", html, flags=re.S | re.I)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html))


def configured_site_root() -> str:
    """Use the build/Pages configuration, never a page's self-declared URL."""
    origin = os.environ.get("NRLLM_ORIGIN", os.environ.get("PAGES_ORIGIN", "https://netresearch.github.io"))
    base = os.environ.get("NRLLM_BASE", os.environ.get("PAGES_BASE_PATH", "/t3x-nr-llm/"))
    return urljoin(origin.rstrip("/") + "/", base.strip("/") + "/")


def check_manifest() -> dict | None:
    path = PUBLIC / "project-manifest.json"
    if not path.exists():
        errors.append("project-manifest.json was not published — the portfolio cannot read this project")
        return None

    manifest = json.loads(path.read_text(encoding="utf-8"))
    for field in ("manifest_version", "name", "slug", "stage", "main_version",
                  "last_verified", "owner", "license", "repository"):
        if not manifest.get(field):
            errors.append(f"project-manifest.json: {field} is missing")

    # The manifest must agree with the repository it ships from.
    emconf = (REPO / "ext_emconf.php").read_text(encoding="utf-8")
    match = re.search(r"'version'\s*=>\s*'([^']+)'", emconf)
    if match and manifest.get("main_version") != match.group(1):
        errors.append(
            f"project-manifest.json: main_version {manifest.get('main_version')} "
            f"does not match ext_emconf.php {match.group(1)}"
        )

    if manifest.get("stage") not in {"concept", "poc", "alpha", "beta", "stable", "maintenance"}:
        errors.append(f"project-manifest.json: {manifest.get('stage')!r} is not a known maturity stage")

    ai = manifest.get("ai") or {}
    for field in ("intended_purpose", "excluded_uses", "processing_location",
                  "human_oversight", "known_limitations"):
        if not ai.get(field):
            errors.append(f"project-manifest.json: ai.{field} is missing — the capability card would have a hole")

    return manifest


def main() -> int:
    if not PUBLIC.exists():
        print("verify: landingpage/public not found — run build.py first", file=sys.stderr)
        return 1

    manifest = check_manifest()
    site_root = configured_site_root()
    expected_symbol_url = urljoin(site_root, "assets/site-shell/netresearch-symbol-only.svg")

    for page in sorted(PUBLIC.rglob("*.html")):
        markup = page.read_text(encoding="utf-8")
        relative = page.relative_to(PUBLIC)
        leak = PYTHON_REPR.search(markup)
        if leak:
            errors.append(f"{relative}: a Python object repr is rendered: {leak.group(0)!r}")
        # The root language chooser and ADR/feature pages share the same public
        # brand contract. Checking landing pages alone missed the unbranded root.
        footer = re.search(r"<footer\b[\s\S]*?</footer>", markup)
        errors.extend(f"{relative}: {problem}" for problem in check_site_shell(
            markup, header_symbol, page_url=urljoin(site_root, relative.as_posix()),
            expected_symbol_url=expected_symbol_url))
        if not footer or "Netresearch DTT GmbH" not in footer.group(0) \
                or 'href="https://www.netresearch.de/"' not in footer.group(0):
            errors.append(f"{relative}: footer must name and link Netresearch DTT GmbH")
        if 'class="context-nav container"' not in markup:
            errors.append(f"{relative}: no portfolio/AI-stack context navigation")

        errors.extend(f"{relative}: {problem}" for problem in
                      check_locale_navigation(markup, relative.as_posix(), site_root))
        language = "de" if relative.as_posix().startswith("de/") else "en"
        errors.extend(f"{relative}: {problem}" for problem in check_family_navigation(markup, language))
        required_ctas = ("hero", "cta-band", "footer") if relative.as_posix() in LANDING_PAGES else ("footer",)
        errors.extend(f"{relative}: {problem}" for problem in check_business_contacts(markup, language, required_ctas))
        if relative.as_posix() == "404.html":
            errors.extend(f"404.html: {problem}" for problem in check_noindex(markup))
            for locale in ("en", "de"):
                target = urlsplit(site_root).path + f"{locale}/"
                if f'href="{target}" hreflang="{locale}" lang="{locale}"' not in markup:
                    errors.append(f"404.html: missing {locale} project recovery link")
            errors.extend(f"404.html: {problem}" for problem in check_accessibility(markup))

    if not (PUBLIC / "404.html").is_file():
        errors.append("404.html: missing project recovery page")

    for relative in LANDING_PAGES:
        page = PUBLIC / relative
        if not page.exists():
            errors.append(f"{relative} was not built")
            continue
        html = page.read_text(encoding="utf-8")
        text = strip_markup(html)

        for placeholder in PLACEHOLDERS:
            if placeholder in text:
                errors.append(f"{relative}: placeholder text in the initial HTML: {placeholder!r}")

        unresolved = UNRESOLVED.search(html)
        if unresolved:
            errors.append(f"{relative}: unresolved content placeholder {unresolved.group(0)}")

        for pattern, label in REQUIRED_META:
            if not re.search(pattern, html):
                errors.append(f"{relative}: no {label}")

        for block in re.findall(r'<script type="application/ld\+json"[^>]*>([\s\S]*?)</script>', html):
            try:
                json.loads(block)
            except json.JSONDecodeError as exc:
                errors.append(f"{relative}: invalid JSON-LD: {exc}")

        # Accessibility and semantics decidable from the markup alone.
        for problem in check_accessibility(html):
            errors.append(f"{relative}: {problem}")

        # Maturity above the fold, and the capability card's limitations present.
        if not has_status_facts(html):
            errors.append(f"{relative}: no status block — maturity and version must be near the top")
        if "capability-card__limits" not in html:
            errors.append(f"{relative}: the capability card renders no known limitations")

        # A dictionary key named "copy" collides with dict.copy in Jinja's dot
        # lookup. Looking up fields on that method silently rendered empty cells,
        # without an object repr for PYTHON_REPR to catch.
        card = re.search(r'<dl class="capability-card"[^>]*>([\s\S]*?)</dl>', html)
        if not card:
            errors.append(f"{relative}: no capability card")
        else:
            values = re.findall(r'<dd\b[^>]*>([\s\S]*?)</dd>', card.group(1))
            if len(values) != 14 or any(not strip_markup(value).strip() for value in values):
                errors.append(f"{relative}: capability card must contain 14 nonempty values")
            lists = re.findall(r'<ul\b[^>]*>([\s\S]*?)</ul>', card.group(1))
            if len(lists) != 4 or any(not re.search(r'<li\b', value) for value in lists):
                errors.append(f"{relative}: capability card must contain four populated control lists")

        # Versions on the page must be ones the manifest knows.
        if manifest:
            known = {v for v in (manifest.get("main_version"), manifest.get("latest_release"),
                                 manifest.get("docs_version")) if v}
            known |= {v.lstrip("v") for v in known}
            for version in set(re.findall(r"\bv?(\d+\.\d+\.\d+)\b", text)):
                if version not in known and f"v{version}" not in known:
                    errors.append(
                        f"{relative}: version {version} is rendered but is not in the manifest"
                    )

    for message in errors:
        print(f"ERROR {message}", file=sys.stderr)

    print(f"\nverify: {len(LANDING_PAGES)} landing pages checked, {len(errors)} errors")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
