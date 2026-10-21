- **Skill metadata closes only at a standalone delimiter.** A YAML key beginning
  with three dashes no longer cuts off later metadata, including tool
  declarations. Malformed closing-marker prefixes are rejected; EOF delimiters,
  CRLF input and later Markdown separators remain supported.
