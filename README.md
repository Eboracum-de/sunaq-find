# SunaQ Find - AI-assisted FullTextSearch

SunaQ Find is a lightweight native Nextcloud app that gives you AI-assisted
natural-language access to your existing Nextcloud FullTextSearch /
Elasticsearch stack. It deliberately does **not** add a second search stack.

There is no middleware, vector database, worker, queue, or separate application
server. Instead, SunaQ Find analyzes the user's request, converts it into a
deterministic Elasticsearch search plan, and uses the document text already
indexed by Nextcloud. Every candidate is checked against the current user's live
Nextcloud file access before authorized evidence can be sent to the configured
LLM.

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

The Nextcloud app id is currently `sunaqfind`. The installed directory must
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
prefilter, set the hard Elasticsearch hit limit (default 15), and separately
set the maximum number of authorized documents passed to the answer model
(default 12). The app does not automatically overfetch or refill candidates
that fail the live ACL check.

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
The current Nextcloud language is passed separately as
`interaction_language`; configured `retrieval_languages` remain a separate
constraint for document-search vocabulary.

The main LLM prompts are located in:

- `lib/Service/QueryRewriteService.php`
- `lib/Service/AnswerService.php`

Visible UI strings use Nextcloud localization. English is the source language;
German translations are included in `l10n/de.json` and `l10n/de.js`.

## Current scope

SunaQ Find is intentionally small. The current release has:

- one active retrieval arm: files / Elasticsearch;
- one retrieval round;
- no vector database;
- no graph or external web research;
- no mail or chat archive;
- no conversation state;
- no background worker;
- synchronous PHP calls to the configured LLM.

If query planning fails, SunaQ Find falls back to a bounded broad lexical search
using the original question.

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
