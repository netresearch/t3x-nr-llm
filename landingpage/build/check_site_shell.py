# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Standalone shell identity and locale checks against rendered HTML."""
import hashlib
import re
from dataclasses import dataclass
from html.parser import HTMLParser
from urllib.parse import parse_qs, urljoin, urlsplit

SYMBOL_SHA256 = "ca88779a6d008b4606bbe8d45f4640d6967f82b66b302d95b9153dcf2cfb9e29"
VOID_ELEMENTS = frozenset({"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"})
FAMILY_DESTINATIONS = {
    "en": ["https://netresearch.github.io/", "https://netresearch.github.io/projects/", "https://netresearch.github.io/modularer-ki-stack-page/en/", "https://netresearch.github.io/claude-code-marketplace/en/", "https://netresearch.github.io/maint/"],
    "de": ["https://netresearch.github.io/de/", "https://netresearch.github.io/de/projekte/", "https://netresearch.github.io/modularer-ki-stack-page/", "https://netresearch.github.io/claude-code-marketplace/de/", "https://netresearch.github.io/maint/de/"],
}


@dataclass(eq=False)
class Element:
    tag: str
    attrs: dict[str, str | None]
    parent: "Element | None"

    def ancestor(self, tag: str) -> "Element | None":
        node = self.parent
        while node is not None:
            if node.tag == tag:
                return node
            node = node.parent
        return None


class SiteShellMarkup(HTMLParser):
    """Keep relevant elements and ancestry; script text is never markup."""

    def __init__(self, markup: str):
        super().__init__(convert_charrefs=True)
        self.elements: dict[str, list[Element]] = {tag: [] for tag in ("header", "img", "a", "nav", "link", "base", "meta", "dl")}
        self.stack: list[Element] = []
        self.feed(markup)
        self.close()

    def handle_starttag(self, tag, attrs):
        attributes = {}
        for name, value in attrs:
            attributes.setdefault(name, value)
        element = Element(tag, attributes, self.stack[-1] if self.stack else None)
        if tag in self.elements:
            self.elements[tag].append(element)
        if tag not in VOID_ELEMENTS:
            self.stack.append(element)

    def handle_endtag(self, tag):
        for index in range(len(self.stack) - 1, -1, -1):
            if self.stack[index].tag == tag:
                del self.stack[index:]
                break

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    @property
    def headers(self) -> list[Element]:
        return [node for node in self.elements["header"] if "data-nr-shell" in node.attrs]

    @property
    def canonical(self) -> str:
        return next((node.attrs.get("href") or "" for node in self.elements["link"]
                     if "canonical" in (node.attrs.get("rel") or "").split()), "")


def has_status_facts(markup: str) -> bool:
    return any("status-facts" in re.split(r"[\t\n\f\r ]+", node.attrs.get("class") or "")
               for node in SiteShellMarkup(markup).elements["dl"])


def check_site_shell(markup: str, symbol: bytes, *, page_url: str, expected_symbol_url: str) -> list[str]:
    errors = []
    document = SiteShellMarkup(markup)
    # These generated pages use explicit URLs and never emit a base href.
    # Reject this unsupported override rather than trusting a document base.
    if any("href" in node.attrs for node in document.elements["base"]):
        errors.append("shared pages must not override URL resolution with base href")
    headers = document.headers
    logos = [node for node in document.elements["img"]
             if urlsplit(node.attrs.get("src") or "").path.rsplit("/", 1)[-1] == "netresearch-symbol-only.svg"]
    if len(headers) != 1 or len(logos) != 1 or logos[0].ancestor("header") is not headers[0]:
        errors.append("exactly one canonical symbol must appear in the shared header")
    if logos and urljoin(page_url, logos[0].attrs.get("src") or "") != expected_symbol_url:
        errors.append("header symbol must load the published site-shell asset")
    if not headers or not any(node.ancestor("header") is headers[0]
                              and node.parent is not None and node.parent.tag == "a"
                              and node.parent.attrs.get("href") == "https://www.netresearch.de/" for node in logos):
        errors.append("header symbol must link to Netresearch DTT GmbH")
    if not logos or any(logos[0].attrs.get(key) != value for key, value in
                        (("width", "44"), ("height", "44"), ("alt", "Netresearch DTT GmbH"))):
        errors.append("header symbol must retain its dimensions and accessible name")
    if hashlib.sha256(symbol).hexdigest() != SYMBOL_SHA256:
        errors.append("header symbol differs from the authentic brand asset")
    return errors


def expected_locale_url(document: SiteShellMarkup, language: str, page_path: str, site_root: str) -> str | None:
    alternates = [node.attrs.get("href") for node in document.elements["link"]
                  if "alternate" in (node.attrs.get("rel") or "").split()
                  and node.attrs.get("hreflang") == language]
    if len(alternates) == 1 and alternates[0]:
        return urljoin(document.canonical, alternates[0])
    # The chooser, untranslated ADRs and bilingual error recovery use landing
    # routes without declaring a translated counterpart for their own content.
    if not alternates and site_root and (page_path in ("index.html", "404.html") or page_path.startswith("adr/")):
        return urljoin(site_root, f"{language}/")
    return None


