# Changelog

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
