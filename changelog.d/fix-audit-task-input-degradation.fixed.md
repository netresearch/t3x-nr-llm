- **Task input remains best effort when readers or diagnostics fail.**
  Deprecation-log reader exceptions and errors return the existing localized
  read-error placeholder. Syslog, table, picker-policy and deprecation failures
  attempt to log their original cause and context; a failing logger cannot
  replace generic input or expose private reader or diagnostic details.
