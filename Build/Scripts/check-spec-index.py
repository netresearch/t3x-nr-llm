#!/usr/bin/env python3
"""Check current specification inventory and local acceptance link integrity."""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
specs = root / "specs"
expected = {p.relative_to(specs).as_posix() for p in specs.glob("*/spec.md")}
expected.add("responsible-ai-run-privacy.md")
index = (specs / "README.md").read_text()
actual = set(re.findall(r"^\| \[[^]]+\]\(([^)]+)\)", index, re.M))
errors = []
if expected != actual:
    errors.append(f"Inventory mismatch: missing={expected-actual}, extra={actual-expected}")
contract = (specs / "current-contract.md").read_text()
ids = re.findall(r"^## ((?:S|C|RT|L)\d+):", contract, re.M)
if len(ids) != 35 or len(set(ids)) != 35:
    errors.append("The audit contract must contain exactly 35 unique requirement IDs")
for path in specs.rglob("*.md"):
    for link in re.findall(r"\[[^]]*\]\(([^)]+)\)", path.read_text()):
        if "://" in link or link.startswith("#"):
            continue
        target = (path.parent / link.split("#", 1)[0]).resolve()
        if not target.is_relative_to(root) or not target.exists():
            errors.append(f"{path.relative_to(root)}: broken local link {link}")
        if "#" in link and target.name == "current-contract.md":
            anchor = link.split("#", 1)[1]
            anchors = {re.sub(r"[^a-z0-9 -]", "", h.lower()).replace(" ", "-") for h in re.findall(r"^#+ (.*)$", target.read_text(), re.M)}
            if anchor not in anchors:
                errors.append(f"{path.relative_to(root)}: missing anchor {anchor}")
if errors:
    raise SystemExit("\n".join(errors))
print(f"Specification inventory: {len(expected)} entries, 35 requirements, local evidence links valid")
