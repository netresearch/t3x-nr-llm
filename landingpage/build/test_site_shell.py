# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
import sys
import json
import os
import subprocess
import unittest
from pathlib import Path
from check_site_shell import check_site_shell

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
