#!/usr/bin/env python3
"""Check an ontology bundle against the repository it describes.

The bundle is an Open Knowledge Format (OKF 0.3) directory: one Markdown file
per concept, with a YAML frontmatter block that names its `type`. Concepts
state their typed relations in a "# Relations" table. This script checks:

- every concept file has frontmatter with a non-empty `type`;
- every directory index lists every concept file of that directory;
- every relation names a relation of the bundle's `relations/` vocabulary;
- every relation target exists (a concept, or a path in the repository);
- every relation marked `asserted` names an evidence file that exists and
  contains the evidence text exactly once, so a code change that removes the
  evidence fails this check in the same change;
- every competency question with an `x-nr-query` shows the answer that
  query gives now.

`--impact <concept>` prints the concepts a change to that concept can affect,
following the relations whose vocabulary entry declares how a change
propagates, and the tests that check each affected concept. `--needs
<concept>` prints what the concept depends on. `--write-answers` rewrites the
stored answers of the competency questions.

Standard library only, because the repository-check jobs that run it install
no dependencies. The frontmatter is therefore checked for a "type:" line only;
a full YAML parse belongs in a job that can install a parser.
"""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path

RESERVED = {"index.md", "log.md"}
STATUSES = {"asserted", "hypothesis"}
PROPAGATION = {"to-source", "to-target", "none"}
LINK = re.compile(r"^\[[^\]]*\]\(([^)\s]+)\)$")
CODE = re.compile(r"^`([^`]+)`$")
ANSWER = re.compile(
    r"(# Answer at the time of writing\n\n```text\n)(.*?)(```\n)", re.DOTALL
)
QUERY = re.compile(r"^--(impact|needs) (\S+)$")


@dataclass
class Relation:
    source: str
    name: str
    target: str  # a concept id, "repo:<path>" or "url:<url>"
    status: str


@dataclass
class Bundle:
    root: Path
    repo: Path
    types: dict[str, str] = field(default_factory=dict)
    queries: dict[Path, str] = field(default_factory=dict)
    propagates: dict[str, str] = field(default_factory=dict)
    relations: list[Relation] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)

    def concept_id(self, path: Path) -> str:
        return path.relative_to(self.root).with_suffix("").as_posix()

    def shown(self, path: Path) -> str:
        return path.relative_to(self.repo).as_posix()


def frontmatter(text: str) -> dict[str, str] | None:
    """Top-level scalar keys of the frontmatter block, or None without one."""
    lines = text.split("\n")
    if not lines or lines[0].strip() != "---":
        return None
    keys: dict[str, str] = {}
    for line in lines[1:]:
        if line.strip() == "---":
            return keys
        match = re.match(r"^([A-Za-z_][\w-]*):\s*(.*)$", line)
        if match:
            keys[match.group(1)] = match.group(2).strip().strip("\"'")
    return None


def cells(row: str) -> list[str]:
    return [c.strip() for c in row.strip().strip("|").split("|")]


def resolve_target(bundle: Bundle, source: Path, cell: str) -> str | None:
    link = LINK.match(cell)
    if link:
        href = link.group(1).split("#", 1)[0]
        if re.match(r"^[a-z][a-z0-9+.-]*:", href):
            return "url:" + href
        base = bundle.root if href.startswith("/") else source.parent
        target = (base / href.lstrip("/")).resolve()
        if target.suffix != ".md" or not target.is_file():
            return None
        try:
            return bundle.concept_id(target)
        except ValueError:
            return None
    code = CODE.match(cell)
    if code and (bundle.repo / code.group(1)).exists():
        return "repo:" + code.group(1)
    return None


def normalise(text: str) -> str:
    return re.sub(r"\s+", " ", text)


def check_evidence(bundle: Bundle, where: str, cells_: list[str]) -> None:
    evidence, quote = CODE.match(cells_[2]), CODE.match(cells_[3])
    if not evidence or not quote:
        bundle.errors.append(
            f"{where}: an asserted relation needs an evidence file and text"
        )
        return
    path = bundle.repo / evidence.group(1)
    if not path.is_file():
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} does not exist"
        )
        return
    count = normalise(path.read_text()).count(normalise(quote.group(1)))
    if count != 1:
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} contains {quote.group(1)!r} "
            f"{count} times; quote a literal that occurs exactly once"
        )


def read_relations(bundle: Bundle, path: Path, text: str) -> None:
    source = bundle.concept_id(path)
    in_section = False
    for number, line in enumerate(text.split("\n"), start=1):
        if line.startswith("#"):
            in_section = line.strip() == "# Relations"
            continue
        if not in_section or not line.startswith("|"):
            continue
        row = cells(line)
        if row[0] == "Relation" or set(row[0]) <= {"-", ":"}:
            continue
        where = f"{bundle.shown(path)}:{number}"
        if len(row) != 5:
            bundle.errors.append(
                f"{where}: a relation row needs 5 cells, found {len(row)}"
            )
            continue
        name, status = row[0].strip("`"), row[4].strip("`")
        target = resolve_target(bundle, path, row[1])
        if target is None:
            bundle.errors.append(f"{where}: target {row[1]} resolves to nothing")
            continue
        if status not in STATUSES:
            bundle.errors.append(
                f"{where}: status {status!r} is not one of {sorted(STATUSES)}"
            )
        elif status == "asserted":
            check_evidence(bundle, where, row)
        bundle.relations.append(Relation(source, name, target, status))


