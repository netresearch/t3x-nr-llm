#!/usr/bin/env python3
"""Check current specification inventory and local acceptance link integrity."""

import re
from pathlib import Path

root = Path(__file__).resolve().parents[2]
specs = root / "specs"


def github_anchors(markdown: str) -> set[str]:
    """Anchors GitHub generates for the headings of a Markdown file."""
    anchors: set[str] = set()
    seen: dict[str, int] = {}
    for heading in re.findall(r"^#+ (.*)$", markdown, re.MULTILINE):
        slug = re.sub(r"[^\w\- ]", "", heading.strip().lower()).replace(" ", "-")
        count = seen.get(slug, 0)
        seen[slug] = count + 1
        anchors.add(slug if count == 0 else f"{slug}-{count}")
    return anchors


expected = {p.relative_to(specs).as_posix() for p in specs.glob("*/spec.md")}
expected.add("responsible-ai-run-privacy.md")
index = (specs / "README.md").read_text()
actual = set(re.findall(r"^\| \[[^]]+\]\(([^)]+)\)", index, re.MULTILINE))
errors = []
if expected != actual:
    errors.append(
        f"Inventory mismatch: missing={expected - actual}, extra={actual - expected}"
    )
contract = (specs / "current-contract.md").read_text()
ids = re.findall(r"^## ((?:S|C|RT|L)\d+):", contract, re.MULTILINE)
duplicates = sorted({i for i in ids if ids.count(i) > 1})
if not ids:
    errors.append("The audit contract names no requirement IDs")
if duplicates:
    errors.append(f"Duplicate requirement IDs in the audit contract: {duplicates}")
for path in specs.rglob("*.md"):
    for link in re.findall(r"\[[^]]*\]\(([^)]+)\)", path.read_text()):
        if "://" in link or link.startswith("#"):
            continue
        target = (path.parent / link.split("#", 1)[0]).resolve()
        if not target.is_relative_to(root) or not target.exists():
            errors.append(f"{path.relative_to(root)}: broken local link {link}")
            continue
        if "#" in link and target.suffix == ".md":
            anchor = link.split("#", 1)[1]
            if anchor not in github_anchors(target.read_text()):
                errors.append(f"{path.relative_to(root)}: missing anchor {link}")
if errors:
    raise SystemExit("\n".join(errors))
print(
    f"Specification inventory: {len(expected)} entries, {len(ids)} requirements,"
    " local evidence links valid"
)
