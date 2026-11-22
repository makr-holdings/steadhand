# Steadhand

Steadhand is being built to let AI agents work on a WordPress site safely. Steadhand is being built so that each agent gets only the permissions it needs, known site-breaking writes are blocked or fixed before they land, and every action is logged and can be undone. See Status for what exists today.

Steadhand is an access and governance layer, not an AI plugin. It never calls an AI model, stores no AI provider keys and adds no chatbot. The AI runs in the MCP client, such as Claude, ChatGPT or Cursor.

## Status

Pre-release. This is milestone M1 (read tools) in progress, in a private repository. The plugin registers five read-only tools. None of them changes site content. `steadhand/get-rendered` runs the site's own blocks and shortcodes to produce HTML. While it renders, Steadhand blocks outbound HTTP requests and embed-cache writes, so embeds appear as plain URLs, and it omits login/logout blocks, avatar blocks and mis-referenced navigation blocks. It also omits every avatar image wherever it is printed, so no Gravatar hash of an email address and no author-archive link appears in the output. Third-party shortcodes may still have side effects of their own:

- `steadhand/get-site-info`: site name, URL, versions, active theme and this agent's capabilities.
- `steadhand/list-posts`: a page of posts of one allowed type (`post` and `page` by default), in the statuses the agent asks for, by any author.
- `steadhand/get-post`: one post with its raw content as stored.
- `steadhand/get-blocks`: the block tree of one post, with an index path for each block.
- `steadhand/get-rendered`: the HTML a post's content produces through the site's content filters, with the render limits above.

