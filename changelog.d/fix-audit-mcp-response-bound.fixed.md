- **Unreadable MCP response streams return a typed failure.** Exceptions while
  obtaining or reading a response body now become a sanitized
  `McpTransportException`, so a failed remote stream follows the tool failure
  path. Complete replies within the bounded read prefix remain accepted.
