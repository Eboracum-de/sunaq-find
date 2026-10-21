# Contributing

Bug reports, reproducible test cases, documentation corrections and focused pull
requests are welcome.

## Data hygiene

Before posting an issue or pull request, remove or redact:

- document contents that are not public;
- names or personal data that are not needed to reproduce the issue;
- API keys, passwords and provider tokens;
- private certificates and keys;
- sensitive internal hostnames and addresses.

Security vulnerabilities must be reported through [SECURITY.md](SECURITY.md),
not through a public issue.

## Development checks

For changes to the application, run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
php -r '$x = simplexml_load_file("appinfo/info.xml"); if ($x === false) exit(1);'
```

Changes affecting compatibility should be tested on the relevant Nextcloud
versions where practical.

## Design invariants

Unless an architectural change is explicitly discussed, contributions should
preserve these rules:

- Elasticsearch proposes candidates; Nextcloud live ACL is authoritative.
- Denied or unresolved document content is not sent to the LLM or browser.
- LLM output never becomes executable Elasticsearch syntax.
- Retrieval remains deterministically bounded by administrator settings.
- SunaQ Find remains a lightweight native app and does not introduce a second
  indexing/RAG stack as an implicit dependency.

## Pull requests

Keep changes focused. A useful pull request states:

- what problem is being solved;
- which Nextcloud versions were tested;
- what tests were run;
- whether configuration or security behavior changed.

Contributions are accepted under the project's AGPL-3.0-or-later license. No
CLA is currently required.
