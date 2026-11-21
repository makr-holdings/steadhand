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
