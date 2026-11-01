- `tx_nrllm_ai_written` follows the text. An update that changed only settings and a
  translation that failed set it no longer, the copy of a marked record keeps it for every
  user, a partly applied `update_page_metadata` sets it, and a run cannot clear it.
- `update_page_metadata` reports a partly applied update as written in part. It used to
  report an error for values that had landed.
