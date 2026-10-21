# SunaQ Find architecture

SunaQ Find is a native, files-only Nextcloud search application. It reuses the
existing FullTextSearch / Elasticsearch index instead of introducing a second
RAG infrastructure.

## Runtime path

```text
natural-language question
  -> QueryRewriteService
  -> normalized SearchSpec
  -> RetrievalPlanner
  -> ElasticsearchCompiler
  -> ElasticsearchSearch
  -> live Nextcloud ACL
  -> EvidenceBuilder
  -> AnswerService
  -> answer + Nextcloud source links
```

## Search planning

The LLM produces a backend-neutral SearchSpec. It does not produce Elasticsearch
DSL.

The SearchSpec can contain entities, concepts, lexical alternative groups,
constraints and verification requirements. Host code normalizes this structure
before use.

A direct lexical route is available when enabled by the administrator and the
planner classifies the original query as lexically sufficient. Otherwise the
structured semantic route is used.

If planning fails or produces no usable positive lexical anchors, the app falls
back to a broad bounded search using the original question.

## Deterministic Elasticsearch compilation

`ElasticsearchCompiler` owns the conversion from SearchPlan to Elasticsearch
query structure. LLM output is never executed as Elasticsearch syntax.

The active and only retrieval arm is `files` / Elasticsearch.

## Authorization boundary

Elasticsearch is not trusted to authorize access.

SunaQ Find can apply a candidate prefilter using the authenticated Nextcloud UID
and group IDs against FullTextSearch metadata fields. This reduces candidate
work but does not replace authorization.

Every candidate is subsequently resolved through the current user's Nextcloud
filesystem view. A candidate must be readable there before its title, path or
text can enter the evidence envelope.

The administrator can disable the metadata prefilter. If it is disabled, or if
identity metadata cannot be used technically, SunaQ Find sends the same
deterministically bounded query without the metadata filter and still applies
live ACL checks to every returned candidate.

The configured Elasticsearch document limit is a hard `size` bound. There is
no automatic refill, second query, or overfetch budget after ACL rejection. A
separate administrator-controlled evidence limit bounds how many authorized
results can be sent to the answer model.

## Evidence boundary

Authorized search snippets are serialized into a server-owned JSON evidence
envelope. Document text remains untrusted model input: evidence cannot redefine
system instructions, citation rules or output format.

The answer layer is instructed to use only supplied evidence and to cite the
corresponding evidence indices.

## Language model boundary

The application talks directly from PHP to the configured OpenAI-compatible
chat-completions endpoint through Nextcloud's HTTP client.

Internal prompt instructions are English. User-facing language is carried
separately as `interaction_language`. Lexical document-search languages are
configured independently through `retrieval_languages`.

## State

SunaQ Find stores only application configuration. It has no conversation state,
vector store, document mirror, background synchronization database or separate
middleware service.

## Current limits

- files / Elasticsearch only;
- one retrieval round;
- snippet-based evidence;
- synchronous LLM calls;
- no chat or conversation archive;
- no external web research;
- no vector or graph retrieval.
