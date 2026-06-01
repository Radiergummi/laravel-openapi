# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities **privately** — do not open a public issue.

Use GitHub's [private vulnerability reporting](https://github.com/Radiergummi/laravel-openapi/security/advisories/new)
("Report a vulnerability" under the repository's **Security** tab), or email
**moritz@mazetti.me**.

Include enough detail to reproduce the issue (affected version, a minimal
reproduction, and the impact you observed). You can expect an initial response
within a few days. Once a fix is ready it will be released and the advisory
published with credit, unless you prefer to remain anonymous.

## Scope

This package generates an OpenAPI document and lints it; it runs at build time
and does not handle production request traffic. Reports are most relevant when
generation can be made to read or write outside the project, execute arbitrary
code from untrusted input, or leak sensitive data into the generated document.
