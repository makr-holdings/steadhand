# Changelog

All notable changes to Steadhand are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html), starting at `0.1.0`.

## [Unreleased]

### Added
- Plugin bootstrap with dependency checks. If the Abilities API or the MCP Adapter is missing, Steadhand shows an admin notice and does nothing else; it never fatals.
- `steadhand_agent` role and Steadhand capabilities, created on activation. Uninstall removes the role, strips every Steadhand capability from every user, and revokes the application passwords of users whose only role was the agent role. Users are never deleted, and users who also hold another role keep their application passwords.
- `steadhand` ability category and the Steadhand MCP server at `/wp-json/steadhand/mcp`, gated by `steadhand_connect`.
- `steadhand/get-site-info` ability, running through the shared pipeline (validate, authorize, execute, log). The log step is a no-op until M2.
- Admin notice instead of a fatal error on PHP older than 8.1 or when the Composer autoloader is missing.
- Refused calls will be logged as `blocked` once the change log lands in M2; until then the log step is a no-op.
- PHPCS and PHPUnit integration tests.
- Continuous integration: PHPCS, PHPUnit integration tests on PHP 8.1 and 8.4 against WordPress 6.9 and latest, Plugin Check on the WordPress.org build, and the release-zip build.
- Two release zips: a dev zip (with the dev-only updater) and a WordPress.org zip. A `vX.Y.Z` tag publishes both as a GitHub release.
- WordPress.org build check that fails if the build contains any update-checker code or dev/test tooling. CI runs it on every build.
- Dev-only updater for internal builds. It serves updates from this repository's release assets only (never the source archive) and does nothing unless a token is set as a constant in the site's configuration. It is not part of the WordPress.org build.
- Local development script that creates an agent user and an Application Password on the wp-env development site. Local development only.
- Documentation: disclosure policy (`SECURITY.md`); developer documentation in the repository.

### Security
- The agent lockdown is registered even when the MCP Adapter or the Abilities API is missing, and a later `rest_pre_dispatch` filter can no longer lift it.
- A user that holds the agent role and another role can no longer use its Application Passwords anywhere. Its cookie sign-in is unchanged.
- Agent credentials are refused everywhere except the Steadhand MCP endpoint: core REST routes, the batch endpoint, the abilities API, the MCP Adapter's default server, XML-RPC, `wp-login.php` and wp-admin. An agent's Application Password no longer works outside Steadhand, and an agent cannot sign in with its account password.
- Only a dedicated agent can connect: a user whose sole role is `steadhand_agent`. A user who also holds another role, such as an administrator given the agent role, is refused at the endpoint and in the pipeline, even if it holds the Steadhand capabilities.
