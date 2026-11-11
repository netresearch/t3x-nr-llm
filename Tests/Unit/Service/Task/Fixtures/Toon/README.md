<!-- SPDX-License-Identifier: MIT -->
<!-- SPDX-FileCopyrightText: 2025-present Johann Schopplich -->

# TOON specification encode fixtures

`arrays-tabular.json` and `primitives.json` are unmodified copies of
`tests/fixtures/encode/` from the TOON specification repository,
https://github.com/toon-format/spec, tag `v4.4.0`
(commit `d09c3d33e37389345a74bd96981eb96dd9be6c5e`).

They are published under the MIT License, Copyright (c) 2025-PRESENT Johann
Schopplich. `TableInputEncoderTest` reads them; ADR-286 records why.

To update them, copy the files from a newer tag and adjust the version the
encoder selects in `TableInputEncoder` in the same change.
