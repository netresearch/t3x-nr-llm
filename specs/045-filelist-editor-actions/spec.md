<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# The file list offers the editor actions

The editor actions on `sys_file` and the folder batch are reachable only from
the module, where the file or folder has to be chosen again in the element
browser. ADR-158 left files out of the context menu because the file list names
a file by its combined identifier, not its uid. Decision record: ADR-267.

## What it must do

- Show **AI actions** in the file list's context menu of a file, linking to the
  catalogue for `sys_file` and that file's uid, when the user holds `tasks_use`,
  may open `nrllm_aitasks`, the catalogue offers an action on `sys_file`, and
  the user may change the file's metadata in a storage the FAL tools may use.
- Show **AI actions** on a folder the user may read in such a storage, linking
  to the catalogue for `sys_file` with the folder's combined identifier; there
  each offered action links to the batch page with the folder as its scope.
- Resolve the identifier with core's `retrieveFileOrFolderObject()`, after the
  grant and module checks; never cast it.
- Show no item, and keep the menu's other items, when the identifier resolves
  to nothing, throws, or names a storage, mount or file the user may not use.
- Keep records with integer uids exactly as before.

## What it explicitly does not do

- No batch over a selection of several files from the file list.
- No new authorisation in the catalogue or the batch page: the batch still asks
  every file as the acting user.
- No file name in the catalogue header.

## Which suite proves each requirement

| Requirement | Suite |
|-------------|-------|
| File link carries the `sys_file` uid, folder link carries the scope, storage outside the allow-list, unresolvable identifier, non-integer identifier on another table, no resolution without the grant | unit: `EditorActionItemProviderTest` |
| Folder reachable only inside the passed user's mount, storage outside the allow-list | functional: `FalStorageGateActingUserTest` |
| Catalogue in folder mode is narrowed to `sys_file`, names the folder, offers the folder batch and no single start; a folder on another table is ignored | functional: `EditorActionControllerTest` (the harness renders action links without a URL, so the link's `scope` argument is read from the template, not asserted) |
| Labels resolve in both languages | unit: `EditorActionCenterLabelsTest` |
