# Changelog fragments

Each pull request writes its changelog entry into its own file here instead of
editing `## [Unreleased]` in `CHANGELOG.md`. Two pull requests that both edit
that section conflict as soon as one of them merges; two fragment files do not.

## Format

- File name: `<branch-slug>.<section>.md`, lowercase, for example
  `fix-skill-sync-lock.fixed.md`. The branch slug keeps names unique.
- `<section>` selects the heading the entry lands under:

  | Section      | Heading in `CHANGELOG.md`                         |
  |--------------|---------------------------------------------------|
  | `upgrading`  | `### Upgrading from X.Y` (the previous 0.N line)  |
  | `added`      | `### Added`                                       |
  | `changed`    | `### Changed`                                     |
  | `deprecated` | `### Deprecated`                                  |
  | `removed`    | `### Removed`                                     |
  | `fixed`      | `### Fixed`                                       |
  | `security`   | `### Security`                                    |

- Content: one or more top-level bullets (`-` and a space), written as they will appear in
  `CHANGELOG.md`. No heading; the file name selects it. A change that belongs
  under two headings gets two files.
- A breaking change opens its top-level bullet with `- **BREAKING`, exactly and
  in upper case (for example
  `- **BREAKING: Foo::bar() is removed.** Call Foo::baz() instead.`). During 0.x that bullet
  is what lets a release raise the middle version number, and
  `composer ci:test:changelog:sections` refuses a release whose middle number
  disagrees with it (`Documentation/Api/Stability.rst`). Other bold forms
  (`**Breaking:**`, the word inside a bullet or a nested one) are refused.

`composer ci:test:changelog` checks the names and contents.

## Release

The release commit runs `composer changelog:collect` before it renames
`[Unreleased]`. The command appends every fragment, in file-name order, at the
end of its heading, creates a missing heading in Keep a Changelog order, and
deletes the fragment. With no fragments it changes nothing. The check fails a
release commit that leaves fragments here, because those notes would not ship.
