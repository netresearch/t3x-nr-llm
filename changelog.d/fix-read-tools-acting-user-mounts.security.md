- **The read tools check web mounts against the run's acting user (#1022).**
  `get_page_content`, `get_record_history`, `read_records`, `search_records`
  and `resolve_url`, and the `include_hidden` edit check of the two record
  readers, asked TYPO3's `BackendUtility::readPageAccess()`, which takes the
  acting user's page permissions but checks the web mount of the backend user
  in `$GLOBALS['BE_USER']`. In a queued or resumed agent run that is whoever
  runs the request: with an admin there, a non-admin's run read pages outside
  its mounts; with nobody there (worker, CLI), every page check of a
  non-admin's run failed with an error. The check now asks the acting user.
