# SunaQ Find - AI-assisted FullTextSearch

[![CI](https://github.com/Eboracum-de/sunaq-find/actions/workflows/ci.yml/badge.svg)](https://github.com/Eboracum-de/sunaq-find/actions/workflows/ci.yml)
[![License: AGPL-3.0-or-later](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue.svg)](LICENSE)

SunaQ Find is a lightweight native Nextcloud app that gives you AI-assisted
natural-language access to your existing Nextcloud FullTextSearch /
Elasticsearch stack. It deliberately does **not** add a second search stack.

There is no middleware, vector database, worker, queue, or separate application
server. Instead, SunaQ Find analyzes the user's request, converts it into a
deterministic Elasticsearch search plan, and uses the document text already
indexed by Nextcloud. Every candidate is checked against the current user's live
Nextcloud file access before authorized evidence can be sent to the configured
LLM.

Note, that SunaQ Find is not a replacement for a fully featured RAG. SunaQ Find will respond to tasks like "List all documents pertaining to X in descending order" and will list the documents found. But there is no guarantee for completeness, since the there might exist more relevant documents than fit in the retrieval window.

Search requests do not use Nextcloud's AI task-processing pipeline and are not
queued for a background worker or cron job. Once the existing FullTextSearch
index is available, each request is handled synchronously, so SunaQ Find does
not depend on a SunaQ-specific background scheduler for response latency.
Normal Nextcloud FullTextSearch indexing still needs to keep the Elasticsearch
index up to date.

SunaQ Find is a spin-off of the SunaQ project. It is intentionally small,
supports Nextcloud 23–35, and adds little server-side overhead beyond the
existing FullTextSearch stack and the configured LLM endpoint. It has been
tested with local Ollama as well as OpenAI-compatible hosted providers including
OpenAI, Groq and xAI/Grok.

## What it does

- Ask questions about indexed Nextcloud documents in natural language.
- Rewrites the question into a backend-neutral search plan.
- Searches the existing FullTextSearch Elasticsearch index.
- Applies a Nextcloud-user/group metadata prefilter where available.
- Performs a live Nextcloud ACL check for every candidate before document
  content can become answer evidence.
- Produces a concise answer with source links back to Nextcloud.
- Adds **Search with SunaQ Find** to Unified Search.
- Supports OpenAI-compatible chat-completions endpoints.
- Keeps user-interface language and document retrieval language independent.
- Ships English source strings and German translations.

## Slash commands

SunaQ Find supports two lightweight commands in the normal search box:

- `/list [request]` runs the normal query planning, bounded Elasticsearch
  retrieval, deduplication and live Nextcloud ACL checks, but skips AI answer
  synthesis and returns the authorized documents in retrieval order.
- `/help` returns the bundled `docs/HELP.md` help text without running query
  planning or retrieval. The help text follows the current Nextcloud UI
  language where a translation is available.

## Requirements

- Nextcloud 23–35.
- PHP 7.4 or newer with `mbstring`.
- An existing and populated Nextcloud FullTextSearch installation using
  `fulltextsearch_elasticsearch`.
- An OpenAI-compatible chat-completions endpoint.

SunaQ Find does not install or operate Elasticsearch and does not build its own
document index.

### Compatibility notes

The first public release is kept compatible with PHP 7.4 so that older
Nextcloud 23 installations do not need a PHP upgrade just for SunaQ Find.
The application avoids PHP 8-only syntax and helpers in production code while
remaining compatible with current PHP versions.

The declared Nextcloud floor is 23. Versions below 23 are not part of the
initial supported range. CI syntax-checks the production app on PHP 7.4 and
runs the full contract test suite on PHP 8.0 and 8.3. Nextcloud 23 and current
Nextcloud installations are used as integration targets.

## Installation

The Nextcloud app id is `sunaqfind`. The installed directory must
therefore be named `sunaqfind`.

For a source checkout, clone the repository into a directory named
`sunaqfind` below one of Nextcloud's writable app paths:

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/Eboracum-de/sunaq-find.git sunaqfind

cd /path/to/nextcloud
sudo -u www-data php occ app:enable sunaqfind
```

Then open **Administration settings → Additional settings → SunaQ Find**.

A signed App Store package will use the normal Nextcloud app installation flow;
no additional server-side SunaQ service is required.

## Configuration

### Elasticsearch

If Elasticsearch URL and index are left empty, SunaQ Find tries to reuse the
first host and index configured by `fulltextsearch_elasticsearch`.

Manual Elasticsearch settings remain available for installations where
automatic detection is not appropriate.

Use **Test Elasticsearch** after saving the configuration. Connection tests use
the saved values, not unsaved form contents.

If automatic detection finds a local/private Elasticsearch host but the test
still fails, check **Allow configured ES/LLM endpoints on private/local
networks** under **Network policy**. This option is enabled by default on fresh
installs because detected Elasticsearch and local LLM endpoints commonly live
on private addresses.

### LLM

Configure:

- an OpenAI-compatible base URL;
- the model name expected by that endpoint;
- an API key where required.

For a local Ollama installation, for example:

```text
Base URL: http://ollama.example.internal:11434/v1
Model:    qwen3:8b
```

Private or local endpoints are allowed by default on fresh installs and can be
disabled under **Network policy**. Self-signed TLS certificates can also be
enabled for controlled lab environments.

**Important:** the AI connection test uses the **saved** configuration. Enter
the base URL, model and API key, press **Save**, and only then run **Test AI**.

### Retrieval language

**Retrieval language(s)** controls the language used for lexical query expansion.
The default is `de`; multiple comma-separated language tags such as `de,en`
are supported.

This setting is independent from the user's Nextcloud interface language. For
example, an English-speaking user can search a German document collection:
user-facing interpretation and answers remain English while the planner emits
German lexical retrieval terms.

### Retrieval bounds

The administrator can enable or disable the Elasticsearch ACL metadata
prefilter, set the bounded Elasticsearch retrieval horizon (default 15, maximum
100), and separately set the maximum number of authorized documents passed to
the answer model (default 12, maximum 25).

Structured searches can use two bounded lexical stages: a primary search with
the user's strongest lexical anchors and, only when that stage does not fill the
configured retrieval target, a broader expansion search. Expansion results are
appended and never displace primary results. SunaQ Find does not perform
unbounded overfetching or repeated background retrieval loops.

### Display options

The administrator can independently enable:

- analysis mode with SearchSpec, retrieval and aggregate ACL diagnostics;
- source excerpts and file paths;
- the collapsed search-strategy explanation.

Analysis mode is disabled by default and is intended for temporary
administrator diagnostics. It includes candidate/authorized/denied/unresolved
counts so an administrator can verify that the live ACL boundary is being
applied. It does not expose titles, paths or contents of denied or unresolved
documents. Because the setting is global, it should normally remain disabled
outside setup and troubleshooting.

These settings affect presentation only, not the live ACL boundary.

## Security and privacy

Elasticsearch is a candidate source, not an authorization authority.

The normal search path is:

```text
question
  -> query planning
  -> deterministic Elasticsearch query
  -> candidate hits
  -> live Nextcloud ACL check
  -> authorized evidence
  -> LLM answer
```

Document title, path, or text from a denied candidate is not passed to the
answer model or returned to the browser.

The configured LLM receives the user's question plus bounded text excerpts from
authorized search results. Administrators are therefore responsible for choosing
an LLM endpoint whose privacy and data-processing properties are suitable for
their installation.

LLM and Elasticsearch credentials entered in the admin page are stored through
Nextcloud configuration; secret values are encrypted with Nextcloud's crypto
service.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the retrieval and security
boundary and [docs/ADMIN.md](docs/ADMIN.md) for configuration and troubleshooting.
Security issues should be reported as described in [SECURITY.md](SECURITY.md).

## Language handling

SunaQ Find uses English internally for provider-neutral LLM instructions.
The query planner receives the current Nextcloud language as
`interaction_language`; configured `retrieval_languages` remain a separate
constraint for document-search vocabulary. The answer layer receives the exact
original request separately and is instructed to answer in that request's
language.

The main LLM prompts are located in:

- `lib/Service/QueryRewriteService.php`
- `lib/Service/AnswerService.php`

Visible UI strings use Nextcloud localization. English is the source language;
German translations are included in `l10n/de.json` and `l10n/de.js`.

## Design scope and limits

SunaQ Find is intentionally a lightweight AI-assisted search layer for files,
not a full RAG platform. It uses the existing FullTextSearch / Elasticsearch
index, bounded lexical retrieval, live Nextcloud ACL checks and a direct
synchronous LLM call. It deliberately does not introduce vector or graph
retrieval, web research, chat archives, conversation state or a separate
worker/middleware stack.

This makes SunaQ Find well suited to natural-language document search and
questions where a relatively small set of strong lexical candidates can be
identified. Additional concrete qualifiers such as a person, organization,
recipient, identifier or year usually improve precision substantially.

SunaQ Find does **not** guarantee exhaustive semantic enumeration across a large,
broad result set. A request such as "find all invoices from an organization in
2020" can produce many lexically matching documents that mention the
organization, invoice terminology and the year without all of them actually
being qualifying invoices. Fully classifying every candidate in such a result
set would require repeated or batched semantic verification and is outside the
scope of the lightweight synchronous search path.

If query planning fails, SunaQ Find falls back to a bounded broad lexical search
using the original question.

### LLM variability and repeatability

Query planning and answer synthesis are LLM operations and are therefore not
fully deterministic. Repeating the same natural-language request can produce a
different SearchSpec, different lexical concept groups or expansion terms, and
therefore a different Elasticsearch candidate set, ranking, evidence set or
answer. This can occur even when the configured provider accepts deterministic
sampling parameters, because model/provider behavior itself is not guaranteed to
be byte-for-byte reproducible.

The deterministic parts of SunaQ Find start after the SearchSpec has been
normalized: host code owns Elasticsearch compilation, retrieval bounds and the
live Nextcloud ACL check. These boundaries make authorization and query execution
predictable, but they do not make the preceding LLM interpretation deterministic.

For important searches, use concrete names, identifiers, dates or other lexical
anchors where possible. If repeated runs differ materially, enable analysis mode
temporarily and compare the SearchSpec, compiled retrieval stages and authorized
evidence before treating the answer as conclusive. A negative answer means that
the evidence retrieved for that particular interpretation did not reliably answer
the question; it is not proof that the information is absent from the corpus.

## Development and contributions

Run the local checks with:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
php -r '$x = simplexml_load_file("appinfo/info.xml"); if ($x === false) exit(1);'
```

Bug reports, documentation corrections and focused pull requests are welcome.
See [CONTRIBUTING.md](CONTRIBUTING.md) before submitting code or diagnostics.

## License

GNU Affero General Public License v3 or later (AGPL-3.0-or-later).