def load(root: Path, repo: Path) -> Bundle:
    bundle = Bundle(root.resolve(), repo.resolve())
    files = sorted(p for p in bundle.root.rglob("*.md") if p.name not in RESERVED)
    for path in files:
        meta = frontmatter(path.read_text())
        if meta is None:
            bundle.errors.append(f"{bundle.shown(path)}: no frontmatter block")
            continue
        if not meta.get("type"):
            bundle.errors.append(f"{bundle.shown(path)}: frontmatter has no type")
            continue
        bundle.types[bundle.concept_id(path)] = meta["type"]
        if meta["type"] == "Relation":
            bundle.propagates[path.stem] = meta.get("x-nr-propagates", "")
        if meta.get("x-nr-query"):
            bundle.queries[path] = meta["x-nr-query"]
    for directory in sorted({p.parent for p in files}):
        index = directory / "index.md"
        listed = (
            set(re.findall(r"\]\(([^)]+\.md)\)", index.read_text()))
            if index.is_file()
            else set()
        )
        for path in sorted(directory.glob("*.md")):
            if path.name not in RESERVED and path.name not in listed:
                bundle.errors.append(
                    f"{bundle.shown(path)}: not listed in {bundle.shown(index)}"
                )
    for name, mode in sorted(bundle.propagates.items()):
        if mode not in PROPAGATION:
            bundle.errors.append(
                f"relation {name}: x-nr-propagates must be one of {sorted(PROPAGATION)}"
            )
    for path in files:
        if bundle.concept_id(path) in bundle.types:
            read_relations(bundle, path, path.read_text())
    for relation in bundle.relations:
        if relation.name not in bundle.propagates:
            bundle.errors.append(
                f"{relation.source}: relation {relation.name!r} is not defined in the vocabulary"
            )
    return bundle


def traverse(bundle: Bundle, start: str, needs: bool) -> list[str]:
    """The concepts reached from start, each with its path and its tests."""
    affected: dict[str, list[str]] = {start: []}
    queue = [start]
    while queue:
        changed = queue.pop(0)
        for r in bundle.relations:
            mode = bundle.propagates.get(r.name)
            if (
                needs
                and mode == "to-source"
                and r.source == changed
                and r.name != "implementedBy"
            ):
                reached, step = r.target, f"{changed} {r.name} {r.target}"
            elif not needs and mode == "to-source" and r.target == changed:
                reached, step = r.source, f"{r.source} {r.name} {changed}"
            elif not needs and mode == "to-target" and r.source == changed:
                reached, step = r.target, f"{changed} {r.name} {r.target}"
            else:
                continue
            if reached in affected or reached.startswith("repo:"):
                continue
            if r.status != "asserted":
                step += " (hypothesis)"
            affected[reached] = affected[changed] + [step]
            if not reached.startswith("url:"):
                queue.append(reached)
    lines: list[str] = []
    for concept, steps in affected.items():
        lines.append(concept)
        lines.extend(f"  via {step}" for step in steps)
        lines.extend(
            f"  test {r.target.removeprefix('repo:')}"
            for r in bundle.relations
            if r.source == concept and r.name == "verifiedBy"
        )
    lines.append(
        "Scope: only the relations this bundle models; coverage.md names what it leaves out."
    )
    return lines


def answer(bundle: Bundle, query: str) -> list[str] | None:
    match = QUERY.match(query)
    if not match or match.group(2) not in bundle.types:
        return None
    return traverse(bundle, match.group(2), needs=match.group(1) == "needs")


def check_answers(bundle: Bundle, write: bool) -> None:
    for path, query in sorted(bundle.queries.items()):
        lines = answer(bundle, query)
        if lines is None:
            bundle.errors.append(
                f"{bundle.shown(path)}: x-nr-query {query!r} names no concept of the bundle"
            )
            continue
        text = path.read_text()
        stored = ANSWER.search(text)
        if not stored:
            bundle.errors.append(
                f"{bundle.shown(path)}: no fenced answer under '# Answer at the time of writing'"
            )
        elif write:
            path.write_text(
                text[: stored.start(2)]
                + "\n".join(lines)
                + "\n"
                + text[stored.end(2) :]
            )
        elif stored.group(2) != "\n".join(lines) + "\n":
            bundle.errors.append(
                f"{bundle.shown(path)}: stored answer is stale; run with --write-answers"
            )


def main() -> int:
    parser = argparse.ArgumentParser(description=(__doc__ or "").split("\n\n")[0])
    parser.add_argument("bundle", type=Path, help="bundle directory")
    parser.add_argument(
        "--repo", type=Path, default=Path.cwd(), help="repository root (default: cwd)"
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--impact", metavar="CONCEPT", help="what a change to CONCEPT can affect"
    )
    mode.add_argument("--needs", metavar="CONCEPT", help="what CONCEPT depends on")
    mode.add_argument(
        "--write-answers", action="store_true", help="rewrite the stored answers"
    )
    args = parser.parse_args()
    bundle = load(args.bundle, args.repo)
    if args.impact or args.needs:
        start = args.impact or args.needs
        if start not in bundle.types:
            print(f"unknown concept {start!r}", file=sys.stderr)
            return 2
        print("\n".join(traverse(bundle, start, needs=bool(args.needs))))
        return 1 if bundle.errors else 0
    check_answers(bundle, write=args.write_answers)
    for error in bundle.errors:
        print(error, file=sys.stderr)
    if bundle.errors:
        print(
            f"{len(bundle.errors)} finding(s) in {len(bundle.types)} files",
            file=sys.stderr,
        )
        return 1
    asserted = sum(1 for r in bundle.relations if r.status == "asserted")
    print(
        f"ok: {len(bundle.types)} files, {len(bundle.relations)} relations, {asserted} of them asserted"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
