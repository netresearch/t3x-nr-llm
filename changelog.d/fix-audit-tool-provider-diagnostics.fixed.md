- **Tool-provider diagnostics do not abort registration.** Logger failures
  while reporting a colliding name or a broken provider preserve registered
  rows and healthy providers. Diagnostics retain their original level, message
  and cause; builtin validation and execution gates keep their behavior.