The change log, guardrails, undo, the admin screens and every write tool are not built yet. Do not use it on a production site, except internal dogfooding sites set up as described in [Dev builds on internal sites](#dev-builds-on-internal-sites).

The plan is in [docs/SPEC.md](docs/SPEC.md). Release notes are in [CHANGELOG.md](CHANGELOG.md).

## Requirements

- WordPress 6.9 or later
- PHP 8.1 or later
- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin (WordPress.org slug `mcp-adapter`). Tested with 0.7.2.

Steadhand declares the MCP Adapter with `Requires Plugins`. If the Abilities API or the MCP Adapter is missing, Steadhand shows an admin notice and does nothing else.

## How access works today

- An agent is a WordPress user whose only role is `steadhand_agent`. Activating Steadhand creates the role. A user that also holds another role, such as an administrator given the agent role, is not an agent and cannot connect.
- The agent authenticates with a WordPress Application Password, sent with HTTP Basic auth.
- The MCP endpoint is `/wp-json/steadhand/mcp` on the site, for example `https://example.com/wp-json/steadhand/mcp`.
- To reach the endpoint, a user must be a dedicated agent and hold the `steadhand_connect` capability. Administrators do not get that capability automatically.
- Agent credentials work only at the Steadhand endpoint. The lockdown (SPEC §4.1, §14.1) enforces this:
  1. Only a user whose sole role is `steadhand_agent` can connect. A user that also holds the agent role is refused at the endpoint.
  2. A dedicated agent's Application Password works only at the Steadhand endpoint. Anywhere else the request is treated as not logged in: core REST answers 401 (`rest_not_logged_in`), `/wp-json/batch/v1` runs as anonymous, and the MCP Adapter's default server (`/wp-json/mcp/mcp-adapter-default-server`) refuses it. A user that holds the agent role and another role gets no use of its Application Passwords anywhere, the Steadhand endpoint included. Its cookie sign-in is unchanged.
  3. Dedicated agents cannot sign in with a password (`wp-login.php`, `wp_signon()`, XML-RPC) and cannot use wp-admin (403). A user that holds the agent role and another role cannot sign in over XML-RPC with its Application Password either, though its account password and cookie sign-in still work.
  4. The lockdown is registered even when the MCP Adapter or the Abilities API is missing. It depends only on WordPress core.
  5. Limits: the lockdown runs only while Steadhand is active and loadable. If Steadhand is deactivated, or cannot load (PHP older than 8.1, or a source checkout without its autoloader), agent users and their Application Passwords still exist and work as ordinary low-privilege accounts on core REST and `wp-login.php` until they are revoked. Uninstalling revokes them. To take a site out of service safely, revoke the agents' Application Passwords first, or uninstall rather than deactivate.
  6. Do not give the agent role to a person's account. That account's Application Passwords stop working everywhere (point 2).

Steadhand has eight capabilities. The table shows the defaults for a new agent. Only `steadhand_connect` and `steadhand_read` have any effect today, because M1 has only read tools. The other capabilities are stored in the role and checked by nothing yet.

| Capability | Default for a new agent | Effect today |
|---|---|---|
| `steadhand_connect` | on | Lets the agent reach the endpoint |
| `steadhand_read` | on | Lets the agent call the five read tools |
| `steadhand_write_drafts` | on | None yet (no write tools in M1) |
| `steadhand_publish` | off | None yet |
| `steadhand_media` | off | None yet |
| `steadhand_menus` | off | None yet |
| `steadhand_trash` | off | None yet |
| `steadhand_settings` | off | None yet |

There is no admin screen for agents or capabilities yet. That is planned for milestone M3.

## Local development

Prerequisites:

- Docker. `wp-env` runs WordPress in containers.
- Node.js (LTS) and npm.
- Composer.
- PHP 8.1 or later on the host, for Composer. The tests run inside `wp-env`'s own PHP.

Set up:

```
git clone <repository-url> steadhand
cd steadhand
composer install
npm ci
composer dev-updater:install
npx wp-env start
```

The checkout folder must be named `steadhand`. `wp-env` mounts the plugin under its folder name, and `composer test` expects `wp-content/plugins/steadhand`.

`composer dev-updater:install` installs the update library used by the dev-only updater in `includes/Dev/`. The tests need it.

The development site is at http://localhost:8888. `wp-env` uses port 8888 unless it is busy. `npx wp-env start` prints the address, and `npx wp-env run cli wp option get siteurl` shows the address the site uses.

The default `wp-env` admin login is:

- Username: `admin`
- Password: `password`

This login is for the local development site only. It is set by `wp-env`, not by Steadhand.

Check the code:

```
composer phpcs
composer test
```

`composer test` runs PHPUnit inside `wp-env`'s tests environment (the `tests-cli` container, with its own database). It does not touch the development site.

Stop the environment:

```
npx wp-env stop
```

## Create a local agent and connect Claude

Create an agent user and an Application Password on the local development site:

```
bin/create-agent.sh [username] [label]
```

The defaults are `steadhand-agent` for the username and `Local agent` for the label. The label becomes the name of the Application Password. Steadhand records it as the agent label. Until the change log lands in M2, the log is a placeholder that keeps nothing. The script:

1. Activates Steadhand if it is not active.
2. Creates the user with the `steadhand_agent` role. If a user with that name already exists and holds exactly that role, the script reuses it. It refuses any other user.
3. Creates an Application Password with the label as its name.
4. Prints the MCP endpoint, the username, the Application Password and the commands to connect a client.

The password is printed once. The script does not save it.

> **Warning:** the connect command below stores the credential in plaintext, in your shell history and in the MCP client's config file. Base64 is an encoding, not encryption. Anyone who can read either file has the password. Use it only with this local development site. When you are done, remove the MCP server with `claude mcp remove steadhand`, and revoke the Application Password on the agent user's profile page in wp-admin, in the Application Passwords section.

To connect Claude Code, run the command the script prints. Its form is:

```
claude mcp add --transport http steadhand <endpoint> --header "Authorization: Basic <base64 of username:application-password>"
```

Replace the placeholders with the values the script printed. Do not add `--scope project`. That scope writes `.mcp.json` into the working tree, where it can be committed. The script refuses to run against any address other than a local `http://localhost` or `http://127.0.0.1` site.

Any other MCP client needs the same two things:

- The endpoint URL, using the Streamable HTTP transport.
- The header `Authorization: Basic base64(username:application-password)` on every request.

To check the connection, ask the client to call the tool `steadhand-get-site-info`. The tool takes no arguments. It returns these fields:

- `name`: the site title as plain text, with HTML entities decoded.
- `url`: the site's home URL.
- `wordpress_version` and `php_version`.
- `theme`: the active theme's `name` and `version`.
- `steadhand_version`.
- `capabilities`: each of the eight Steadhand capabilities set to `true` or `false` for this agent.

The other four read tools are listed below. Each takes the arguments shown and refuses any other.

- `steadhand-list-posts` takes `post_type` (default `post`; must be an allowed type), `status` (one or more of `publish`, `future`, `draft`, `pending`, `private`; default all five), `search` (up to 200 characters), `after` and `before` (ISO 8601 dates), `orderby` (`date`, `modified` or `title`), `order`, `page` (1 to 10000) and `per_page` (1 to 50). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Trashed and auto-draft posts are never returned.
- `steadhand-get-post` takes `id`. It returns the post's fields and its raw `content`, exactly as stored.
- `steadhand-get-blocks` takes `id`. It returns the block tree. Each block has a `path` such as `0` or `1.0`, a `name`, `attrs`, `inner_html` and `inner_blocks`.
- `steadhand-get-rendered` takes `id`. It returns the post's rendered `html` as a fragment, not a full page.

Content over 256 KB is cut, and the response then says `truncated: true` and gives the full size.

Refused and missing posts get the same message, so the tools cannot be used to find out which IDs exist. An administrator account is refused at the endpoint, with or without `steadhand_connect`, because only a dedicated agent connects.

## Builds and releases

Build a release zip:

```
bin/build.sh <dev|wporg> [version]
```

The optional version must match the `Version:` header in `steadhand.php`, with or without a leading `v`.

Each build writes one zip into `build/`:

- `build/steadhand-dev-X.Y.Z.zip`: includes the dev-only updater in `includes/Dev/`. For internal sites only.
- `build/steadhand-X.Y.Z.zip`: the WordPress.org build. It has no `includes/Dev/` folder and no update-checker code.

Both builds leave out the files listed in `.distignore`. The version comes from the `Version:` header in `steadhand.php`, which must match `STEADHAND_VERSION`. A build that fails at any step leaves no zip behind.

The build stages the files git tracks, plus untracked files that git does not ignore. A new file counts before its first commit, so commit or remove stray files before you build. Ignored files such as `vendor/` and `build/` are not staged from your working tree; the build creates the autoloader itself.

The build needs these tools on the `PATH`: `git`, `rsync`, `zip`, `unzip`, `composer` and `php`. The gate needs `find`, `grep`, `head`, `od`, `tr`, `iconv`, `wc` and `unzip`. The gate self-test (`bin/test-wporg-gate.sh`) also needs `zip`, `python3` and `iconv`.

`bin/check-wporg-build.sh` is the release gate. It fails if a WordPress.org build contains update-checker code, dev-only folders, dev and test tooling, or anything that cannot be read as plain source. CI runs it on every build. `bin/test-wporg-gate.sh` plants each known way of smuggling the updater into the WordPress.org build and checks that the gate catches it. CI runs that too.

To release, tag a commit on `main` with `vX.Y.Z`, for example `v0.1.0`. The `release.yml` workflow checks that the tagged commit is on `main`, builds and gates both zips, and creates a GitHub Release with both zips attached. The tag must match the `Version:` header and `STEADHAND_VERSION` in `steadhand.php`. Versions follow [Semantic Versioning](https://semver.org/). There is no WordPress.org deploy yet. That lands in milestone M4.

## Dev builds on internal sites

The dev zip includes an updater. It checks the latest GitHub release of this repository and installs the dev zip from that release's assets. Without the token constant, the update library is not loaded and nothing is hooked or printed.

Set it up on each internal site:

1. Create a fine-grained personal access token on GitHub:
   - Resource owner: the `makr-holdings` organization.
   - Repository access: "Only select repositories", then `makr-holdings/steadhand`.
   - Repository permissions: Contents, Read-only. Metadata, Read-only, is added automatically.
   - Expiration: 90 days or less.

   The organization may need fine-grained tokens enabled first. An organization owner can enable them under the organization's Settings, then Personal access tokens.
2. Add the token to `wp-config.php`, above the line that says to stop editing:

   ```php
   define( 'STEADHAND_DEV_GITHUB_TOKEN', '<your-token-here>' );
   ```

3. Rotate the token before it expires. Create a new one, replace the constant's value, and revoke the old token on GitHub.

Handling the token:

- Edit `wp-config.php` directly. Do not use `wp config set`: it puts the token in your shell history and in the process list.
- Never commit `wp-config.php`, and never paste the token into a ticket or chat.
- Restrict the file's permissions so that only the account that runs the web server, and the administrators who need it, can read it.
- The token belongs to one person's GitHub account. Updates stop if that account loses access to the repository. Write down who owns the token.
- The organization may require an owner to approve the fine-grained token before it works.

How the updater handles the token and the download:

- It reads the token only from that constant. It never stores the token in the database, writes it to a log or displays it.
- It installs only the `steadhand-dev-X.Y.Z.zip` release asset. It never installs GitHub's source archive, which has no `vendor/` folder. A release without the asset is skipped.

Know these risks before you use a token:

- Every plugin and theme on the site, and anyone who can read `wp-config.php` or its backups, can read the token. The update library passes its request options, including the `Authorization` header, through its filter `puc_request_info_options-steadhand` and through WordPress's HTTP hooks, so other code on the site can see the token on its requests.
- A leaked token gives read access to this private repository's source and releases until it is revoked. Use dev builds only on sites where you trust every installed plugin.
- Anyone with write access to this repository can publish a release, by pushing a tag or uploading an asset by hand, and so run code on every site that uses the dev build. The zip is not signed. Keep write access limited.

Removing a dev build does not remove the update library's data. These are left behind:

- The option `external_updates-steadhand`. It is a site option.
- The cron event `puc_cron_check_updates-steadhand`.
- The transient `puc_manual_check_errors-steadhand`. It is a site transient. It is set only after a failed manual update check, and it expires after 60 seconds.

Remove them with WP-CLI:

```
wp option delete external_updates-steadhand
wp cron event delete puc_cron_check_updates-steadhand
wp transient delete puc_manual_check_errors-steadhand --network
```

On a multisite network, the option is a network option, so use `wp site option delete external_updates-steadhand` instead of `wp option delete`.

On the local development site, the same commands run as `npx wp-env run cli wp ...`.

## Uninstall

Deleting Steadhand runs `uninstall.php`, which removes the agent role and its access:

- Removes the `steadhand_agent` role.
- Removes every Steadhand capability from every user.
- Deletes the Application Passwords of users whose only role was `steadhand_agent`. Those passwords exist only for agent access. Such a user is left with no role.

Users are never deleted. A user who also holds another role keeps their Application Passwords and loses only the agent role and the Steadhand capabilities. M0 creates no database tables and no options of its own.

Deactivating Steadhand, as opposed to uninstalling it, keeps the role, its capabilities and the Application Passwords. Steadhand registers no deactivation hook.

`uninstall.php` loads the Composer autoloader before it does anything. In a source checkout without `vendor/`, uninstall does nothing and the role and its grants remain. Release zips always include `vendor/`.

## Contributing and security

- Work on a branch from `main` and open a pull request. The maintainer reviews and merges every pull request. Do not push to `main`.
- Coding agents and contributors should read [CLAUDE.md](CLAUDE.md) and [docs/SPEC.md](docs/SPEC.md) before they start. Extension points are listed in [docs/HOOKS.md](docs/HOOKS.md).
- To report a security problem, follow [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability.

Steadhand is licensed under the GPL-2.0-or-later. See [LICENSE](LICENSE).
