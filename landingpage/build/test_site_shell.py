# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
import sys
import json
import os
import re
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch
from check_site_shell import check_business_contacts, check_family_navigation, check_locale_navigation, check_noindex, check_site_shell, has_status_facts
from verify import configured_site_root

SHELL = Path(__file__).resolve().parent.parent / "site-shell"
sys.path.insert(0, str(SHELL))
from site_shell import render_header


class ShellIdentityTest(unittest.TestCase):
    def setUp(self):
        self.symbol = (SHELL / "netresearch-symbol-only.svg").read_bytes()
        self.header = render_header({"lang": "en", "assetBase": "assets/site-shell", "languageUrls": {"en": "./", "de": "de/"}, "active": None, "contactHref": "https://www.netresearch.de/kontakt/"})

    def check_header(self, markup, symbol):
        return check_site_shell(markup, symbol, page_url="https://example.test/product/index.html",
                                expected_symbol_url="https://example.test/product/assets/site-shell/netresearch-symbol-only.svg")

    def test_authentic_linked_header(self):
        self.assertEqual([], self.check_header(self.header, self.symbol))

    def test_status_facts_use_class_tokens_and_real_definition_lists(self):
        self.assertTrue(has_status_facts('<dl class="status-facts nr-hero-facts"></dl>'))
        self.assertTrue(has_status_facts("<dl class='nr-hero-facts status-facts'></dl>"))
        self.assertTrue(has_status_facts('<dl class="status-facts\tnr-hero-facts"></dl>'))
        self.assertTrue(has_status_facts('<dl class="status-facts&#9;nr-hero-facts"></dl>'))
        self.assertTrue(has_status_facts('<dl class="status-facts" class="other"></dl>'))
        for invalid in ('', '<dl class="nr-hero-facts"></dl>', '<dl class="old-status-facts"></dl>',
                        '<!-- <dl class="status-facts"></dl> -->', '<script><dl class="status-facts"></dl></script>',
                        '<dl class="status-facts\u00a0nr-hero-facts"></dl>', '<dl class="status-facts&#160;nr-hero-facts"></dl>',
                        '<dl class="status-facts\u2003nr-hero-facts"></dl>', '<dl class="status-facts\vnr-hero-facts"></dl>',
                        '<dl class="other" class="status-facts"></dl>'):
            with self.subTest(markup=invalid):
                self.assertFalse(has_status_facts(invalid))

    def test_status_facts_ignore_quoted_examples_and_inert_contexts(self):
        cases = (
            ('real_dl', '<dl class="status-facts nr-hero-facts"></dl>', True),
            ('quoted_parent_double', '<div data-example="<dl class=\'status-facts\'>"></div>', False),
            ('quoted_parent_single', '<div data-example=\'<dl class="status-facts">\'></div>', False),
            ('quoted_parent_unquoted_class', '<div data-example="<dl class=status-facts>"></div>', False),
            ('real_after_quoted_fake_dl', '<div data-example="<dl class=\'status-facts\'>"></div><dl class="status-facts"></dl>', True),
            ('real_after_fake_script_attr', '<div data-example="<script>"></div><dl class="status-facts"></dl></script>', True),
            ('real_after_fake_style_attr', '<div data-example="<style>"></div><dl class="status-facts"></dl></style>', True),
            ('real_after_fake_comment_attr', '<div data-example="<!--"></div><dl class="status-facts"></dl>-->', True),
            ('textarea_inert', '<textarea><dl class="status-facts"></dl></textarea>', False),
            ('title_inert', '<title><dl class="status-facts"></dl></title>', False),
            ('template_inert', '<template><dl class="status-facts"></dl></template>', False),
            ('template_then_real', '<template><dl class="other"></dl></template><dl class="status-facts"></dl>', True),
            ('script_inert', '<script type="application/json"><dl class="status-facts"></dl></script>', False),
            ('style_inert', '<style><dl class="status-facts"></dl></style>', False),
            ('comment_inert', '<!-- <dl class="status-facts"></dl> -->', False),
            ('textarea_then_real', '<textarea><dl class="other"></dl></textarea><dl class="status-facts"></dl>', True),
        )
        for name, markup, expected in cases:
            with self.subTest(case=name):
                self.assertEqual(expected, has_status_facts(markup))

    def test_recovery_noindex_uses_real_metadata_with_any_attribute_order(self):
        valid = '<meta content="noindex,follow" data-note="a > b" name="robots">'
        self.assertEqual([], check_noindex(valid))
        for invalid in ("", valid.replace("noindex,follow", "index,follow"), valid + valid,
                        f"<!--{valid}-->", f"<script>{valid}</script>"):
            with self.subTest(markup=invalid):
                self.assertTrue(check_noindex(invalid))

    def test_inert_examples_cannot_supply_or_duplicate_page_metadata_and_header(self):
        robots = '<meta name="robots" content="noindex,follow">'
        for tag in ("template", "textarea", "title", "script", "style", "xmp", "iframe", "noembed", "noframes"):
            with self.subTest(context=tag):
                fake = f"<{tag}>{robots}{self.header}</{tag}>"
                self.assertTrue(check_noindex(fake))
                self.assertTrue(self.check_header(fake, self.symbol))
                self.assertTrue(check_family_navigation(fake, "en"))
                self.assertEqual([], check_noindex(fake + robots))
                self.assertEqual([], self.check_header(self.header + fake, self.symbol))
                self.assertEqual([], check_family_navigation(self.header + fake, "en"))
                hidden_base = f'<{tag}><base href="https://wrong.test/"></{tag}>'
                self.assertEqual([], self.check_header(hidden_base + self.header, self.symbol))

    def test_locale_metadata_and_links_must_belong_to_the_active_document(self):
        metadata, header = self.locale_page()
        for tag in ("template", "textarea", "title", "script", "style"):
            with self.subTest(context=tag):
                self.assertTrue(check_locale_navigation(f"<{tag}>{metadata}</{tag}>" + header,
                                                        "en/security/", "https://example.test/product/"))
                self.assertTrue(check_locale_navigation(metadata + f"<{tag}>{header}</{tag}>",
                                                        "en/security/", "https://example.test/product/"))
                self.assertEqual([], check_locale_navigation(metadata + header + f"<{tag}>{metadata}{header}</{tag}>",
                                                              "en/security/", "https://example.test/product/"))

    def test_contact_examples_cannot_replace_a_real_header_or_hero(self):
        target = "https://www.netresearch.de/en/contact/?utm_source=github-pages&amp;utm_medium=referral&amp;utm_campaign=nr-llm&amp;utm_content=hero"
        header = self.header.replace("https://www.netresearch.de/kontakt/", target)
        hero = f'<a data-cta="business" data-cta-position="hero" href="{target}">Contact</a>'
        real = header + hero
        self.assertEqual([], check_business_contacts(real, "en", ("hero",)))
        for tag in ("template", "textarea", "title", "script", "style"):
            with self.subTest(context=tag):
                self.assertTrue(check_business_contacts(f"<{tag}>{real}</{tag}>", "en", ("hero",)))
                self.assertTrue(check_business_contacts(header + f"<{tag}>{hero}</{tag}>", "en", ("hero",)))
                invalid = real.replace("https://www.netresearch.de/en/contact/", "https://wrong.test/")
                self.assertEqual([], check_business_contacts(real + f"<{tag}>{invalid}</{tag}>", "en", ("hero",)))

    def test_nonvoid_self_closing_syntax_does_not_escape_inert_context(self):
        robots = '<meta name="robots" content="noindex,follow">'
        for tag in ("template", "textarea", "title", "script", "style"):
            with self.subTest(context=tag):
                fake = f"<{tag}/>{robots}</{tag}>"
                self.assertTrue(check_noindex(fake))
                self.assertEqual([], check_noindex(fake + robots))
        escaped = f"<div><template></div>{robots}</template>"
        self.assertTrue(check_noindex(escaped))
        self.assertEqual([], check_noindex(escaped + robots))
        self.assertTrue(check_noindex(f"<plaintext>{robots}</plaintext>{robots}"))

    def test_text_only_end_tags_cannot_activate_example_markup(self):
        robots = '<meta name="robots" content="noindex,follow">'
        for tag in ("textarea", "title", "script", "style", "xmp", "iframe", "noembed", "noframes"):
            with self.subTest(context=tag):
                fake = f"<div><{tag}></div>{robots}</{tag}></div>"
                self.assertTrue(check_noindex(fake))
                self.assertEqual([], check_noindex(fake + robots))

    def test_class_and_rel_contracts_use_html_whitespace_only(self):
        metadata, header = self.locale_page()
        for whitespace in ("\u00a0", "\u2003", "\v", "&#160;"):
            with self.subTest(separator=whitespace):
                self.assertTrue(check_family_navigation(header.replace('class="nr-shell-desktop"', f'class="nr-shell-desktop{whitespace}other"'), "en"))
                self.assertTrue(check_locale_navigation(metadata + header.replace('class="nr-shell-locales"', f'class="nr-shell-locales{whitespace}other"'), "en/security/", "https://example.test/product/"))
                self.assertTrue(check_locale_navigation(metadata.replace('rel="canonical"', f'rel="canonical{whitespace}other"') + header, "en/security/", "https://example.test/product/"))
                self.assertTrue(check_locale_navigation(metadata.replace('rel="alternate"', f'rel="alternate{whitespace}other"') + header, "en/security/", "https://example.test/product/"))
        for whitespace in (" ", "\t", "\n", "\r", "\f", "&#9;"):
            with self.subTest(separator=whitespace):
                self.assertEqual([], check_family_navigation(header.replace('class="nr-shell-desktop"', f'class="nr-shell-desktop{whitespace}other"'), "en"))
                self.assertEqual([], check_locale_navigation(metadata.replace('rel="canonical"', f'rel="canonical{whitespace}other"') + header, "en/security/", "https://example.test/product/"))

    def test_desktop_and_mobile_family_navigation_keep_all_localized_destinations(self):
        import re
        destinations = {
            "en": ["https://netresearch.github.io/", "https://netresearch.github.io/projects/", "https://netresearch.github.io/modularer-ki-stack-page/en/", "https://netresearch.github.io/claude-code-marketplace/en/", "https://netresearch.github.io/maint/"],
            "de": ["https://netresearch.github.io/de/", "https://netresearch.github.io/de/projekte/", "https://netresearch.github.io/modularer-ki-stack-page/", "https://netresearch.github.io/claude-code-marketplace/de/", "https://netresearch.github.io/maint/de/"],
        }
        for lang, expected in destinations.items():
            with self.subTest(lang=lang):
                markup = render_header({"lang": lang, "assetBase": "assets/site-shell", "languageUrls": {"en": "./", "de": "de/"}, "active": None, "contactHref": "https://www.netresearch.de/kontakt/"})
                self.assertEqual(expected + expected, re.findall(r'class="nr-shell-link" href="([^"]+)"', markup))
                self.assertEqual([], check_family_navigation(markup, lang))
                self.assertTrue(check_family_navigation(markup.replace(expected[3], "https://wrong.test/skills/"), lang))
                self.assertTrue(check_family_navigation(markup.replace("nr-shell-menu-panel", "missing-menu"), lang))

    def test_duplicates_wrong_company_and_altered_symbol_fail(self):
        import re
        img = re.search(r"<img[^>]*>", self.header)[0]
        self.assertTrue(self.check_header(self.header + img, self.symbol))
        self.assertTrue(self.check_header(self.header.replace("https://www.netresearch.de/", "https://example.com/"), self.symbol))
        self.assertTrue(self.check_header(self.header, self.symbol.replace(b"#2F99A4", b"#000000")))

    def test_quoted_angle_brackets_and_script_text_are_not_elements(self):
        quoted = self.header.replace("data-nr-shell", 'data-example="a > b" data-nr-shell', 1)
        self.assertEqual([], self.check_header(quoted, self.symbol))
        self.assertEqual([], self.check_header(self.header + f"<script>{self.header}</script>", self.symbol))

    def test_marker_text_does_not_replace_a_shell_attribute(self):
        lookalike = self.header.replace("data-nr-shell", 'data-example="data-nr-shell"', 1)
        self.assertTrue(self.check_header(lookalike, self.symbol))

    def test_symbol_contract_rejects_missing_unlinked_and_inaccessible_images(self):
        for old, new in (('width="44"', 'width="40"'), ('height="44"', 'height="40"'),
                         ('alt="Netresearch DTT GmbH"', 'alt=""'),
                         ("netresearch-symbol-only.svg", "other.svg"),
                         ('href="https://www.netresearch.de/"', 'data-href="https://www.netresearch.de/"')):
            with self.subTest(attribute=old):
                self.assertTrue(self.check_header(self.header.replace(old, new), self.symbol))

    def test_same_named_symbol_on_a_foreign_host_or_wrong_path_fails(self):
        for target in ("https://wrong.test/netresearch-symbol-only.svg", "/missing/netresearch-symbol-only.svg"):
            with self.subTest(source=target):
                markup = self.header.replace("assets/site-shell/netresearch-symbol-only.svg", target)
                self.assertTrue(self.check_header(markup, self.symbol))

    def test_configured_origin_base_and_deep_relative_sources(self):
        configurations = [
            ({}, "https://netresearch.github.io/t3x-nr-llm/"),
            ({"PAGES_ORIGIN": "https://docs.example.test", "PAGES_BASE_PATH": "/custom/manual"}, "https://docs.example.test/custom/manual/"),
            ({"PAGES_ORIGIN": "https://unused.test", "PAGES_BASE_PATH": "/unused/",
              "NRLLM_ORIGIN": "http://localhost:8137/", "NRLLM_BASE": "/"}, "http://localhost:8137/"),
        ]
        for environment, expected_root in configurations:
            with self.subTest(environment=environment), patch.dict(os.environ, environment, clear=True):
                root = configured_site_root()
                self.assertEqual(expected_root, root)
                expected = root + "assets/site-shell/netresearch-symbol-only.svg"
                self.assertEqual([], check_site_shell(self.header, self.symbol, page_url=root + "index.html", expected_symbol_url=expected))
                # An unchanged relative src on a deep page points at a different file.
                self.assertTrue(check_site_shell(self.header, self.symbol, page_url=root + "en/security/index.html", expected_symbol_url=expected))
                relative = self.header.replace('src="assets/', 'src="../../assets/')
                self.assertEqual([], check_site_shell(relative, self.symbol, page_url=root + "en/security/index.html", expected_symbol_url=expected))

    def test_a_declared_canonical_cannot_bless_a_foreign_symbol(self):
        spoof = '<link rel="canonical" href="https://wrong.test/">'
        spoof += self.header.replace("assets/site-shell/netresearch-symbol-only.svg", "https://wrong.test/netresearch-symbol-only.svg")
        self.assertTrue(self.check_header(spoof, self.symbol))

    def test_base_href_is_unsupported_but_script_text_is_not_an_element(self):
        for href in ("https://wrong.test/", "https://example.test/product/index.html"):
            with self.subTest(base=href):
                self.assertTrue(self.check_header(f'<base href="{href}">' + self.header, self.symbol))
        self.assertEqual([], self.check_header('<script><base href="https://wrong.test/"></script>' + self.header, self.symbol))

    def locale_page(self, path="en/security/", declared=True, root="https://example.test/product/"):
        languages = {lang: root + f"{lang}/security/" for lang in ("de", "en")}
        canonical = root if path == "index.html" else root + path.removesuffix("index.html")
        metadata = f'<link rel="canonical" href="{canonical}">'
        if declared:
            metadata += "".join(f'<link rel="alternate" hreflang="{lang}" href="{href}">' for lang, href in languages.items())
            metadata += f'<link rel="alternate" hreflang="x-default" href="{canonical}">'
        else:
            metadata += f'<link rel="alternate" hreflang="x-default" href="{canonical}">'
            languages = {lang: root + f"{lang}/" for lang in ("de", "en")}
        header = render_header({"lang": "en", "assetBase": "assets/site-shell",
                                "languageUrls": languages, "active": None,
                                "contactHref": "https://www.netresearch.de/kontakt/"})
        return f'<!doctype html><html><head>{metadata}</head><body>', header + '</body></html>'

    def test_locale_metadata_must_remain_in_the_document_head(self):
        metadata, header = self.locale_page()
        valid = metadata + header
        self.assertEqual([], check_locale_navigation(valid, "en/security/", "https://example.test/product/"))
        for relation in ("canonical", "alternate"):
            with self.subTest(relation=relation):
                tags = re.findall(rf'<link rel="{relation}"[^>]*>', valid)
                moved = valid
                for tag in tags:
                    moved = moved.replace(tag, "", 1)
                moved = moved.replace("</body>", "".join(tags) + "</body>", 1)
                self.assertTrue(check_locale_navigation(moved, "en/security/", "https://example.test/product/"))

    def test_body_or_late_head_tags_cannot_supply_document_metadata(self):
        metadata, header = self.locale_page()
        tags = re.search(r"<head>(.*?)</head>", metadata)[1]
        content = header.removesuffix("</body></html>")
        documents = (
            f"<!doctype html><html><body><head>{tags}</head>{content}</body></html>",
            f"<!doctype html><html><body>{content}</body><head>{tags}</head></html>",
            f"<!doctype html><html><head><div>Body content</div>{tags}</head><body>{content}</body></html>",
            f"<!doctype html><html><head>Body text{tags}</head><body>{content}</body></html>",
        )
        for markup in documents:
            with self.subTest(markup=markup[:100]):
                self.assertTrue(check_locale_navigation(markup, "en/security/", "https://example.test/product/"))

    def test_landing_x_default_requires_a_real_head_link(self):
        metadata, header = self.locale_page()
        valid = metadata + header
        self.assertEqual([], check_locale_navigation(valid, "en/security/", "https://example.test/product/", require_x_default=True))
        tag = re.search(r'<link rel="alternate" hreflang="x-default"[^>]*>', valid)[0]
        missing = valid.replace(tag, "", 1)
        for replacement in (tag, f"<!--{tag}-->", f"<template>{tag}</template>", f"<script>{tag}</script>"):
            with self.subTest(replacement=replacement):
                moved = missing.replace("</body>", replacement + "</body>", 1)
                self.assertTrue(check_locale_navigation(moved, "en/security/", "https://example.test/product/", require_x_default=True))

    def test_recovery_robots_metadata_remains_supported_in_body(self):
        markup = '<!doctype html><html><head><title>Missing page</title></head><body><meta name="robots" content="noindex,follow"></body></html>'
        self.assertEqual([], check_noindex(markup))

    def test_implicit_body_content_prevents_a_later_document_head(self):
        metadata, header = self.locale_page()
        tags = re.search(r"<head>(.*?)</head>", metadata)[1]
        content = header.removesuffix("</body></html>")
        for early in ("<div>Body content</div>", "Body text"):
            with self.subTest(early=early):
                markup = f"<!doctype html><html>{early}<head>{tags}</head>{content}</html>"
                self.assertTrue(check_locale_navigation(markup, "en/security/", "https://example.test/product/", require_x_default=True))
        markup = f"Body text<!doctype html><html><head>{tags}</head>{content}</html>"
        self.assertTrue(check_locale_navigation(markup, "en/security/", "https://example.test/product/", require_x_default=True))
        valid = (metadata + header).replace("<head>", " \n<!-- before head --><head>", 1)
        self.assertEqual([], check_locale_navigation(valid, "en/security/", "https://example.test/product/", require_x_default=True))

    def test_x_default_counts_empty_and_missing_href_duplicates(self):
        metadata, header = self.locale_page()
        valid = metadata + header
        tag = re.search(r'<link rel="alternate" hreflang="x-default"[^>]*>', valid)[0]
        for href in ('href=""', '', 'href="   "'):
            with self.subTest(href=href):
                duplicate = f'<link rel="alternate" hreflang="x-default" {href}>'
                markup = valid.replace("</head>", duplicate + "</head>", 1)
                self.assertTrue(check_locale_navigation(markup, "en/security/", "https://example.test/product/", require_x_default=True))
                self.assertTrue(check_locale_navigation(valid.replace(tag, duplicate, 1), "en/security/", "https://example.test/product/", require_x_default=True))
                inert = valid.replace("</head>", f"<template>{duplicate}</template></head>", 1)
                self.assertEqual([], check_locale_navigation(inert, "en/security/", "https://example.test/product/", require_x_default=True))

    def test_locale_links_preserve_a_translated_deep_page(self):
        metadata, header = self.locale_page()
        self.assertEqual([], check_locale_navigation(metadata + header, "en/security/", "https://example.test/product/"))
        # Attribute quoting, order and an embedded '>' are HTML syntax, not boundaries.
        quoted = header.replace("data-nr-shell", 'data-note="1 > 0" data-nr-shell', 1).replace('"', "'")
        self.assertEqual([], check_locale_navigation(metadata + quoted, "en/security/", "https://example.test/product/"))

    def test_locale_missing_wrong_duplicate_and_script_spoofs_fail(self):
        metadata, header = self.locale_page()
        wrong = header.replace("https://example.test/product/de/security/", "https://example.test/product/de/")
        cases = [
            header.replace("nr-shell-locales", "removed-locales"),
            header.replace('hreflang="de"', 'hreflang="xx"'),
            header.replace('hreflang="en"', 'hreflang="de"'),
            header.replace(' lang="de"', ' lang="en"'),
            header.replace("https://example.test/product/en/security/", "https://wrong.test/product/en/security/"),
            wrong,
            wrong + f"<script>{header}</script>",
        ]
        for broken in cases:
            with self.subTest(markup=broken[:80]):
                self.assertTrue(check_locale_navigation(metadata + broken, "en/security/", "https://example.test/product/"))

    def test_only_chooser_adr_and_404_allow_locale_landing_fallbacks(self):
        root = "https://example.test/custom/base/"
        for path in ("index.html", "adr/index.html", "adr/adr001-example.html", "404.html"):
            with self.subTest(page=path):
                metadata, header = self.locale_page(path, declared=False, root=root)
                self.assertEqual([], check_locale_navigation(metadata + header, path, root))
                wrong = header.replace(root + "de/", root + "en/")
                self.assertTrue(check_locale_navigation(metadata + wrong, path, root))
                if path == "404.html":
                    self.assertEqual([], check_locale_navigation(header, path, root))
        metadata, header = self.locale_page(declared=False, root=root)
        self.assertTrue(check_locale_navigation(metadata + header, "en/security/", root))
        fake = '<script><link rel="alternate" hreflang="de" href="https://example.test/custom/base/de/"></script>'
        self.assertTrue(check_locale_navigation(metadata + fake + header, "en/security/", root))

    def test_renderers_keep_utf8_output_in_an_ascii_locale(self):
        script = (
            "import json,sys; from site_shell import render_header,render_page_navigation; "
            "data=json.load(sys.stdin); "
            "html=render_header(data['config']) if data['kind']=='global' else render_page_navigation(data['config']); "
            "sys.stdout.write(html)"
        )
        environment = {**os.environ, "PYTHONCOERCECLOCALE": "0", "PYTHONIOENCODING": "utf-8", "PYTHONDONTWRITEBYTECODE": "1"}
        for lang in ("de", "en"):
            for kind in ("global", "local"):
                with self.subTest(lang=lang, component=kind):
                    config = {"lang": lang, "assetBase": "assets/site-shell", "languageUrls": {"en": "./", "de": "de/"}, "active": None, "contactHref": "https://www.netresearch.de/kontakt/"}
                    if kind == "local":
                        config = {"lang": lang, "title": "Übersicht", "links": [{"href": "#details", "label": "Änderungen & Qualität"}]}
                    payload = json.dumps({"kind": kind, "config": config}).encode("ascii")
                    normal = subprocess.run([sys.executable, "-c", script], input=payload, cwd=SHELL, env={**environment, "LC_ALL": "C.UTF-8", "PYTHONUTF8": "1"}, capture_output=True, check=True).stdout
                    ascii_locale = subprocess.run([sys.executable, "-c", script], input=payload, cwd=SHELL, env={**environment, "LC_ALL": "C", "PYTHONUTF8": "0"}, capture_output=True, check=True).stdout
                    self.assertEqual(normal, ascii_locale)
                    self.assertIn(" – ".encode("utf-8"), ascii_locale)
