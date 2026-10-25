# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
"""Standalone shell identity checks against the authentic branding asset."""
import hashlib
import re

SYMBOL_SHA256 = "ca88779a6d008b4606bbe8d45f4640d6967f82b66b302d95b9153dcf2cfb9e29"


def check_site_shell(markup: str, symbol: bytes) -> list[str]:
    errors = []
    headers = re.findall(r"<header\b[^>]*data-nr-shell[^>]*>[\s\S]*?</header>", markup)
    logos = re.findall(r'<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>', markup)
    if len(headers) != 1 or len(logos) != 1 or logos[0] not in headers[0]:
        errors.append("exactly one canonical symbol must appear in the shared header")
    company_link = r'<a\b[^>]*href="https://www\.netresearch\.de/"[^>]*>\s*<img\b[^>]*src="[^"]*netresearch-symbol-only\.svg"[^>]*>\s*</a>'
    if not headers or not re.search(company_link, headers[0]):
        errors.append("header symbol must link to Netresearch DTT GmbH")
    if not logos or not all(part in logos[0] for part in ('width="44"', 'height="44"', 'alt="Netresearch DTT GmbH"')):
        errors.append("header symbol must retain its dimensions and accessible name")
    if hashlib.sha256(symbol).hexdigest() != SYMBOL_SHA256:
        errors.append("header symbol differs from the authentic brand asset")
    return errors
