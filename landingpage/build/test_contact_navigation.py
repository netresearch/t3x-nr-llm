# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
import unittest
from urllib.parse import parse_qs, urlsplit

from build import contact_url, site_header


class ContactNavigationTest(unittest.TestCase):
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
