# Security policy

Steadhand gives AI agents access to WordPress sites, so security reports matter. This policy says how to report a problem and what to expect.

## Reporting a vulnerability

Do not open a public issue, pull request or discussion for a vulnerability.

Report it privately, through GitHub private vulnerability reporting: https://github.com/makr-holdings/steadhand/security/advisories/new

Include as much of the following as you can:

- The Steadhand version (the `Version:` header in `steadhand.php`) and the WordPress and PHP versions.
- The MCP Adapter version, if it is relevant.
- The steps to reproduce the problem, or a proof of concept.
- The impact you see, for example what an agent or a user could do that they should not be able to do.
- Which capability or role the attack needs.

Test on a local or test site that you control. Do not include real credentials, tokens or data from sites you do not own.

## What to expect

- We acknowledge a report within 3 business days.
- For a confirmed report, we release a fix within 7 days.

## Supported versions

While Steadhand is below 1.0, only the latest release receives security fixes. Update to the latest release before you report a problem, and check that it still happens.

## Scope

In scope: the plugin code in this repository. That includes the Steadhand MCP endpoint and its transport gate, the abilities, the shared pipeline, the agent role and capabilities, uninstall, and the build and release scripts.

Out of scope: WordPress core, the MCP Adapter plugin, and third-party plugins. Report problems in those to their own maintainers. If a Steadhand problem depends on one of them, report the Steadhand part to us and mention the other component.
