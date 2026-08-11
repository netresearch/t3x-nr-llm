"""Static accessibility and HTML-semantics checks for rendered pages.

Deliberately dependency-free and browser-free: these are the defects that are
decidable from the markup alone, which is where generated HTML gets them wrong.
It is not a substitute for axe or for testing with a screen reader — contrast,
focus order and live-region behaviour are not decidable here, and this module
does not pretend otherwise.

    from check_accessibility import check_accessibility
    for problem in check_accessibility(html):
        errors.append(f"{name}: {problem}")
"""

from __future__ import annotations

import re

ATTR = re.compile(r"""([a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))""")
BARE_ATTR = re.compile(r"\s([a-zA-Z-]+)(?=[\s>])")
SKIP_INPUT_TYPES = {"hidden", "submit", "button"}


def attrs(tag: str) -> dict[str, str]:
    """All attributes of one tag, lower-cased keys."""
    out: dict[str, str] = {}
    for match in ATTR.finditer(tag):
        out[match.group(1).lower()] = match.group(2) or match.group(3) or match.group(4) or ""
    for match in BARE_ATTR.finditer(tag):
        out.setdefault(match.group(1).lower(), "")
    return out


def inner_text(html: str, tag_name: str, start: int) -> str:
    """Text content of an element, given the document and the tag's offset."""
    close = html.find(f"</{tag_name}", start)
    if close == -1:
        return ""
    open_end = html.find(">", start)
    if open_end == -1 or open_end > close:
        return ""
    body = html[open_end + 1:close]
    body = re.sub(r"<[^>]+>", " ", body)
    return re.sub(r"&[a-z]+;|&#\d+;", " ", body, flags=re.I).strip()


def check_accessibility(html: str) -> list[str]:
    problems: list[str] = []

    # ── Document ─────────────────────────────────────────────────────────────
    html_tag = re.search(r"<html\b[^>]*>", html, re.I)
    if not html_tag or not attrs(html_tag.group(0)).get("lang"):
        problems.append("the <html> element has no lang attribute")

    mains = re.findall(r"<main\b[^>]*>", html, re.I)
    if len(mains) != 1:
        problems.append(f"{len(mains)} <main> landmarks, expected exactly 1")

    # ── Headings ─────────────────────────────────────────────────────────────
    levels = [int(m.group(1)) for m in re.finditer(r"<h([1-6])\b[^>]*>", html, re.I)]
    h1s = levels.count(1)
    if h1s != 1:
        problems.append(f"{h1s} <h1> elements, expected exactly 1")

    previous = 0
    for level in levels:
        if previous and level > previous + 1:
            problems.append(f"heading level jumps from h{previous} to h{level}")
            break  # One report is enough; the whole outline needs fixing anyway.
        previous = level

    # ── Images ───────────────────────────────────────────────────────────────
    for match in re.finditer(r"<img\b[^>]*>", html, re.I):
        if "alt" not in attrs(match.group(0)):
            problems.append(f"<img> without an alt attribute: {match.group(0)[:90]}")

    # ── Links and buttons need an accessible name ────────────────────────────
    for match in re.finditer(r"<a\b[^>]*href[^>]*>", html, re.I):
        a = attrs(match.group(0))
        text = inner_text(html, "a", match.start())
        segment = html[match.start():html.find("</a", match.start()) + 1]
        has_image_alt = re.search(r"""<img[^>]+alt\s*=\s*["'][^"']+["']""", segment, re.I)
        if not text and not a.get("aria-label") and not a.get("aria-labelledby") and not has_image_alt:
            problems.append(f"link without discernible text: {match.group(0)[:90]}")

    for match in re.finditer(r"<button\b[^>]*>", html, re.I):
        button = attrs(match.group(0))
        text = inner_text(html, "button", match.start())
        segment = html[match.start():html.find("</button", match.start()) + 1]
        has_svg_title = re.search(r"<svg[\s\S]*?<title>[^<]+</title>", segment, re.I)
        if not text and not button.get("aria-label") and not button.get("aria-labelledby") and not has_svg_title:
            problems.append(f"button without an accessible name: {match.group(0)[:90]}")

    # ── Form controls need a label ───────────────────────────────────────────
    label_for = {
        m.group(1) or m.group(2)
        for m in re.finditer(r"""<label\b[^>]*\bfor\s*=\s*(?:"([^"]+)"|'([^']+)')""", html, re.I)
    }
    # A control nested inside a <label> that has text is labelled implicitly.
    # That is valid HTML and common for checkboxes, so a checker that only looks
    # for `for=` reports a defect that is not there.
    # The span is the whole element, opening tag to closing tag — not the
    # opening tag alone, which is what a nested control sits after.
    implicit_labels = [
        (m.start(), html.find("</label", m.start()), inner_text(html, "label", m.start()))
        for m in re.finditer(r"<label\b[^>]*>", html, re.I)
    ]

    def inside_labelled_label(offset: int) -> bool:
        return any(
            start < offset < end
            for start, end, text in implicit_labels
            if text and end != -1
        )


    for match in re.finditer(r"<(input|select|textarea)\b[^>]*>", html, re.I):
        control = attrs(match.group(0))
        if control.get("type") in SKIP_INPUT_TYPES:
            continue
        labelled = (
            control.get("aria-label")
            or control.get("aria-labelledby")
            or (control.get("id") and control["id"] in label_for)
            or control.get("title")
            or inside_labelled_label(match.start())
        )
        if not labelled:
            problems.append(f"form control without a label: {match.group(0)[:90]}")

    # ── Tables ───────────────────────────────────────────────────────────────
    for match in re.finditer(r"<table\b[^>]*>", html, re.I):
        table = attrs(match.group(0))
        body = html[match.start():html.find("</table", match.start()) + 1]
        if not re.search(r"<caption\b", body, re.I) and not table.get("aria-label") \
                and not table.get("aria-labelledby"):
            problems.append("table without a caption or an accessible name")
        for cell in re.finditer(r"<th\b[^>]*>", body, re.I):
            if not attrs(cell.group(0)).get("scope"):
                problems.append(f"<th> without a scope attribute: {cell.group(0)[:70]}")
                break

    # ── Focus order and identity ─────────────────────────────────────────────
    for match in re.finditer(r"""\btabindex\s*=\s*["']?(\d+)["']?""", html, re.I):
        if int(match.group(1)) > 0:
            problems.append(
                f'positive tabindex="{match.group(1)}" overrides the natural focus order'
            )
            break

    ids = [
        m.group(1) or m.group(2)
        for m in re.finditer(r"""\sid\s*=\s*(?:"([^"]+)"|'([^']+)')""", html, re.I)
    ]
    duplicates = sorted({i for i in ids if ids.count(i) > 1})
    if duplicates:
        problems.append(f"duplicate id attribute(s): {', '.join(duplicates[:5])}")

    return problems
