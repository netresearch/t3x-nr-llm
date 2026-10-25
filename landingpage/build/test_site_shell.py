# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
import sys
import json
import os
import subprocess
import unittest
from pathlib import Path
from check_site_shell import check_locale_navigation, check_site_shell

SHELL = Path(__file__).resolve().parent.parent / "site-shell"
sys.path.insert(0, str(SHELL))
from site_shell import render_header


class ShellIdentityTest(unittest.TestCase):
    def setUp(self):
        self.symbol = (SHELL / "netresearch-symbol-only.svg").read_bytes()
        self.header = render_header({"lang": "en", "assetBase": "assets/site-shell", "languageUrls": {"en": "./", "de": "de/"}, "active": None, "contactHref": "https://www.netresearch.de/kontakt/"})

    def test_authentic_linked_header(self):
        self.assertEqual([], check_site_shell(self.header, self.symbol))

    def test_duplicates_wrong_company_and_altered_symbol_fail(self):
        import re
        img = re.search(r"<img[^>]*>", self.header)[0]
        self.assertTrue(check_site_shell(self.header + img, self.symbol))
        self.assertTrue(check_site_shell(self.header.replace("https://www.netresearch.de/", "https://example.com/"), self.symbol))
        self.assertTrue(check_site_shell(self.header, self.symbol.replace(b"#2F99A4", b"#000000")))

    def test_quoted_angle_brackets_and_script_text_are_not_elements(self):
        quoted = self.header.replace("data-nr-shell", 'data-example="a > b" data-nr-shell', 1)
        self.assertEqual([], check_site_shell(quoted, self.symbol))
        self.assertEqual([], check_site_shell(self.header + f"<script>{self.header}</script>", self.symbol))

    def test_marker_text_does_not_replace_a_shell_attribute(self):
        lookalike = self.header.replace("data-nr-shell", 'data-example="data-nr-shell"', 1)
        self.assertTrue(check_site_shell(lookalike, self.symbol))

    def test_symbol_contract_rejects_missing_unlinked_and_inaccessible_images(self):
        for old, new in (('width="44"', 'width="40"'), ('height="44"', 'height="40"'),
                         ('alt="Netresearch DTT GmbH"', 'alt=""'),
                         ("netresearch-symbol-only.svg", "other.svg"),
                         ('href="https://www.netresearch.de/"', 'data-href="https://www.netresearch.de/"')):
            with self.subTest(attribute=old):
                self.assertTrue(check_site_shell(self.header.replace(old, new), self.symbol))

    def locale_page(self, path="en/security/", declared=True, root="https://example.test/product/"):
        languages = {lang: root + f"{lang}/security/" for lang in ("de", "en")}
        canonical = root if path == "index.html" else root + path.removesuffix("index.html")
        metadata = f'<link rel="canonical" href="{canonical}">'
        if declared:
            metadata += "".join(f'<link rel="alternate" hreflang="{lang}" href="{href}">' for lang, href in languages.items())
        else:
            metadata += f'<link rel="alternate" hreflang="x-default" href="{canonical}">'
            languages = {lang: root + f"{lang}/" for lang in ("de", "en")}
        header = render_header({"lang": "en", "assetBase": "assets/site-shell",
                                "languageUrls": languages, "active": None,
                                "contactHref": "https://www.netresearch.de/kontakt/"})
        return metadata, header

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

    def test_only_chooser_and_adr_allow_locale_landing_fallbacks(self):
        root = "https://example.test/custom/base/"
        for path in ("index.html", "adr/index.html", "adr/adr001-example.html"):
            with self.subTest(page=path):
                metadata, header = self.locale_page(path, declared=False, root=root)
                self.assertEqual([], check_locale_navigation(metadata + header, path, root))
                wrong = header.replace(root + "de/", root + "en/")
                self.assertTrue(check_locale_navigation(metadata + wrong, path, root))
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
