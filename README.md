# Steadhand

Steadhand is being built to let AI agents work on a WordPress site safely. Steadhand is being built so that each agent gets only the permissions it needs, known site-breaking writes are blocked or fixed before they land, and every action is logged and can be undone. See Status for what exists today.

Steadhand is an access and governance layer, not an AI plugin. It never calls an AI model, stores no AI provider keys and adds no chatbot. The AI runs in the MCP client, such as Claude, ChatGPT or Cursor.

## Status

Pre-release, in a private repository. Milestone M1 (read-only tools) is complete (version 0.1.0). Milestone M2 (safe writes) is complete: version 0.2.0 is tagged and installed on rickearley.me. All six write tools have been called against that site and behaved as specified. M2 has the write path, the guardrail engine with five built-in rules, the post write tools, the term write tool and the media write tools: `steadhand/upload-media`, which adds an image to the media library from a base64 payload, and `steadhand/set-featured-image`, which sets or removes a post's featured image. The plugin registers nineteen tools. Thirteen are read-only. Six are write tools: `steadhand/create-post`, `steadhand/update-post` and `steadhand/edit-blocks`, which can change post content, `steadhand/set-post-terms`, which can change the terms of a post, and `steadhand/upload-media` and `steadhand/set-featured-image`, which can change the media library and a post's featured image. Steadhand can now change site content for agents that hold the write capabilities. A new agent holds `steadhand_write_drafts` by default, so it can create drafts and edit draft, pending and auto-draft posts of any author. Publishing needs `steadhand_publish`, which is off by default. Nothing else is writable yet: no image fetched from a URL, no menus, trash, settings or post meta. `steadhand/get-rendered` runs the site's own blocks and shortcodes to produce HTML. While it renders, Steadhand blocks outbound HTTP requests and embed-cache writes, so embeds appear as plain URLs, and it omits login/logout blocks, avatar blocks and mis-referenced navigation blocks. It renders a `[gallery]` or `[playlist]` shortcode as nothing when it would show media or children that `get-media` or `get-post` refuses. It also omits every avatar image wherever it is printed, so no Gravatar hash of an email address and no author-archive link appears in the output. Third-party shortcodes may still have side effects of their own:

