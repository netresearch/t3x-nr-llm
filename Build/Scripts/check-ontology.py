#!/usr/bin/env python3
"""Check an ontology bundle against the repository it describes.

The bundle is an Open Knowledge Format (OKF 0.3) directory: one Markdown file
per concept, with a YAML frontmatter block that names its `type`. Concepts
state their typed relations in a "# Relations" table. This script checks:

- every concept file has frontmatter with a non-empty `type`;
- every directory index lists every concept file of that directory, and each
  entry repeats the `description` of the concept it lists;
- every relation names a relation of the bundle's `relations/` vocabulary;
- every relation target exists (a concept of the bundle, or a path in the
  repository);
- every relation marked `asserted` names an evidence file that exists and
  contains the evidence text exactly once (whitespace differences ignored), so
  a code change that removes the evidence fails this check in the same change;
- every competency question with an `x-nr-query` shows the answer that
  query gives now.

`--impact <concept>` prints the concepts a change to that concept can affect,
following the relations whose vocabulary entry declares how a change
propagates, and the tests that check each affected concept. `--needs
<concept>` prints what the concept depends on, including the parts it is made
of. `--write-answers` rewrites the stored answers of the competency questions.

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
REPO_TARGET = {"implementedBy", "verifiedBy"}
NO_RELATIONS = {"Relation", "Competency Question", "Reference"}
STATUSES = {"asserted", "hypothesis"}
PROPAGATION = {"to-source", "to-target", "none"}
LINK = re.compile(r"^\[[^\]]*\]\(([^)\s]+)\)$")
CODE = re.compile(r"^`([^`]+)`$")
ANSWER = re.compile(
    r"(# Answer at the time of writing\n\n```text\n)(.*?)(```\n)", re.DOTALL
)
QUERY = re.compile(r"^--(impact|needs) (\S+)$")
FENCE = re.compile(r"^\s*(```|~~~)")
ENTRY = re.compile(r"^\* \[[^\]]*\]\(([^)]+\.md)\) - (.*)$")


@dataclass
class Relation:
    source: str
    name: str
    target: str  # a concept id, "repo:<path>", "url:<url>" or "" when unresolved
    status: str
    where: str
    target_cell: str


@dataclass
class Bundle:
    root: Path
    repo: Path
    types: dict[str, str] = field(default_factory=dict)
    descriptions: dict[Path, str] = field(default_factory=dict)
    queries: dict[Path, str] = field(default_factory=dict)
    propagates: dict[str, str] = field(default_factory=dict)
    relations: list[Relation] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)

    def concept_id(self, path: Path) -> str:
        return path.relative_to(self.root).with_suffix("").as_posix()

    def shown(self, path: Path) -> str:
        try:
            return path.relative_to(self.repo).as_posix()
        except ValueError:
            return path.as_posix()


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] == '"':
        return re.sub(r'\\(["\\])', r"\1", value[1:-1])
    if len(value) >= 2 and value[0] == value[-1] == "'":
        return value[1:-1].replace("''", "'")
    return value


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
            keys[match.group(1)] = unquote(match.group(2))
    return None


def body_lines(text: str):
    """Numbered lines outside fenced code blocks."""
    fenced = False
    for number, line in enumerate(text.split("\n"), start=1):
        if FENCE.match(line):
            fenced = not fenced
            continue
        if not fenced:
            yield number, line


def cells(row: str) -> list[str]:
    parts = re.split(r"(?<!\\)\|", row.strip().strip("|"))
    return [part.strip().replace("\\|", "|") for part in parts]


def normalise(text: str) -> str:
    return re.sub(r"\s+", " ", text)


def resolve_target(bundle: Bundle, source: Path, cell: str) -> str:
    link = LINK.match(cell)
    if link:
        href = link.group(1).split("#", 1)[0]
        if re.match(r"^[a-z][a-z0-9+.-]*:", href):
            return "url:" + href
        base = bundle.root if href.startswith("/") else source.parent
        target = (base / href.lstrip("/")).resolve()
        if target.suffix == ".md" and target.is_file():
            try:
                return bundle.concept_id(target)
            except ValueError:
                return ""
        return ""
    code = CODE.match(cell)
    if (
        code
        and inside(bundle, code.group(1))
        and (bundle.repo / code.group(1)).exists()
    ):
        return "repo:" + code.group(1)
    return ""


def inside(bundle: Bundle, relative: str) -> bool:
    """True when a repository path stays inside the repository."""
    if relative.startswith("/"):
        return False
    return (bundle.repo / relative).resolve().is_relative_to(bundle.repo)


def check_evidence(bundle: Bundle, where: str, row: list[str]) -> None:
    evidence, quote = CODE.match(row[2]), CODE.match(row[3])
    if not evidence or not quote:
        bundle.errors.append(
            f"{where}: an asserted relation needs an evidence file and text"
        )
        return
    path = bundle.repo / evidence.group(1)
    if not inside(bundle, evidence.group(1)):
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} lies outside the repository"
        )
        return
    if not path.is_file():
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} does not exist"
        )
        return
    try:
        content = read(path)
    except UnicodeDecodeError:
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} is not UTF-8 text"
        )
        return
    count = normalise(content).count(normalise(quote.group(1)))
    if count != 1:
        bundle.errors.append(
            f"{where}: evidence file {evidence.group(1)} contains {quote.group(1)!r} "
            f"{count} times; quote a literal that occurs exactly once"
        )


def read_relations(bundle: Bundle, path: Path, text: str) -> None:
    source = bundle.concept_id(path)
    in_section = False
    has_section = False
    for number, line in body_lines(text):
        where = f"{bundle.shown(path)}:{number}"
        if line.startswith("#"):
            in_section = line.strip() == "# Relations"
            has_section = has_section or in_section
            if re.match(r"^#{2,} Relations\s*$", line):
                bundle.errors.append(
                    f"{where}: the relations heading must be '# Relations'"
                )
            continue
        if not in_section or not line.strip():
            continue
        if not line.startswith("|"):
            bundle.errors.append(
                f"{where}: a line in '# Relations' that is not a table row"
            )
            continue
        row = cells(line)
        if row[0] == "Relation" or all(re.fullmatch(r":?-+:?", cell) for cell in row):
            continue
        if len(row) != 5:
            bundle.errors.append(
                f"{where}: a relation row needs 5 cells, found {len(row)}"
            )
            continue
        name, status = row[0].strip("`"), row[4].strip("`")
        if not name:
            bundle.errors.append(f"{where}: a relation row has no relation name")
            continue
        if status not in STATUSES:
            bundle.errors.append(
                f"{where}: status {status!r} is not one of {sorted(STATUSES)}"
            )
        elif status == "asserted":
            check_evidence(bundle, where, row)
        target = resolve_target(bundle, path, row[1])
        bundle.relations.append(Relation(source, name, target, status, where, row[1]))
    if not has_section and bundle.types.get(source) not in NO_RELATIONS:
        bundle.errors.append(
            f"{bundle.shown(path)}: a concept needs a '# Relations' section"
        )


def check_indexes(bundle: Bundle, files: list[Path]) -> None:
    directories = {p.parent for p in bundle.root.rglob("*.md")} | {bundle.root}
    for directory in sorted(directories):
        if directory != bundle.root:
            parent_index = directory.parent / "index.md"
            linked = parent_index.is_file() and any(
                match
                and (directory.parent / match.group(1)).resolve()
                == (directory / "index.md").resolve()
                for match in (
                    ENTRY.match(line.strip())
                    for _, line in body_lines(read(parent_index))
                )
            )
            if not linked:
                bundle.errors.append(
                    f"{bundle.shown(directory / 'index.md')}: not listed in {bundle.shown(parent_index)}"
                )
        index = directory / "index.md"
        if not index.is_file():
            bundle.errors.append(f"{bundle.shown(index)} does not exist")
            continue
        entries: dict[Path, str] = {}
        for _, line in body_lines(read(index)):
            match = ENTRY.match(line.strip())
            if match:
                href = match.group(1)
                base = bundle.root if href.startswith("/") else directory
                entries[(base / href.lstrip("/")).resolve()] = match.group(2).strip()
        for path in sorted(directory.glob("*.md")):
            if path.name in RESERVED or path not in bundle.descriptions:
                continue
            listed = entries.get(path.resolve())
            if listed is None:
                bundle.errors.append(
                    f"{bundle.shown(path)}: not listed in {bundle.shown(index)}"
                )
            elif bundle.descriptions[path] and listed != bundle.descriptions[path]:
                bundle.errors.append(
                    f"{bundle.shown(index)}: the entry for {path.name} does not repeat its description"
                )
        for target in sorted(entries):
            if target != index.resolve() and not target.is_file():
                bundle.errors.append(
                    f"{bundle.shown(index)}: an entry points to {bundle.shown(target)}, which does not exist"
                )


def load(root: Path, repo: Path) -> Bundle:
    bundle = Bundle(root.resolve(), repo.resolve())
    files = sorted(p for p in bundle.root.rglob("*.md") if p.name not in RESERVED)
    texts: dict[Path, str] = {}
    for path in files:
        try:
            texts[path] = read(path)
        except UnicodeDecodeError:
            bundle.errors.append(f"{bundle.shown(path)}: not UTF-8 text")
            continue
        meta = frontmatter(texts[path])
        if meta is None:
            bundle.errors.append(f"{bundle.shown(path)}: no frontmatter block")
            continue
        if not meta.get("type"):
            bundle.errors.append(f"{bundle.shown(path)}: frontmatter has no type")
            continue
        bundle.types[bundle.concept_id(path)] = meta["type"]
        bundle.descriptions[path] = meta.get("description", "")
        if meta["type"] == "Relation":
            bundle.propagates[path.stem] = meta.get("x-nr-propagates", "")
        if meta.get("x-nr-query"):
            bundle.queries[path] = meta["x-nr-query"]
        elif meta["type"] == "Competency Question":
            bundle.errors.append(
                f"{bundle.shown(path)}: a competency question needs an x-nr-query"
            )
    check_indexes(bundle, files)
    for name, mode in sorted(bundle.propagates.items()):
        if mode not in PROPAGATION:
            bundle.errors.append(
                f"relation {name}: x-nr-propagates must be one of {sorted(PROPAGATION)}"
            )
    for path in files:
        if bundle.concept_id(path) in bundle.types:
            read_relations(bundle, path, texts[path])
    for relation in bundle.relations:
        if relation.name not in bundle.propagates:
            bundle.errors.append(
                f"{relation.where}: relation {relation.name!r} is not defined in the vocabulary"
            )
        if relation.name in REPO_TARGET and not relation.target.startswith("repo:"):
            bundle.errors.append(
                f"{relation.where}: {relation.name} needs a path in the repository as its target"
            )
        external = relation.target.startswith(("repo:", "url:"))
        if not external and bundle.types.get(relation.target) in (None, *NO_RELATIONS):
            bundle.errors.append(
                f"{relation.where}: target {relation.target_cell} is no concept of the bundle"
            )
            relation.target = ""
    return bundle


def traverse(bundle: Bundle, start: str, needs: bool) -> list[str]:
    """The concepts reached from start, each with its path and its tests."""
    affected: dict[str, list[str]] = {start: []}
    queue = [start]
    while queue:
        changed = queue.pop(0)
        for r in bundle.relations:
            mode = bundle.propagates.get(r.name)
            if not r.target or r.name == "implementedBy":
                continue
            forward = mode == "to-source" and r.source == changed
            backward = mode == "to-target" and r.target == changed
            if needs and (forward or backward):
                reached = r.target if forward else r.source
            elif not needs and mode == "to-source" and r.target == changed:
                reached = r.source
            elif not needs and mode == "to-target" and r.source == changed:
                reached = r.target
            else:
                continue
            if reached in affected or reached.startswith("repo:"):
                continue
            step = f"{r.source} {r.name} {r.target}"
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


def answer(bundle: Bundle, query: str) -> list[str] | str:
    """The answer lines of a query, or the reason the query cannot run."""
    match = QUERY.match(query)
    if not match:
        return "x-nr-query must be '--impact <concept>' or '--needs <concept>'"
    if bundle.types.get(match.group(2)) in (None, *NO_RELATIONS):
        return f"x-nr-query names {match.group(2)!r}, which is no concept of the bundle"
    return traverse(bundle, match.group(2), needs=match.group(1) == "needs")


def check_answers(bundle: Bundle, write: bool) -> None:
    for path, query in sorted(bundle.queries.items()):
        lines = answer(bundle, query)
        if isinstance(lines, str):
            bundle.errors.append(f"{bundle.shown(path)}: {lines}")
            continue
        text = read(path)
        stored = ANSWER.search(text)
        if not stored:
            bundle.errors.append(
                f"{bundle.shown(path)}: no fenced answer under '# Answer at the time of writing'"
            )
        elif write:
            new = (
                text[: stored.start(2)]
                + "\n".join(lines)
                + "\n"
                + text[stored.end(2) :]
            )
            path.write_text(new, encoding="utf-8")
        elif stored.group(2) != "\n".join(lines) + "\n":
            bundle.errors.append(
                f"{bundle.shown(path)}: stored answer is stale; run with --write-answers"
            )


def repository_root(bundle: Path) -> Path:
    """The nearest ancestor of the bundle that holds a .git entry, else the cwd."""
    for directory in [bundle.resolve(), *bundle.resolve().parents]:
        if (directory / ".git").exists():
            return directory
    return Path.cwd()


def report(bundle: Bundle) -> None:
    for error in bundle.errors:
        print(error, file=sys.stderr)
    if bundle.errors:
        print(
            f"{len(bundle.errors)} finding(s) in {len(bundle.types)} files",
            file=sys.stderr,
        )


def main() -> int:
    parser = argparse.ArgumentParser(description=(__doc__ or "").split("\n\n")[0])
    parser.add_argument("bundle", type=Path, help="bundle directory")
    parser.add_argument(
        "--repo",
        type=Path,
        help="repository root (default: the nearest ancestor with .git)",
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
    if not args.bundle.is_dir():
        print(f"{args.bundle}: no such directory", file=sys.stderr)
        return 2
    bundle = load(args.bundle, args.repo or repository_root(args.bundle))
    if not bundle.types:
        print(f"{args.bundle}: holds no concept file", file=sys.stderr)
        return 2
    if args.impact or args.needs:
        start = args.impact or args.needs
        if bundle.types.get(start) in (None, *NO_RELATIONS):
            print(f"unknown concept {start!r}", file=sys.stderr)
            return 2
        print("\n".join(traverse(bundle, start, needs=bool(args.needs))))
        report(bundle)
        return 1 if bundle.errors else 0
    check_answers(bundle, write=args.write_answers)
    report(bundle)
    if bundle.errors:
        return 1
    asserted = sum(1 for r in bundle.relations if r.status == "asserted")
    print(
        f"ok: {len(bundle.types)} files, {len(bundle.relations)} relations, {asserted} of them asserted"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
