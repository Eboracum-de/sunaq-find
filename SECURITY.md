# Security Policy

SunaQ Find handles private document search results and can send authorized
evidence to a configured LLM endpoint. Security reports are therefore handled
privately.

## Reporting a vulnerability

Please do **not** open a public GitHub issue for a suspected vulnerability.

Report security issues to:

`rag@eboracum.de`

Use the subject prefix **[SunaQ Find Security]** where practical.

A useful report includes:

- the SunaQ Find version or commit;
- the Nextcloud and PHP versions;
- a concise description of the impact;
- reproducible steps using synthetic or redacted data;
- sanitized logs or analysis output where relevant.

Do not send API keys, passwords, private keys, production document contents, or
other confidential customer/user data.

Especially relevant issues include unauthorized document disclosure, ACL bypass,
credential exposure, unsafe request routing, or a way for document content to
override the application's security boundary.

Please allow reasonable time for investigation and a coordinated fix before
public disclosure.
