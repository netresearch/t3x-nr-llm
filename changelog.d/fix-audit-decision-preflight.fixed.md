- **Decision preflight failures follow the public contract (ADR-211).** Both
  `evaluate()` and `assertAvailable()` wrap infrastructure failures during
  configuration lookup and routing in `DecisionException`, retaining their
  cause and configuration context. Budget-subject lookup is covered during
  evaluation; policy denials keep their identity and engine errors propagate.
