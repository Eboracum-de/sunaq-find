# SunaQ Find administration

## Prerequisites

SunaQ Find expects an existing Nextcloud FullTextSearch setup with
`fulltextsearch_elasticsearch` and a populated Elasticsearch index. It does not
create or synchronize that index itself.

An OpenAI-compatible chat-completions endpoint is also required.

## Elasticsearch

By default, leave the Elasticsearch URL and index empty. SunaQ Find then tries
to read the existing `fulltextsearch_elasticsearch` host and index.

Manual URL, index, username and password values override or complement automatic
detection.

After saving, use **Test Elasticsearch**. Connection tests always use the saved
configuration, not unsaved form values.

If an automatically detected Elasticsearch host is local/private and the test
fails, verify **Allow configured ES/LLM endpoints on private/local networks**
under **Network policy**. The option is enabled by default on a fresh SunaQ Find
installation.

## LLM

Configure an OpenAI-compatible base URL, model and optional API key.

Examples of endpoint shapes:

```text
https://api.example.com/v1
http://ollama.internal:11434/v1
```

SunaQ Find appends or preserves the normal `/chat/completions` endpoint as
appropriate.

The integration intentionally stays provider-neutral. Provider-specific
features are not required for normal operation.

**Test AI uses the saved configuration.** Enter or change the base URL, model
and API key, press **Save**, and only then run **Test AI**.

## Retrieval languages

The default retrieval language is:

```text
de
```

Multiple languages can be configured as comma-separated tags:

```text
de,en
```

Retrieval language controls lexical expansion for the indexed corpus. It is not
the same setting as the user's Nextcloud UI language.

## Retrieval controls

**Use ACL metadata prefilter** is enabled by default. When enabled, SunaQ Find
adds the current Nextcloud user/group identity metadata to the Elasticsearch
query before the live ACL check. This is an optimization only: the live
Nextcloud ACL check remains authoritative and is always applied.

**Maximum Elasticsearch hits** is a hard Elasticsearch result limit. The
default is 15 and the supported range is 1–20. SunaQ Find does not increase
this value automatically, refill rejected candidates, or use a separate
overfetch budget. If the prefilter is disabled and all returned hits fail the
live ACL check, the authorized result is empty.

**Maximum documents for AI evaluation** controls how many live-ACL-authorized
search results can enter the evidence envelope sent to the configured LLM. The
default is 12, the supported range is 1–20, and the value cannot exceed the
Elasticsearch hit limit. The difference between both limits can therefore be
used by the administrator as a deliberate ACL/deduplication buffer.

## Network policy

Nextcloud's HTTP client normally protects against local/private destinations.
SunaQ Find enables **Allow configured ES/LLM endpoints on private/local
networks** by default on fresh installs because automatically detected
Elasticsearch hosts and local LLMs commonly use private addresses. Disable it if
your installation does not need local/private endpoints.

**Allow self-signed TLS certificates** is intended for controlled lab or private
CA environments. Prefer a normally trusted certificate where practical.

## Display settings

**Analysis mode** is an administrator diagnostic aid and is disabled by
default. Enable it temporarily while setting up or troubleshooting a SunaQ Find
installation, then switch it off again for normal operation.

Besides the SearchSpec and retrieval plan, analysis mode reports aggregate ACL
diagnostics:

- `candidate_count`: Elasticsearch candidates considered by the retrieval;
- `authorized_count`: candidates that passed the live Nextcloud ACL check;
- `denied_count`: resolvable files that exist but are not readable by the
  current user;
- `unresolved_count`: candidates whose Nextcloud file ID cannot currently be
  resolved, for example stale Elasticsearch entries;
- `acl_prefilter_status`: `applied`, `disabled`, or `fallback`;
- `acl_prefilter_applied` and `acl_prefilter_fallback`: compatibility flags
  showing whether the Elasticsearch-side identity prefilter was used or a
  technical fallback was required.

These counters are useful for verifying that the installation's ACL boundary is
actually being exercised. SunaQ Find deliberately exposes only aggregate counts
for rejected candidates: titles, paths and document contents from denied or
unresolved candidates are not returned to the browser or sent to the answer
model.

Because analysis mode is a global display setting, users of the app can see
these diagnostics while it is enabled. It should therefore normally remain off.

**Show document excerpts** controls whether source snippets and paths are
returned to the ordinary source UI.

**Show search strategy** displays the lexical terms used and the evidence
criteria in a collapsed disclosure.

## Language

The UI follows the current Nextcloud language. English source strings and German
translations are included.

LLM prompts are internally written in English. The current Nextcloud language is
sent to the model as `interaction_language`, while configured
`retrieval_languages` separately control lexical search vocabulary.

## Troubleshooting

If the admin connection test works but search fails, check:

1. FullTextSearch has actually indexed the target files.
2. The current user can open the files in Nextcloud.
3. The Elasticsearch index configured by SunaQ Find matches the
   FullTextSearch index.
4. The LLM account has sufficient quota for both query planning and answer
   generation.
5. The LLM supports sufficiently compatible chat-completions and JSON output.

HTTP 401/403/404/429 and temporary provider failures are converted into bounded,
provider-neutral diagnostics. Analysis mode can expose the sanitized reason
without returning raw provider responses or credentials.

## ACL behavior

When enabled, Elasticsearch candidates are prefiltered using current Nextcloud
identity metadata where possible. Every returned candidate still passes a live
filesystem ACL check. Only content from candidates that pass that check can
become LLM evidence.

The configured Elasticsearch hit limit is always hard. Disabling the prefilter,
or technically falling back because prefilter metadata is unavailable, does not
increase the Elasticsearch window. An empty authorized result is a valid search
result and does not trigger another search or a global unfiltered document scan.
