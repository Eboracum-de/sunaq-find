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
DSL. This planning step is LLM-driven and therefore probabilistic: repeated identical requests are not guaranteed to produce identical SearchSpecs. LLM-variance in concept groups,
expansion terms or verification requirements can change the candidate
set and evidence available to the answer layer. Variance can be tested in anaylsis mode.

Determinism begins at the host-code boundary after SearchSpec normalization:
given the same normalized SearchSpec and index state, Elasticsearch compilation,
retrieval bounds and ACL enforcement are application-controlled rather than
model-generated.

The SearchSpec can contain entities, concepts, lexical alternative groups,
constraints, verification requirements and answer requirements. Host code
normalizes this structure before use. The exact original user request is kept
separately instead of being replaced by the SearchSpec: the structured spec is
an interpretation aid, while the original request remains authoritative for
requested meaning and answer presentation.

A direct lexical route is available when enabled by the administrator and the
planner classifies the original query as lexically sufficient. Otherwise the
structured semantic route is used.

Concept groups retain a distinction between primary lexical anchors and
recall-oriented expansion terms. Files retrieval is staged: Elasticsearch first
runs the primary groups together with entities and validated constraints. Only
when that authorized result set does not fill the configured retrieval target
does a second, broader query run with the expansion terms. Primary hits retain
their order and expansion hits may only append new authorized documents.

The analysis view exposes both the request partition (information interest,
search specifications, answer requirements) and the sanitized compiled query
body for each retrieval stage. The query body never contains endpoint
credentials; ACL metadata prefilter clauses are added only after the diagnostic
base query has been captured.

Validated structured year and date constraints are compiled into required
lexical clauses by host code. They are exact content/title requirements, not
claims about the semantic role of a date inside a document; semantic relations
such as "invoice date is in 2020" remain subject to evidence verification.

Complete filenames recognized in the original request are matched against the
keyword-analyzed Nextcloud `title` field, preserving punctuation and allowing
both a bare filename and a path ending in that filename. Identifiers occurring
inside document content are different: FullTextSearch exposes `content` as an
analyzed text field without a keyword copy. Identifier constraints are therefore
required lexical phrase constraints, but punctuation-exact matching inside
content cannot be guaranteed without changing the host FullTextSearch mapping.

If planning fails or produces no usable positive lexical anchors, the app falls
back to a broad bounded search using the original question. Deterministically
recognizable hard constraints from the original request, such as explicit years,
dates, identifiers or complete filenames, remain enforceable in direct-lexical
and fallback routes as well.

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

The configured Elasticsearch document limit is a hard `size` bound for each
retrieval stage. When staged retrieval applies and the authorized primary
results do not fill the retrieval target, at most one expansion query runs with
the same bound. No further refill runs after ACL rejection, and there is no
separate overfetch budget. A separate administrator-controlled evidence limit
bounds how many authorized results can be sent to the answer model.

## Evidence boundary

Authorized search snippets are serialized into a server-owned JSON evidence
envelope. Document text remains untrusted model input: evidence cannot redefine
system instructions, citation rules or output format.

The answer layer receives the exact original user request, the normalized
planner context and only the authorized evidence envelope. The original request
is authoritative; planner-provided verification requirements help assess
sufficiency, and answer requirements carry presentation requests such as
ordering, table/list shape or requested detail. The answer layer is instructed
to use only supplied evidence and to cite stable server-generated evidence IDs,
which are mapped to visible source numbers afterwards.

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

## Design scope

SunaQ Find is intentionally a lightweight AI-assisted file-search application,
not a general-purpose RAG platform. It uses the existing FullTextSearch /
Elasticsearch index, one primary bounded retrieval pass and at most one
expansion pass, live Nextcloud ACL enforcement and direct synchronous LLM
evaluation. Evidence is built from bounded snippets
of authorized documents. Vector or graph retrieval, web research, chat archives
and conversation state are deliberately outside this app's scope.
