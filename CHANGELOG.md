# Changelog

## 0.4.3

- Add a dedicated first-run state when Elasticsearch or AI configuration is incomplete.
- Link administrators directly to SunaQ Find settings while showing non-admin users a neutral setup notice.
- Guard search requests until the required Elasticsearch and AI endpoints are configured.
- Clarify that all administration changes become effective only after saving and that connection tests use saved values.
- Add App Store screenshots and a small SunaQ Find preview thumbnail.

## 0.4.2

- Reject positive AI answers that cannot be tied to valid evidence citations.
- Preserve authorized primary results when the optional expansion search fails.
- Validate calendar dates before promoting them to required retrieval constraints.
- Preserve hard-constraint kinds and enforce punctuation-exact complete filenames against Nextcloud titles.
- Keep analysis citation explanations limited to evidence records actually supplied to the answer model.
- Correct search-strategy wording for optional terms, typo alternatives, staged retrieval, and documented retrieval limits.

## 0.4.1

- Added `/list` for retrieval-only document lists and localized `/help` based on the bundled help text.
- Fixed the AI connectivity test in the administration section for Ollama.
- Hardened prompts to avoid occasional LLM-internal renumbering of evidence references.
- Raised the maximum AI document budget to 25.
- Introduced two-round Elasticsearch retrieval for more controlled use of semantic expansion.

## 0.4.0

- Initial public release of SunaQ Find.
- Native AI-assisted natural-language search over an existing Nextcloud FullTextSearch / Elasticsearch index.
- Deterministic Elasticsearch query compilation with bounded retrieval.
- Live Nextcloud ACL validation before document evidence can reach the configured LLM.
- Direct synchronous OpenAI-compatible LLM integration without a separate middleware, queue, or background worker.
- Unified Search integration, source links, configurable diagnostics, and English/German UI support.
- Supports Nextcloud 23–35.