- `steadhand/get-site-info`: site name, URL, versions, active theme and this agent's capabilities.
- `steadhand/list-posts`: a page of posts of one allowed type (`post` and `page` by default), in the statuses the agent asks for, by any author.
- `steadhand/get-post`: one post with its raw content as stored.
- `steadhand/get-blocks`: the block tree of one post, with an index path for each block.
- `steadhand/get-rendered`: the HTML a post's content produces through the site's content filters, with the render limits above.
- `steadhand/list-terms`: a page of the terms of one public taxonomy attached to an allowed post type (categories, tags and post formats by default), including empty terms, with their stored counts.
- `steadhand/get-post-terms`: the allowed terms assigned to one post, grouped by taxonomy, at most 500 per taxonomy.
- `steadhand/list-menus`: classic menus and block navigation menus, with stored item counts and theme locations.
- `steadhand/get-menu`: one classic menu as a flat list of up to 500 items in menu order, or one block navigation post with its content and block tree.
- `steadhand/list-media`: a page of media items the media rule allows, newest first, with public URLs, alt text, captions, dimensions where stored, and the parent post ID. Trashed media is never listed.
- `steadhand/get-media`: one media item, with its description as plain text and its generated image sizes. Server file paths and image metadata (EXIF, IPTC, GPS) are never returned.
- `steadhand/get-diagnostics`: a read-only health report: environment, theme, active plugins, recent PHP engine errors from WordPress's own debug log, overdue cron events, the Site Health counts already stored, and Steadhand's self-check. See [What get-diagnostics returns and what it never returns](#what-get-diagnostics-returns-and-what-it-never-returns).
- `steadhand/get-change-log`: this agent's own change-log entries, newest first. It never returns another agent's entries or any stored input. See [Change log](#change-log).
- `steadhand/create-post`: creates one post or page as the calling agent. It is saved as a draft unless the agent also holds `steadhand_publish`. A write tool.
- `steadhand/update-post`: changes the fields you send on one existing post or page. It needs `steadhand_read`, and `steadhand_write_drafts` or `steadhand_publish`. Before it changes a title, content or excerpt, it saves a WordPress revision when the site keeps two or more revisions, or unlimited ones. It refuses, changing nothing, a post whose page template the active theme does not have (`steadhand_refused_invalid_template`; an administrator must set a valid template), A field you do not send is kept as it is stored, even when a filter on this site would change it on save. A scheduled post cannot be published early with it. A private post made from a protected one loses its password, and the result says so. If WordPress fails after it has changed the post, or a field you did not send changes after the save, the error is `steadhand_update_partial`, and the post should be checked. A write tool.
- `steadhand/edit-blocks`: changes some blocks of one post or page, by the paths `get-blocks` returns, without resending the whole content. It uses the permissions, revision and error rules of `update-post`. Blocks that no operation names stay byte for byte, or the call is refused. A block whose name does not match is refused. Blocks it does not name are stored as they are and are not filtered. The markup it sends must be complete blocks, and is filtered by WordPress as for a user without unfiltered HTML; a change to it is reported with `content_modified_on_save` and a warning. A stored post whose HTML is left open before the edit (for example an unclosed script) is refused, and so is an edit that would change blocks it does not name. The guardrails check the exact content that is written: a result that differs between the check and the write is refused, with nothing written. WordPress cuts the post's display at a `wp:more` delimiter, so an edit that would let a stored or sent void `wp:more` delimiter reach past its place into other bytes is refused too. The check covers the whole result, not only the sent markup. A post over the content limits cannot be edited with it. A write tool.

The change log records every write, every refused call and every failed call, with no admin screen yet (see [Change log](#change-log)). The guardrail engine has five built-in rules (see [Guardrails](#guardrails)). Undo, the admin screens, the guardrail presets, and every write tool except the four above are not built yet. Do not use it on a production site, except internal dogfooding sites set up as described in [Dev builds on internal sites](#dev-builds-on-internal-sites).

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

Steadhand has eight capabilities. The table shows the defaults for a new agent. Four have an effect today: `steadhand_connect`, `steadhand_read`, `steadhand_write_drafts` and `steadhand_publish`. The other four are stored in the role and checked by nothing yet.

| Capability | Default for a new agent | Effect today |
|---|---|---|
| `steadhand_connect` | on | Lets the agent reach the endpoint |
| `steadhand_read` | on | Lets the agent call the thirteen read tools. `update-post`, `edit-blocks` and `set-post-terms` also need it, to read the post they change. `upload-media` and `set-featured-image` need it too, to read back what they stored |
| `steadhand_write_drafts` | on | `create-post` saves drafts. `update-post` and `edit-blocks` edit draft, pending and auto-draft posts of any author, and `update-post` can set a draft to pending. `set-post-terms` sets the terms of those posts. Publishing a draft also needs `steadhand_publish` |
| `steadhand_publish` | off | `update-post` and `edit-blocks` edit published, scheduled and private posts, and `update-post` moves them back to draft. `set-post-terms` sets the terms of those posts. `create-post` and `update-post` can set a live status (`publish`, `future` or `private`). `create-post` can set `pending` only with this capability too |
| `steadhand_media` | off | `upload-media` adds an image to the media library, and `set-featured-image` sets or removes a post's featured image. Both also need `steadhand_read`. An uploaded file is public at once, at its uploads URL and on its attachment page where the site has them, even without `steadhand_publish`. Grant this only to an agent you trust to publish images that way |
| `steadhand_menus` | off | None yet |
| `steadhand_trash` | off | None yet |
| `steadhand_settings` | off | None yet |

There is no admin screen for agents or capabilities yet. That is planned for milestone M3.

## Change log

Steadhand writes its change log to one table, `{prefix}steadhand_log` (`wp_steadhand_log` with the default prefix). Activation creates it. It is also created on the first request after an update, if the table's recorded version is missing or different. If that create fails, Steadhand retries no more than once an hour.

**What is recorded.** Every refused call and every failed call, to every Steadhand tool, with a result of `blocked` or `error`. Every successful write, from `create-post`, `update-post`, `edit-blocks`, `set-post-terms`, `upload-media` and `set-featured-image`, with a result of `ok`, or `fixed` when a guardrail rewrote its input. The image payload of `upload-media` is stored only as the digest of its decoded bytes, never as text. Each entry has the time (UTC), the agent user, the agent label (the name of the Application Password the agent used), the tool, the post or media ID the call named (even when the call was refused), the message the agent received, and the call's input. The input is redacted on a best-effort basis, keys as well as values, and stored up to 16 KB. A larger input is stored as a summary: its size and its top-level key names. Input over 256 KB is not scanned at all. Post content is stored as a digest instead of text: its byte count and SHA-256, written as eight groups of eight hex digits separated by colons. An excerpt over 500 bytes is stored the same way, and so is the markup sent to `edit-blocks`. The revisions hold the text.

**What is not recorded.** Successful calls to read-only tools that need `steadhand_read`, unless a site turns them on with the `steadhand_log_read_calls` filter (see [docs/HOOKS.md](docs/HOOKS.md)). Calls that core rejects on their input before Steadhand's permission check runs, such as input that fails the schema check. That is a known gap.

**Writes.** A write is recorded in two steps. Its row is stored before the change, with the message "The call started, but no outcome was recorded." until the outcome is written to it, so a crash leaves a row that says so. If that first row cannot be stored, the write does not run and the caller gets `steadhand_log_failed`. If the change is made and its outcome cannot be recorded, the caller gets `steadhand_log_incomplete`, which says the change was made. Before `update-post` changes a title, content or excerpt (`edit-blocks` changes content through it), Steadhand saves a WordPress revision of the post, when revisions are available; `warnings` says when none could be saved. `snapshot_id` is the ID of the newest revision that matches the post's title, content and excerpt exactly (autosaves are never used). `undoable` is 1 only when that revision was found and the call changed only those three fields. Steadhand does not claim undo for status, slug or date, and a created post has no earlier state to restore. Undo is not built yet (milestone M3).

**Limits.** At most 60 refused calls per agent are stored in any rolling minute. Then one marker row is stored, and nothing more until the minute has passed. Errors are never dropped. Entries are kept 30 days. Old entries are pruned when a new entry is written, at most once a day, in batches of 1,000 rows, up to 20 batches per pass. If old entries remain after that, the next write continues the pruning. Nothing runs on a schedule, so old entries remain while nothing is written. The period can be changed with the `steadhand_log_retention_days` filter.

**Who can see it.** An agent can read only its own entries, with `steadhand-get-change-log`. There is no admin screen yet; it comes in milestone M3. Until then, an administrator can read the table directly, for example:

```
wp db query "SELECT id, created_at, agent_label, ability, result, message FROM wp_steadhand_log ORDER BY id DESC LIMIT 20"
```

Use the table prefix from `wp-config.php`. The table holds every agent's entries and the stored input, so treat it as sensitive. On the local development site, run it with `npx wp-env run cli wp db query "..."`.

## Guardrails

Every write goes through the guardrail engine before it runs. Five built-in rules are always on. Each one refuses a call with a message. None of them rewrites a call or warns yet.

- **Content size and nesting** (`steadhand/content-limits`): refuses content over 256 KB, more than 2,000 blocks (nested blocks count), nesting deeper than 20 levels, or content that is not valid UTF-8. Content exactly at a limit is allowed.
- **Block markup** (`steadhand/block-markup`): refuses block comment delimiters that WordPress would not read as blocks (malformed, unbalanced, or closed out of order), and attributes that are not valid JSON. **What it does not check:** it does not run the block editor's own check that a block's saved HTML matches what its JavaScript would save, so a block can pass here and still show "This block contains unexpected or invalid content" in the editor. It does not check that a block is registered on the site, or that its attributes match its schema. Markup it cannot read is refused. Delimiters are matched case-sensitively and need whitespace after `<!--`, as WordPress reads them, so text such as `<!-- WP:Engine cache note -->` is allowed. **Known false positive:** text that looks like a malformed delimiter, such as `<!-- wp:x` with no closing comment, is refused when it sits inside a `core/html` block or inside an ordinary HTML comment that starts with `<!-- wp:`.
- **Rate limit** (`steadhand/rate-limit`): 30 writes per agent in any rolling minute, counted from the change log. Refused calls do not count. The count is read before the row is reserved, so writes that arrive at the same moment can pass together: the limit is best effort under concurrent requests. A site can set the limit from 1 to 600 with the `steadhand_rate_limit_per_minute` filter (see [docs/HOOKS.md](docs/HOOKS.md)). It cannot be turned off.
- **Daily upload cap** (`steadhand/daily-upload-limit`): 100 `upload-media` calls per agent in any rolling 24 hours, counted from the change log. A refused upload is logged as blocked, with the rate limit's error (422, `steadhand_guardrail_blocked`). If the count cannot be read, the upload is refused. The limit is fixed in code, not a filter. Both upload limits are best effort under concurrent requests: the count is read before the row is reserved, so uploads that arrive at the same moment can pass together.
- **Edit lock** (`steadhand/edit-lock`): refuses a write to a post that another WordPress user has open in the block editor. The lock window is 150 seconds, as in WordPress. The `wp_check_post_lock_window` filter can change it, from 1 to 3,600 seconds. The refusal does not name the other user. A lock in WordPress's older format, a timestamp with no user, takes its holder from `_edit_last`. A fresh one with no holder, or holder 0, or another user's, blocks; the agent's own lock passes.

**A refusal.** The tool call fails with the WordPress error code `steadhand_guardrail_blocked` (HTTP status 422) and the rule's message. For example: "This agent has made too many changes in the last minute. Wait a minute, then try again." The refusal is recorded in the change log as `blocked`. A rule that cannot run also refuses the call, with "A guardrail could not check this call, so it was refused."

Third-party rules can be added with the `steadhand_register_guardrails` action (see [docs/HOOKS.md](docs/HOOKS.md)).

**What the HTML filter leaves in.** WordPress filters HTML on save for the agent, which lacks `unfiltered_html`, as it does for a Contributor. Some content passes that filter as written: shortcodes, which run when the post is rendered or previewed; URLs inside block attribute JSON, which are not filtered; and links in titles. This is the same exposure as WordPress's Contributor role.

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

The defaults are `steadhand-agent` for the username and `Local agent` for the label. The label becomes the name of the Application Password. Steadhand records it as the agent label, in the change log. The script:

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

The other twelve read tools are listed below. Each takes the arguments shown and refuses any other.

- `steadhand-list-posts` takes `post_type` (default `post`; must be an allowed type), `status` (one or more of `publish`, `future`, `draft`, `pending`, `private`; default all five), `search` (up to 200 characters), `after` and `before` (ISO 8601 dates), `orderby` (`date`, `modified` or `title`), `order`, `page` (1 to 10000) and `per_page` (1 to 50). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Trashed and auto-draft posts are never returned.
- `steadhand-get-post` takes `id`. It returns the post's fields and its raw `content`, exactly as stored.
- `steadhand-get-blocks` takes `id`. It returns the block tree. Each block has a `path` such as `0` or `1.0`, a `name`, `attrs`, `inner_html` and `inner_blocks`.
- `steadhand-get-rendered` takes `id`. It returns the post's rendered `html` as a fragment, not a full page. It omits what the agent may not read: galleries and playlists that name media or posts it may not read, legacy widgets, blocks bound to post meta, navigation links and submenus bound to post or term data, breadcrumbs, author links and bios, and video dimensions of unreadable attachments.

- `steadhand-list-terms` takes `taxonomy` (required; a public taxonomy attached to an allowed post type, such as `category` or `post_tag`), `search` (up to 200 characters, matched against names and slugs), `parent` (a term ID, or 0 for top-level terms), `orderby` (`name`, `count` or `id`), `order` (`asc` or `desc`), `page` (1 to 10000) and `per_page` (1 to 100). It returns `items`, `total`, `total_pages`, `page` and `per_page`. A taxonomy that is not allowed is refused, and the response names the allowed ones. Every public taxonomy attached to an allowed post type is readable: by default categories, tags and post formats, plus any public custom taxonomy attached to an allowed type. Each term's `count` is the number WordPress stores for it, not a fresh count.
- `steadhand-get-post-terms` takes `id`. It returns the allowed taxonomies of that post, each with its label, its `total` number of terms, `truncated`, and its terms. At most 500 terms are returned per taxonomy, in name order; `truncated` is true when there are more, and `total` gives the full count.
- `steadhand-list-menus` takes no arguments. It returns `menus`, classic menus first, then block navigation in the publish, draft, pending, future or private status, up to 200, with `truncated` set when there are more. A classic menu's `item_count` is the count WordPress stores for the menu. It can include an item that get-menu leaves out, such as one whose linked page is trashed, and it is not a count of loaded items.
- `steadhand-get-menu` takes `kind` (`classic` or `block`) and `id`. A classic menu loads the whole menu and returns its items as a flat list in menu order, at most 500, with each item's parent item ID, type, title and URL. An item's title and URL are the ones the public menu prints, as plain text. The item's linked object may be a draft, a private post, or a content type the post tools refuse; only its type, its ID, and that title and URL are returned. Items whose linked object is trashed or missing are left out. A block navigation post returns its raw `content` and its block tree.
- `steadhand-list-media` takes `media_type` (`image`, `video`, `audio`, `application` or `text`), `search` (up to 200 characters), `parent` (0 for unattached media, or a post ID you can read; any other parent is refused with the standard message), `after` and `before` (ISO 8601 dates, checked as real calendar dates), `orderby` (`date` or `title`), `order`, `page` (1 to 10000) and `per_page` (1 to 50). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Each item has `id`, `title`, `slug`, `mime_type`, `media_type`, `url`, `alt`, `caption`, `date_gmt`, `modified_gmt`, `author` (`id` and display name), `parent`, `width` and `height` (images and videos), `filesize` and `duration` (audio and video), each null when it is not stored. Titles, alt text and captions are plain text, cut at 1000 bytes. Only media the rule below allows is listed, and `total` counts only those.
- `steadhand-get-media` takes `id`. It returns the same fields as an item, plus `description` (the content as plain text, cut at 5000 bytes, with `truncated` set when it was cut) and `sizes` (the generated image sizes, at most 30, each with `name`, `url`, `width`, `height` and `mime_type`; empty for non-images).

- `steadhand-get-diagnostics` takes `max_errors` (1 to 50, default 20). It returns seven sections, described in [What get-diagnostics returns and what it never returns](#what-get-diagnostics-returns-and-what-it-never-returns).
- `steadhand-get-change-log` takes `page` (1 to 10000, default 1), `per_page` (1 to 50, default 20), `result` (`ok`, `blocked`, `fixed` or `error`), `ability` (a Steadhand ability name, such as `steadhand/get-post`), and `after` and `before` (ISO 8601 date-times, inclusive; a value without an offset is UTC). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Each item has `id`, `created_at`, `ability`, `object_type`, `object_id`, `result`, `message`, `guardrail_notes`, `undoable` and `undone`. It returns only this agent's own entries, and never the stored input of a call.

Content over 256 KB is cut, and the response then says `truncated: true` and gives the full size.

The six write tools change posts, their terms and the media library. All of them go through the guardrails and the change log, and a refused call gets the same messages as a read call.

- `steadhand-create-post` takes `title`, `content`, `excerpt`, `slug`, `status`, `date` and `post_type`. `status` is `draft` by default. `pending`, `publish`, `future` and `private` need `steadhand_publish`. `date` (ISO 8601) is allowed only with status `future`, where it is required, and it must be at least 60 seconds and at most 10 years ahead. `post_type` defaults to `post`, and must be an allowed type. A title or content must be sent. The title is at most 400 characters, the excerpt 5,000, the slug 200 (lowercase letters, digits and single hyphens), and the content 256 KB. The post is created as the calling agent. The tool returns `id`, `post_type`, `status`, `title`, `slug`, `link`, `modified`, `changed`, `content_modified_on_save`, `warnings` and `guardrail_notes`. `content_modified_on_save` is true when WordPress stored text that differs from what was sent.
- `steadhand-update-post` takes `id` and at least one of `title`, `content`, `excerpt`, `slug`, `status` and `date`, with the same limits and status rules. Only the fields that differ from the stored post change. `content` replaces the whole content. Publishing a draft needs both `steadhand_write_drafts` and `steadhand_publish`. The tool returns the same fields as `create-post`, and `changed` says whether anything was written. A post that is missing, in the trash, of a type that is not allowed, or that this agent may not edit, gets the standard message.
- `steadhand-edit-blocks` takes `id` and `operations`, one to 20 objects. Each has `op`: `replace`, `insert_before`, `insert_after`, `remove` or `append`. Every op except `append` takes `path` (as `get-blocks` returns it) and `name` (the block name `get-blocks` returned at that path, or `null` for classic HTML). `append` takes neither. `markup` is required except for `remove`. Every path refers to the post as stored before the call, and blocks that no operation names stay byte for byte. A path that does not name a block with that name is refused with `steadhand_refused_block_mismatch`, and nothing is written. Blocks it does not name are stored as they are, and are not passed through the save filters. Only the markup it sends, and the separators Steadhand adds next to it, are filtered by WordPress, as for a user without unfiltered HTML. The custom CSS strip for blocks runs on the sent blocks where WordPress has it (WordPress 7.0 and later); WordPress 6.9 has no such strip, so Steadhand runs none there. A script, an iframe or an attribute it does not allow is removed from what is stored, and the call is reported with `content_modified_on_save` set to true and a warning. Another plugin can still change the post during the save, and then `content_modified_on_save` is also true. Each operation's markup must be complete blocks on its own, with no HTML comment that is not a block comment, and a break comment only as the sole inner content of its `core/more` or `core/nextpage` block (`steadhand_invalid_input`), and it must still be complete blocks after WordPress has filtered it (`steadhand_refused_unsupported_markup`). The block delimiters of the final content must come only from the stored blocks it keeps and the markup it sends, so a delimiter cannot be completed across two places (`steadhand_refused_unsupported_markup`). Where a removal joins two parts of the stored content, the bytes before the join must not leave HTML open (`steadhand_refused_unsupported_markup`). The tool refuses a post whose HTML is left open before the place where the markup would go, such as an unclosed script, a comment or an attribute value (`steadhand_refused_unsupported_markup`), and an edit that would change blocks it does not name (also `steadhand_refused_unsupported_markup`). A closed script in a block is not refused. The tool cannot add the first child of an empty container block, because it has no path: it replaces the container instead. A post over the content limits (2,000 blocks, 20 levels, 256 KB) cannot be edited with it. It returns the same fields as `update-post`.
- `steadhand-set-post-terms` takes `id`, `taxonomy` (an allowed taxonomy that the post's type uses, such as `category` or `post_tag`), `terms` (up to 100 term IDs from `steadhand-list-terms` or `steadhand-get-post-terms`; it may be empty) and `mode` (`replace`, the default, `add` or `remove`). It never creates, renames or deletes a term, and names are refused. It needs `steadhand_read`, and `steadhand_write_drafts` or `steadhand_publish` by the post's status, as `update-post` does. It returns `term_ids` and `terms` (the result, at most 500), `previous_term_ids` (what the post had before, so the terms can be put back with this tool, because Steadhand cannot undo a change to terms yet), `changed`, `link` (the permalink after the call) and `warnings`. A call may leave more than 100 terms only when it adds none, so a `remove` works on a post that is already over 100; an `add` that would take it past 100 is refused. A post format takes one term. A taxonomy that is not allowed, or that the post's type does not use, is refused, and the allowed taxonomies are named when the post is readable. A post with more than 500 terms in the taxonomy is refused until a person reduces them. If the terms change while the call runs, nothing is written and the call fails with `steadhand_refused_content_changed`. When a published post moves to a new address, the warning says so. `save_post` does not run, so plugins that listen only to it are not told of the change. Two calls that overlap in time can leave more than 100 terms on a post; the later one usually reports `steadhand_update_partial`. The moved-address warning is given for published posts only.
- `steadhand-upload-media` takes `filename` (one base name of letters, digits, `_` and `-`, at most 80 characters, then one dot and `jpg`, `jpeg` or `png` in any case; no spaces, accents, paths, second dots or Windows device names), `data_base64` (the image as standard base64 with padding, with no whitespace, no line breaks and no `data:` prefix; the decoded image is 1 byte to 5 MB) and, optionally, `title`, `alt` and `caption` (plain text) and `parent` (0, or a post you may edit). Only JPEG and PNG are accepted, judged from the file's own bytes, and the extension must match them. GIF, WebP and SVG are refused, and so is an animated PNG, a JPEG with more than 50 scans, and a file that contains `<?php` (a best-effort check: other embedded text is stored as sent, and a stored file's name always ends in `.jpg`, `.jpeg` or `.png` and its detected type is an image). Each side is 1 to 8000 pixels, and the image may have at most 16 million pixels and must fit the memory the site allows. SVG and anything else is refused. Steadhand does not decode the image itself: core decodes it when it makes the sizes, more than once for a large or rotated image. The memory check does not apply to Imagick, which was not tested. On a fatal error during the write, WordPress's fatal error handler, with a shutdown function as the fallback, removes the files and the row. If PHP runs out of memory the cleanup can itself fail, and then a file and an attachment row without metadata can remain. It needs `steadhand_media` and `steadhand_read`. The output has the `get-media` fields of the new file, plus `warnings` and `guardrail_notes`. An uploaded file is public at once, at its uploads URL, even without `steadhand_publish`. Steadhand cannot undo an upload yet. EXIF data, which can include a GPS position, and other text in the file are stored as sent. Fetching an image from a URL comes in a later change.
- `steadhand-set-featured-image` takes `id` (the post) and `media_id` (an image you can read in the media library; `0` removes the featured image). The post must pass the same check as `update-post`, and its type must support a featured image. It needs `steadhand_read` and `steadhand_media`, and `steadhand_write_drafts` or `steadhand_publish` by the post's status. It returns `previous_featured_media`, so the earlier image can be set back. Steadhand cannot undo this change yet. Asking for the image the post already has changes nothing.

No post tool can set the author, a post's password, parent or meta, and `create-post` never sets terms. Post meta is not part of milestone M2. The fields you send are filtered by WordPress's post KSES rules, because an agent does not hold `unfiltered_html`: a tag that WordPress does not allow for this user, such as `<script>`, is removed from what you send, and the text between tags is kept. `edit-blocks` filters only the markup it sends, and stores the blocks it does not name as they are. The fields you do not send are saved exactly as they are stored, so a title-only update does not rewrite an administrator's content. Known limits: WordPress re-saves some values that were written into the database by hand in an unusual form. An empty comment status becomes closed, a non-canonical slug is sanitised, a post with no category gets the default one, and `to_ping` is sanitised.

**Policies in plain words.**

- **Media.** A file can be read when its own status is `inherit` (the normal state of a media item), and it is either unattached, attached to a post that no longer exists, or attached to a post of an allowed type that is not in the trash. The parent's status and author do not matter, so a file on a draft, a private page or another author's post can be read. A file attached to any other content type (for example a custom type that is not allowed, or a block), or to a trashed post, is refused with the standard message, and list-media neither lists it nor counts it in `total`. Files in the media library are public URLs, so this rule decides what the agent sees through these tools; it does not hide anything from the public.
- **Never returned for media.** No server file paths, and no raw attachment metadata. No EXIF, IPTC or GPS data from the image. A `url` is empty when the file has no stored location, or when the stored location is a server path, so that a server path is never printed as a URL.
- **Block navigation.** Block navigation is readable in the publish, draft, pending, future and private statuses. Trashed and auto-draft navigation is never listed or read.
- **Authors.** Every tool that returns an author gives its ID and display name. On many sites the display name is the same as the login name, so the login can be learned that way. No email address is ever returned.
- **Counts and caps.** A term's `count` and a menu's `item_count` are the numbers WordPress stores. Lists take at most 50 posts or media items, or 100 terms, per page; get-post-terms returns at most 500 terms per taxonomy; get-menu returns at most 500 items of a classic menu; list-menus returns at most 200 menus.

Refused and missing posts get the same message, so the tools cannot be used to find out which IDs exist. An administrator account is refused at the endpoint, with or without `steadhand_connect`, because only a dedicated agent connects.

### What get-diagnostics returns and what it never returns

`steadhand-get-diagnostics` takes `max_errors` and nothing else. It returns these sections:

- `environment`: the WordPress, PHP and database versions (the database kind and version only), memory and execution limits, the upload limit, whether the site is multisite, the environment type, the debug flags (whether `WP_DEBUG_LOG` is on, never where it writes), the object cache, whether the home URL is https, the timezone and the locale.
- `theme`: the active theme's name and version, and whether it is a child or a block theme.
- `plugins`: the active plugins by name and version, up to 100. Whether an update is already known comes from the update check WordPress stored earlier. No update check runs.
- `php_errors`: recent PHP engine errors (warnings, notices, deprecations, and fatal or parse errors), newest first, with a count for each repeated error.
- `cron`: how many scheduled events are overdue, the most overdue hook names, and when the next event is due.
- `site_health`: the Site Health counts that were stored the last time Site Health ran. No Site Health test is run from here.
- `steadhand`: Steadhand's own self-check: its version, the MCP Adapter's version, whether its ability category, abilities, MCP server and lockdown are registered, and whether the change-log table exists.

It never returns:

- **Anything except PHP engine errors from WordPress's own debug log.** That log is read only when `WP_DEBUG_LOG` is on. The server-wide PHP error log and every other file are never read, and a log file reached through a symlink, or a path outside the content folder and the WordPress root, is refused unless the site owner typed that exact path.
- **Free-form log output and stack traces.** Lines that are not PHP engine errors, such as `error_log()` output from plugins and themes and the trace lines after a fatal error, are skipped and only counted. That output is where dumped data, SQL and credentials tend to end up.
- **Absolute server paths.** Site directories are removed from messages, and file names are relative to the site. Any other absolute path left in a message (POSIX, Windows drive or UNC) is reduced to its file name, so `/home/x/wp-config.php` appears as `wp-config.php`. URLs are not changed.
- **Database credentials, the table prefix or the authentication keys and salts.** In a connection error the database user and host are masked (`for user '[redacted]'@'[redacted]'`), so the account and the server name do not appear.
- **Cron hook arguments.** A stack trace in a message is cut at its first `Stack trace:` or `#0` frame, so the arguments of the calls in it do not appear.
- **Plugin file paths, plugin authors, or inactive plugins.**

Messages are cut to 500 bytes, and the messages of uncaught exceptions are returned after redaction, so they may include text that the throwing code put there. Redaction is best-effort. Messages pass through `Steadhand\Redactor`, which masks common secret formats: authorization and cookie headers; passwords, tokens, keys and session values in key/value pairs, query strings, JSON, PHP array dumps and serialized data, with bracketed values masked whole; the WordPress session cookies; credentials in URLs (up to the last `@` before the host); the Basic, Bearer, Negotiate and Digest authentication schemes; JSON Web Tokens; private keys; password hashes; common API token formats; WordPress application passwords; long random strings; and card numbers that pass the Luhn check. Email addresses, including internationalised ones, are replaced with `[email]`. Redaction reduces the chance that a secret leaks. It cannot guarantee that none does, so a secret that no pattern recognises can still appear in a message.

The redaction does **not** catch: an unlabelled short password in free text; a secret under a key in a non-Latin script, or under a key with look-alike or zero-width characters; a key whose name is URL-encoded; a password given to `curl -u user:pass`; base64 of `user:pass` without a scheme name; the short lines of a PGP private key block; a secret shorter than 32 characters with no label, prefix or known shape; and phone numbers and IP addresses, which are not masked by design.

The plugin list reveals the installed plugin versions to anyone who holds an agent credential with `steadhand_read`. The tool writes nothing and makes no outbound HTTP request. Its log messages and names are site data, not instructions.

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

Deleting Steadhand runs `uninstall.php`, which removes the agent role, its access and the change log:

- Removes the `steadhand_agent` role.
- Removes every Steadhand capability from every user.
- Drops the change-log table, `{prefix}steadhand_log`, and removes the options `steadhand_db_version` and `steadhand_log_last_prune`.
- Deletes the Application Passwords of users whose only role was `steadhand_agent`. Those passwords exist only for agent access. Such a user is left with no role.

Users are never deleted. A user who also holds another role keeps their Application Passwords and loses only the agent role and the Steadhand capabilities.

Deactivating Steadhand, as opposed to uninstalling it, keeps the role, its capabilities, the Application Passwords and the change-log table. Steadhand registers no deactivation hook.

`uninstall.php` loads the Composer autoloader before it does anything. In a source checkout without `vendor/`, uninstall does nothing: the role, its grants and the change-log table remain. Release zips always include `vendor/`.

## Contributing and security

- Work on a branch from `main` and open a pull request. The maintainer reviews and merges every pull request. Do not push to `main`.
- Coding agents and contributors should read [CLAUDE.md](CLAUDE.md) and [docs/SPEC.md](docs/SPEC.md) before they start. Extension points are listed in [docs/HOOKS.md](docs/HOOKS.md).
- To report a security problem, follow [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability.

Steadhand is licensed under the GPL-2.0-or-later. See [LICENSE](LICENSE).
