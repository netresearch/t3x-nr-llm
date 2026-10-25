# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
import unittest
from html import escape
from urllib.parse import parse_qs, urlsplit

from build import contact_url, site_header
from check_site_shell import check_business_contacts


class ContactNavigationTest(unittest.TestCase):
    def test_invalid_hero_cannot_hide_behind_valid_header_and_footer(self):
        for lang in ("en", "de"):
            header = site_header(lang, [])
            footer = f'<a data-cta="business" href="{escape(contact_url("footer", lang), quote=True)}">Footer</a>'
            def fixture(href):
                attribute = f' href="{escape(href, quote=True)}"' if href is not None else ""
                return header + f'<a data-cta="business"{attribute}>Hero</a>' + footer
            valid = fixture(contact_url("hero", lang))
            with self.subTest(lang=lang):
                self.assertEqual([], check_business_contacts(valid, lang))
                self.assertEqual([], check_business_contacts(valid.replace("&amp;", "&#38;"), lang))
                for href in (None, "https://wrong.test/contact/", contact_url("hero", lang).replace("https:", "http:"),
                             contact_url("hero", lang).replace("utm_medium=referral", "unused=referral")):
                    self.assertTrue(check_business_contacts(fixture(href), lang))
                self.assertTrue(check_business_contacts(f"<script>{valid}</script>", lang))
                self.assertTrue(check_business_contacts(f"<!--{valid}-->", lang))

    def test_contact_language_and_campaign_survive_every_position(self):
        for lang, path in (("en", "/en/contact/"), ("de", "/kontakt/")):
            for position in ("header", "hero", "cta-band", "footer"):
                with self.subTest(lang=lang, position=position):
                    target = urlsplit(contact_url(position, lang))
                    self.assertEqual("https", target.scheme)
                    self.assertEqual("www.netresearch.de", target.netloc)
                    self.assertEqual(path, target.path)
                    self.assertEqual({"utm_source": ["github-pages"], "utm_medium": ["referral"],
                                      "utm_campaign": ["nr-llm"], "utm_content": [position]}, parse_qs(target.query))

    def test_header_contact_preserves_page_language(self):
        for lang, path in (("en", "/en/contact/"), ("de", "/kontakt/")):
            with self.subTest(lang=lang):
                self.assertIn(f'href="https://www.netresearch.de{path}?', site_header(lang, []))
