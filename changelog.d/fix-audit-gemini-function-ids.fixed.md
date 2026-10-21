- Echo Gemini native function-call IDs in their own results through reordered parallel calls and persisted approval resume; reject malformed or ambiguous owned associations while retaining ID-less and unambiguous legacy replay.

- Gemini now encodes known function argument and result roots as JSON objects,
  including empty and numeric-key maps in saved approval resumes. Public tool
  arguments and replay carriers remain unchanged; arbitrary nested JSON-kind
  loss remains outside this bounded repair (ADR-224).

- **Gemini tool conversations retain native thought signatures (ADR-222).** The existing response/transcript carrier preserves complete native parts through tool steps and stored resume. Gemini and OpenAI Responses distinguish ownership, keep foreign visible text and ordinary calls, and refuse ambiguous recognized replay state before contact.