def check_family_navigation(markup: str, lang: str) -> list[str]:
    document = SiteShellMarkup(markup)
    if len(document.headers) != 1 or lang not in FAMILY_DESTINATIONS:
        return ["family navigation requires one shared header and a supported language"]
    errors = []
    for class_name in ("nr-shell-desktop", "nr-shell-menu-panel"):
        menus = [node for node in document.elements["nav"]
                 if class_name in (node.attrs.get("class") or "").split()
                 and node.ancestor("header") is document.headers[0]]
        if len(menus) != 1:
            errors.append(f"exactly one {class_name} family navigation is required")
            continue
        links = [node.attrs.get("href") for node in document.elements["a"]
                 if node.ancestor("nav") is menus[0]
                 and "nr-shell-link" in (node.attrs.get("class") or "").split()]
        if links != FAMILY_DESTINATIONS[lang]:
            errors.append(f"{class_name} must retain all five {lang} family destinations")
    return errors


def check_noindex(markup: str) -> list[str]:
    document = SiteShellMarkup(markup)
    robots = [node.attrs.get("content") for node in document.elements["meta"]
              if node.attrs.get("name") == "robots"]
    return [] if robots == ["noindex,follow"] else ["recovery page must declare exactly one noindex,follow robots directive"]


def contact_link_errors(attributes: dict, lang: str) -> list[str]:
    try:
        target = urlsplit(attributes.get("href") or "")
    except ValueError:
        return ["business contact link has an invalid href"]
    errors = []
    expected_path = "/kontakt/" if lang == "de" else "/en/contact/"
    if target.scheme != "https" or target.netloc.lower() != "www.netresearch.de" or target.path != expected_path:
        errors.append("business contact link has a missing href, wrong destination or language")
    parameters = parse_qs(target.query, keep_blank_values=True)
    for name in ("utm_source", "utm_medium", "utm_campaign", "utm_content"):
        values = parameters.get(name, [])
        if len(values) != 1 or not values[0].strip():
            errors.append(f"business contact link needs one nonempty {name}")
    return errors


def check_business_contacts(markup: str, lang: str, required_positions: tuple[str, ...] = ()) -> list[str]:
    document = SiteShellMarkup(markup)
    classes = ("nr-shell-contact", "nr-shell-menu-contact")
    links = document.elements["a"]
    errors = []
    for name in classes:
        matching = [node for node in links if name in (node.attrs.get("class") or "").split()
                    and node.ancestor("header") in document.headers]
        if len(matching) != 1:
            errors.append(f"expected one {name} business contact link")
    if not any(node.attrs.get("data-cta") == "business" for node in links):
        errors.append("no marked business CTA remains")
    for position in required_positions:
        matching = [node for node in links if node.attrs.get("data-cta") == "business"
                    and node.attrs.get("data-cta-position") == position]
        if len(matching) != 1:
            errors.append(f"expected one marked business contact link at {position}")
    selected = [node for node in links if node.attrs.get("data-cta") == "business"
                or set(classes).intersection((node.attrs.get("class") or "").split())]
    for node in selected:
        errors.extend(contact_link_errors(node.attrs, lang))
    return errors


def check_locale_navigation(markup: str, page_path: str, site_root: str) -> list[str]:
    document = SiteShellMarkup(markup)
    navigations = [node for node in document.elements["nav"]
                   if "nr-shell-locales" in (node.attrs.get("class") or "").split()]
    if len(navigations) != 1 or len(document.headers) != 1 \
            or navigations[0].ancestor("header") is not document.headers[0]:
        return ["exactly one shared header locale navigation is required"]
    if not document.canonical and page_path != "404.html":
        return ["locale navigation requires the page canonical URL"]
    links = [node for node in document.elements["a"] if node.ancestor("nav") is navigations[0]]
    errors = []
    if len(links) != 2:
        errors.append("locale navigation must contain exactly the DE and EN links")
    for language in ("de", "en"):
        expected = expected_locale_url(document, language, page_path, site_root)
        matches = [node for node in links if node.attrs.get("hreflang") == language]
        if len(matches) != 1:
            errors.append(f"locale navigation requires exactly one {language} link")
        elif not expected:
            errors.append(f"no declared alternate or permitted fallback for locale {language}")
        elif matches[0].attrs.get("lang") != language or not matches[0].attrs.get("href") \
                or urljoin(document.canonical or site_root, matches[0].attrs["href"]) != expected:
            errors.append(f"locale link {language} does not match its declared alternate or permitted fallback")
    return errors
