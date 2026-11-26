# Steadhand

Steadhand is being built to let AI agents work on a WordPress site safely. Steadhand is being built so that each agent gets only the permissions it needs, known site-breaking writes are blocked or fixed before they land, and every action is logged and can be undone. See Status for what exists today.

Steadhand is an access and governance layer, not an AI plugin. It never calls an AI model, stores no AI provider keys and adds no chatbot. The AI runs in the MCP client, such as Claude, ChatGPT or Cursor.

## Status

**Pre-release.** Steadhand has been tested only on its maintainers' own sites, and has had no outside security review. Do not rely on it on a production site yet. Milestone M1 (read-only tools) is complete (version 0.1.0). Milestone M2 (safe writes) is complete (version 0.2.0). Version 0.2.1, a fix release, is tagged and installed on a maintainer's site. All six write tools were called against that site on 0.2.0 and behaved as specified; they have not been called again on 0.2.1. Version 0.3.0 holds the code of milestone M3 (undo and the admin screens): `steadhand/undo-change`, the change-log screen with its Undo button, and the Agents screen. Version 0.3.0 is tagged and installed on that site, and a live test of its undo tools and of the administrator's Undo button passed. Version 0.3.1 reworks the Agents screen and is installed there. Version 0.3.2 adds disable, enable and delete for an agent, connection instructions on the Agents screen, and `readme.txt`; these have not been opened in a browser. Version 0.4.0 adds `steadhand/trash-post` and `steadhand/restore-post`, and a dev updater that needs no token; the two tools have not been called on a live site. M3 is not complete: the maintainers' two sites do not yet use Steadhand as their only MCP server. M2 has the write path, the guardrail engine with five built-in rules, the post write tools, the term write tool and the media write tools: `steadhand/upload-media`, which adds an image to the media library from a base64 payload, and `steadhand/set-featured-image`, which sets or removes a post's featured image. The plugin registers twenty-three tools. Thirteen are read-only. Ten are write tools: `steadhand/create-post`, `steadhand/update-post` and `steadhand/edit-blocks`, which can change post content, `steadhand/set-post-terms`, which can change the terms of a post, and `steadhand/upload-media` and `steadhand/set-featured-image`, which can change the media library and a post's featured image, and `steadhand/undo-change`, which reverses one earlier change of the calling agent made by `update-post`, `edit-blocks`, `set-post-terms` or `set-featured-image`, and `steadhand/trash-post` and `steadhand/restore-post`, which move a post or page to the trash and bring back one the same agent trashed, and `steadhand/update-menu`, which adds, renames, moves, hides and shows items of a classic menu. Steadhand can now change site content for agents that hold the write capabilities. A new agent holds `steadhand_write_drafts` by default, so it can create drafts and edit draft, pending and auto-draft posts of any author. Publishing needs `steadhand_publish`, which is off by default. Nothing else is writable yet: no image fetched from a URL, no menus, trash, settings or post meta. `steadhand/get-rendered` runs the site's own blocks and shortcodes to produce HTML. While it renders, Steadhand blocks outbound HTTP requests and embed-cache writes, so embeds appear as plain URLs, and it omits login/logout blocks, avatar blocks and mis-referenced navigation blocks. It renders a `[gallery]` or `[playlist]` shortcode as nothing when it would show media or children that `get-media` or `get-post` refuses. It also omits every avatar image wherever it is printed, so no Gravatar hash of an email address and no author-archive link appears in the output. Third-party shortcodes may still have side effects of their own:

- `steadhand/get-site-info`: site name, URL, versions, active theme and this agent's capabilities.
- `steadhand/list-posts`: a page of posts of one allowed type (`post` and `page` by default), in the statuses the agent asks for, by any author.
- `steadhand/get-post`: one post with its raw content as stored.
- `steadhand/get-blocks`: the block tree of one post, with an index path for each block.
- `steadhand/get-rendered`: the HTML a post's content produces through the site's content filters, with the render limits above.
- `steadhand/list-terms`: a page of the terms of one public taxonomy attached to an allowed post type (categories, tags and post formats by default), including empty terms, with their stored counts.
- `steadhand/get-post-terms`: the allowed terms assigned to one post, grouped by taxonomy, at most 500 per taxonomy.
- `steadhand/list-menus`: classic menus and block navigation menus, with stored item counts and theme locations.
- `steadhand/get-menu`: one classic menu as a flat list of up to 500 items in menu order, each marked `hidden` or not, or one block navigation post with its content and block tree.
- `steadhand/list-media`: a page of media items the media rule allows, newest first, with public URLs, alt text, captions, dimensions where stored, and the parent post ID. Trashed media is never listed.
- `steadhand/get-media`: one media item, with its description as plain text and its generated image sizes. Server file paths and image metadata (EXIF, IPTC, GPS) are never returned.
- `steadhand/get-diagnostics`: a read-only health report: environment, theme, active plugins, recent PHP engine errors from WordPress's own debug log, overdue cron events, the Site Health counts already stored, and Steadhand's self-check. See [What get-diagnostics returns and what it never returns](#what-get-diagnostics-returns-and-what-it-never-returns).
- `steadhand/get-change-log`: this agent's own change-log entries, newest first. It never returns another agent's entries or any stored input. See [Change log](#change-log).
- `steadhand/create-post`: creates one post or page as the calling agent. It is saved as a draft unless the agent also holds `steadhand_publish`. A write tool.
- `steadhand/update-post`: changes the fields you send on one existing post or page. It needs `steadhand_read`, and `steadhand_write_drafts` or `steadhand_publish`. Before it changes a title, content or excerpt, it saves a WordPress revision when the site keeps two or more revisions, or unlimited ones. It refuses, changing nothing, a post whose page template the active theme does not have (`steadhand_refused_invalid_template`; an administrator must set a valid template), A field you do not send is kept as it is stored, even when a filter on this site would change it on save. A scheduled post cannot be published early with it. A private post made from a protected one loses its password, and the result says so. If WordPress fails after it has changed the post, or a field you did not send changes after the save, the error is `steadhand_update_partial`, and the post should be checked. A write tool.
- `steadhand/edit-blocks`: changes some blocks of one post or page, by the paths `get-blocks` returns, without resending the whole content. It uses the permissions, revision and error rules of `update-post`. Blocks that no operation names stay byte for byte, or the call is refused. A block whose name does not match is refused. Blocks it does not name are stored as they are and are not filtered. The markup it sends must be complete blocks, and is filtered by WordPress as for a user without unfiltered HTML; a change to it is reported with `content_modified_on_save` and a warning. A stored post whose HTML is left open before the edit (for example an unclosed script) is refused, and so is an edit that would change blocks it does not name. The guardrails check the exact content that is written: a result that differs between the check and the write is refused, with nothing written. WordPress cuts the post's display at a `wp:more` delimiter, so an edit that would let a stored or sent void `wp:more` delimiter reach past its place into other bytes is refused too. The check covers the whole result, not only the sent markup. A post over the content limits cannot be edited with it. A write tool.
- `steadhand/undo-change`: reverses one earlier change that this agent made with `update-post`, `edit-blocks`, `set-post-terms` or `set-featured-image`. For post text it restores the title, content and excerpt to what they were just before that change, and only while the post still holds the text that change left and no revision of the post was created after that change's after-revision (creation order, by ID, never by date). It marks the entry as undone. Only this agent's own entries can be undone, and once an undo is made, the same post's older changes cannot be undone. It sends only the title, content and excerpt; the save is WordPress's own, as for `update-post`. An undo cannot itself be undone. See [Change log](#change-log).

The change log records every write, every refused call and every failed call, and an administrator can read it on an admin screen (see [Change log](#change-log)). The guardrail engine has six built-in rules (see [Guardrails](#guardrails)). Undo is built for changes made by `update-post`, `edit-blocks`, `set-post-terms` and `set-featured-image` (see `steadhand/undo-change` below). Undo for `create-post` and `upload-media` is not built yet. The change-log screen is built, with its Undo button for an administrator (see [Change log screen](#change-log-screen)). Do not use it on a production site, except internal dogfooding sites set up as described in [Dev builds on internal sites](#dev-builds-on-internal-sites).

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
| `steadhand_menus` | off | `steadhand-update-menu` adds, renames, moves, hides and shows items of classic menus. It also needs `steadhand_publish`. A change is live on the public site at once. It cannot delete an item or a menu, or change theme locations. |
| `steadhand_trash` | off | `steadhand-trash-post` moves a post or page to the trash, and `steadhand-restore-post` restores one the agent trashed. Neither deletes permanently. Trashing a published, scheduled or private post also needs `steadhand_publish`. Restoring a draft or pending post, or a live post that would come back as a draft, also needs `steadhand_write_drafts`. WordPress deletes trashed posts after the site's trash period (`EMPTY_TRASH_DAYS`). |
| `steadhand_settings` | off | `steadhand-update-option` changes the site title (`blogname`) and tagline (`blogdescription`), and no other option unless the `steadhand_allowed_options` filter allows it. It also needs `steadhand_read` and `steadhand_publish`. A change is live on every page at once. It never creates or deletes an option, and it refuses an option that holds structured data or that the site overrides in code. Its change cannot be undone with `steadhand-undo-change`; the output gives the previous value, so the change can be put back. |

Administrators create and manage agents on the **Agents** screen under **Steadhand** in the admin menu (see [Agents screen](#agents-screen)). The access rules are in SPEC section 4.

## Change log

Steadhand writes its change log to one table, `{prefix}steadhand_log` (`wp_steadhand_log` with the default prefix). Activation creates it. It is also created on the first request after an update, if the table's recorded version is missing or different. If that create fails, Steadhand retries no more than once an hour.

**What is recorded.** Every refused call and every failed call, to every Steadhand tool, with a result of `blocked` or `error`. Every successful write, from `create-post`, `update-post`, `edit-blocks`, `set-post-terms`, `upload-media`, `set-featured-image` and `undo-change`, with a result of `ok`, or `fixed` when a guardrail rewrote its input. The image payload of `upload-media` is stored only as the digest of its decoded bytes, never as text. Each entry has the time (UTC), the agent user, the agent label (the name of the Application Password the agent used), the tool, the post or media ID the call named (even when the call was refused), the message without the code suffix that Steadhand adds over MCP, and the call's input. The input is redacted on a best-effort basis, keys as well as values, and stored up to 16 KB. A larger input is stored as a summary: its size and its top-level key names. Input over 256 KB is not scanned at all. Post content is stored as a digest instead of text: its byte count and SHA-256, written as eight groups of eight hex digits separated by colons. An excerpt over 500 bytes is stored the same way, and so is the markup sent to `edit-blocks`. The revisions hold the text.

**What is not recorded.** Successful calls to read-only tools that need `steadhand_read`, unless a site turns them on with the `steadhand_log_read_calls` filter (see [docs/HOOKS.md](docs/HOOKS.md)). Calls that core rejects on their input before Steadhand's permission check runs, such as input that fails the schema check. That is a known gap.

**Writes.** A write is recorded in two steps. Its row is stored before the change, with the message "The call started, but no outcome was recorded." until the outcome is written to it, so a crash leaves a row that says so. If that first row cannot be stored, the write does not run and the caller gets `steadhand_log_failed`. If the change is made and its outcome cannot be recorded, the caller gets `steadhand_log_incomplete`, which says the change was made. Before `update-post` changes a title, content or excerpt (`edit-blocks` changes content through it), Steadhand saves a WordPress revision of the post, when revisions are available; `warnings` says when none could be saved. `snapshot_id` is the ID of the newest revision that matches the post's title, content and excerpt exactly (autosaves are never used). `undoable` is 1 only when that revision was found, the call changed only those three fields, and the state after the call was saved as a revision that holds exactly the text the call stored (`after_snapshot_id`). Steadhand does not claim undo for status, slug or date, and a created post has no earlier state to restore. Undo of `update-post`, `edit-blocks`, `set-post-terms` and `set-featured-image` changes is built, through `steadhand/undo-change`. Undo of `create-post` and `upload-media` is not built yet.

**Limits.** At most 60 refused calls per agent are stored in any rolling minute. Then one marker row is stored, and nothing more until the minute has passed. Errors are never dropped. Entries are kept 30 days, and the saved states that undo needs are kept as long as their entries. Old entries are pruned when a new entry is written, at most once a day, in batches of 1,000 rows, up to 20 batches per pass. If old entries remain after that, the next write continues the pruning. Nothing runs on a schedule, so old entries remain while nothing is written. The period can be changed with the `steadhand_log_retention_days` filter.

**Who can see it.** An agent can read only its own entries, with `steadhand-get-change-log`. An administrator sees every agent's entries on the screen described in [Change log screen](#change-log-screen). An administrator can also read the table directly, for example:

```
wp db query "SELECT id, created_at, agent_label, ability, result, message FROM wp_steadhand_log ORDER BY id DESC LIMIT 20"
```

Use the table prefix from `wp-config.php`. The table holds every agent's entries and the stored input, so treat it as sensitive. On the local development site, run it with `npx wp-env run cli wp db query "..."`.

## Change log screen

Administrators find the change log under **Steadhand** in the admin menu. The list filters by agent, tool, result and date (days are read in the site's timezone), and shows 50 entries a page. Open an entry to see its stored fields, its input and a diff of the change: the title, content and excerpt for a post edit, the terms added and removed, or the featured image before and after. The diff needs the right to edit that post; without it, the diff is not shown. The message, the guardrail notes and the input of an entry about a post are hidden from a viewer who may not edit that post, and the screen shows a short note in their place. Entries about no post are always shown. Agents cannot open the screen. The screen reads, except for one action: on an entry that Steadhand can reverse, an administrator who holds no agent role and may edit the post sees an **Undo this change** button. The undo is refused, with nothing changed, while the post has a live edit lock, held by any user, the administrator's own session included. It puts the post back as it was before that change (its text, terms or featured image), under the same rules as the agent's `steadhand/undo-change`, and the undo is recorded in the change log. Nothing is undone unless the post still holds what the change left.

## Agents screen

Under **Steadhand** in the admin menu, **Agents** has three views:

- **List** (the default): one table of the agents, with the columns Agent, Status, Capabilities, Connections and Last used, and a Manage link for each. Status reads Disabled, Disabled (N connections remain), No connections or Active. The list has no forms.
- **Detail** (one agent, `agent=<id>`): the agent's capabilities, its connections (each Application Password, by its connection name), and a link to its change-log entries. An administrator can turn each capability on or off, add a connection (up to 10 Application Passwords), revoke one, or revoke all of them. The "Disable or delete" section disables an agent (it revokes every connection and stops it connecting; the user account stays), enables it again, or deletes its user account after a confirmation. Steadhand never deletes content, so an agent with posts, revisions or links attributed to it cannot be deleted; disable it instead. Revisions count because core's revisions are Steadhand's undo snapshots, so an agent that has ever edited a post is in that state. A disabled agent cannot be given a new connection: enable it first. Unticking Connect in the capabilities form does not revoke the agent's connections, so a disabled agent may show "Disabled (N connections remain)" until Disable revokes them. After a revoke, a save of the capabilities, or a disable or enable, the screen returns to this view.
- **Add agent** (`view=new`): a username, the capabilities the agent gets, and the connection name of its first Application Password. The connection name is the agent's name in the change log. One box is shown for `steadhand_connect` and for each capability that a registered tool uses, and it starts checked or unchecked as the role's default says. A box that is unchecked gives the agent no such capability. Connect must be ticked to create an agent, because a new agent makes its first connection: a create with Connect unticked is refused, and nothing is made. To make an agent that cannot connect, create it and then disable it.

The new password is shown once, in the response to the form that made it. Copy it then. It cannot be shown again, and Steadhand does not save it, log it or put it in a link.

The detail view of an agent has a **Connect a client** section. It gives the endpoint, the transport and the username, the Claude Code command with a placeholder for the Base64 value, and how to check the connection. It never shows a password. The one-time panel after a create or an add shows the finished Claude Code command and the header value, which contain the password. Copy them then, since they cannot be shown again. The command is printed only for a plain endpoint (an http or https address whose characters are letters, digits, dots, hyphens, slashes and `?`, `=`, `&`, `~`, `_`), and only for a username without a colon, since HTTP Basic reads the username up to its first colon. Otherwise the screen says the command cannot be shown safely. Do not add `--scope project` to the command: it writes the password into a `.mcp.json` file in the current folder, where it can be committed to a repository.

Only an administrator who holds no agent role, and may list users, sees the screen. Each change needs a valid form, `manage_options` and `list_users`, and the WordPress capability for the act: `create_users` to create an agent, and `edit_user` for the agent to change it. Only a dedicated agent can be changed, so an administrator, an editor, or an account that also holds another role is refused. Such an account is not listed as an agent: the list shows a warning about it instead, and it has no forms. A detail request for a user who is not a dedicated agent shows the "not a dedicated agent" notice and the list, and nothing about that user. Application Passwords need HTTPS. Without them the screen says so and offers no create or add form. The screen is not available on a multisite network, where it shows only a notice (SPEC D13). A new agent is checked again after each write. The check compares its roles and stored capabilities with the ones the form chose. A user that does not match is rolled back, and no password is shown: it is deleted, or kept with no role and no password when content is attributed to it.

`bin/create-agent.sh` (below) creates an agent from the command line, on the local wp-env site only. Use it for local development, never on a real site.

## Guardrails

Every write goes through the guardrail engine before it runs. Six built-in rules are always on. Each one refuses a call with a message. None of them rewrites a call or warns yet.

- **Content size and nesting** (`steadhand/content-limits`): refuses content over 256 KB, more than 2,000 blocks (nested blocks count), nesting deeper than 20 levels, or content that is not valid UTF-8. Content exactly at a limit is allowed.
- **Block markup** (`steadhand/block-markup`): refuses block comment delimiters that WordPress would not read as blocks (malformed, unbalanced, or closed out of order), and attributes that are not valid JSON. **What it does not check:** it does not run the block editor's own check that a block's saved HTML matches what its JavaScript would save, so a block can pass here and still show "This block contains unexpected or invalid content" in the editor. It does not check that a block is registered on the site, or that its attributes match its schema. Markup it cannot read is refused. Delimiters are matched case-sensitively and need whitespace after `<!--`, as WordPress reads them, so text such as `<!-- WP:Engine cache note -->` is allowed. **Known false positive:** text that looks like a malformed delimiter, such as `<!-- wp:x` with no closing comment, is refused when it sits inside a `core/html` block or inside an ordinary HTML comment that starts with `<!-- wp:`.
- **Rate limit** (`steadhand/rate-limit`): 30 writes per agent in any rolling minute, counted from the change log. Refused calls do not count. The count is read before the row is reserved, so writes that arrive at the same moment can pass together: the limit is best effort under concurrent requests. A site can set the limit from 1 to 600 with the `steadhand_rate_limit_per_minute` filter (see [docs/HOOKS.md](docs/HOOKS.md)). It cannot be turned off.
- **Daily upload cap** (`steadhand/daily-upload-limit`): 100 `upload-media` calls per agent in any rolling 24 hours, counted from the change log. A refused upload is logged as blocked, with the rate limit's error (code `steadhand_guardrail_blocked`; the HTTP status, 422, is not sent to the agent over MCP). If the count cannot be read, the upload is refused. The limit is fixed in code, not a filter. Both upload limits are best effort under concurrent requests: the count is read before the row is reserved, so uploads that arrive at the same moment can pass together.
- **Option allowlist** (`steadhand/protected-options`): refuses an `update-option` call for an option that is not on the allowlist, or that is hard-blocked. The hard-coded blocks cannot be changed by any setting or filter.
- **Edit lock** (`steadhand/edit-lock`): refuses a write to a post that another WordPress user has open in the block editor. The lock window is 150 seconds, as in WordPress. The `wp_check_post_lock_window` filter can change it, from 1 to 3,600 seconds. The refusal does not name the other user. A lock in WordPress's older format, a timestamp with no user, takes its holder from `_edit_last`. A fresh one with no holder, or holder 0, or another user's, blocks; the agent's own lock passes.

**A refusal.** The tool call fails with the rule's message, which ends with the error code in parentheses, `(steadhand_guardrail_blocked)`, because the MCP Adapter passes only the text to the agent. For example: "This agent has made too many changes in the last minute. Wait a minute, then try again." The refusal is recorded in the change log as `blocked`. A rule that cannot run also refuses the call, with "A guardrail could not check this call, so it was refused."

Third-party rules can be added with the `steadhand_register_guardrails` action (see [docs/HOOKS.md](docs/HOOKS.md)).

**The Guardrails screen.** Under **Steadhand**, **Guardrails** lists the built-in rules with their current limits. For the rate limit it shows the default and the allowed range, because the filter that can change it runs only in a write, and the screen does not run it. It does not list the rules other plugins add, because those are registered only when an agent's write reaches the guardrail step; a rule that blocks, fixes or warns a call is named in that call's guardrail notes in the change log. An administrator who holds no agent role can open it. The presets are optional guardrails, each on or off, in a fixed list in code. The first one, **Rank Math options** (on by default), refuses any `update-option` call for an option whose name starts with `rank-math`, `rankmath` or `rank_math`, in any letter case, while it is on. Rank Math's main settings are structured data, which Steadhand refuses whatever the preset says, so this preset matters for Rank Math's plain-text options, and only when another plugin adds them to the allowlist (the built-in list has none). Nothing else on the screen can be changed, and the hard-coded option blocks cannot be changed there or by any filter. On a multisite network the screen shows only a notice. Presets are not built for content rewriting yet: there is no color-slug fix and no Kadence attribute rule (SPEC section 6.2).

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
- `steadhand-get-menu` takes `kind` (`classic` or `block`) and `id`. A classic menu loads the whole menu and returns its items as a flat list in menu order, at most 500, with each item's parent item ID, type, title, URL and `hidden`. A hidden item is in the draft status, so the public menu does not print it. An item's title and URL are the ones the public menu prints, or would print for a hidden item, as plain text. The item's linked object may be a draft, a private post, or a content type the post tools refuse; only its type, its ID, and that title and URL are returned. Items whose linked object is trashed or missing are left out. A block navigation post returns its raw `content` and its block tree.
- `steadhand-list-media` takes `media_type` (`image`, `video`, `audio`, `application` or `text`), `search` (up to 200 characters), `parent` (0 for unattached media, or a post ID you can read; any other parent is refused with the standard message), `after` and `before` (ISO 8601 dates, checked as real calendar dates), `orderby` (`date` or `title`), `order`, `page` (1 to 10000) and `per_page` (1 to 50). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Each item has `id`, `title`, `slug`, `mime_type`, `media_type`, `url`, `alt`, `caption`, `date_gmt`, `modified_gmt`, `author` (`id` and display name), `parent`, `width` and `height` (images and videos), `filesize` and `duration` (audio and video), each null when it is not stored. Titles, alt text and captions are plain text, cut at 1000 bytes. Only media the rule below allows is listed, and `total` counts only those.
- `steadhand-get-media` takes `id`. It returns the same fields as an item, plus `description` (the content as plain text, cut at 5000 bytes, with `truncated` set when it was cut) and `sizes` (the generated image sizes, at most 30, each with `name`, `url`, `width`, `height` and `mime_type`; empty for non-images).

- `steadhand-get-diagnostics` takes `max_errors` (1 to 50, default 20). It returns seven sections, described in [What get-diagnostics returns and what it never returns](#what-get-diagnostics-returns-and-what-it-never-returns).
- `steadhand-get-change-log` takes `page` (1 to 10000, default 1), `per_page` (1 to 50, default 20), `result` (`ok`, `blocked`, `fixed` or `error`), `ability` (a Steadhand ability name, such as `steadhand/get-post`), and `after` and `before` (ISO 8601 date-times, inclusive; a value without an offset is UTC). It returns `items`, `total`, `total_pages`, `page` and `per_page`. Each item has `id`, `created_at`, `ability`, `object_type`, `object_id`, `result`, `message`, `guardrail_notes`, `undoable` and `undone`. It returns only this agent's own entries, and never the stored input of a call.

Content over 256 KB is cut, and the response then says `truncated: true` and gives the full size.

The nine write tools change posts, their terms, the media library and the trash, and `steadhand/undo-change` reverses a change made by `update-post`, `edit-blocks`, `set-post-terms` or `set-featured-image`. All of them go through the guardrails and the change log, and a refused call gets the same messages as a read call.

- `steadhand-create-post` takes `title`, `content`, `excerpt`, `slug`, `status`, `date` and `post_type`. `status` is `draft` by default. `pending`, `publish`, `future` and `private` need `steadhand_publish`. `date` (ISO 8601) is allowed only with status `future`, where it is required, and it must be at least 60 seconds and at most 10 years ahead. `post_type` defaults to `post`, and must be an allowed type. A title or content must be sent. The title is at most 400 characters, the excerpt 5,000, the slug 200 (lowercase letters, digits and single hyphens), and the content 256 KB. The post is created as the calling agent. The tool returns `id`, `post_type`, `status`, `title`, `slug`, `link`, `modified`, `changed`, `content_modified_on_save`, `warnings` and `guardrail_notes`. `content_modified_on_save` is true when WordPress stored text that differs from what was sent.
- `steadhand-update-post` takes `id` and at least one of `title`, `content`, `excerpt`, `slug`, `status` and `date`, with the same limits and status rules. Only the fields that differ from the stored post change. `content` replaces the whole content. Publishing a draft needs both `steadhand_write_drafts` and `steadhand_publish`. The tool returns the same fields as `create-post`, and `changed` says whether anything was written. A post that is missing, in the trash, of a type that is not allowed, or that this agent may not edit, gets the standard message.
- `steadhand-edit-blocks` takes `id` and `operations`, one to 20 objects. Each has `op`: `replace`, `insert_before`, `insert_after`, `remove` or `append`. Every op except `append` takes `path` (as `get-blocks` returns it) and `name` (the block name `get-blocks` returned at that path, or `null` for classic HTML). `append` takes neither. `markup` is required except for `remove`. Every path refers to the post as stored before the call, and blocks that no operation names stay byte for byte. A path that does not name a block with that name is refused with `steadhand_refused_block_mismatch`, and nothing is written. Blocks it does not name are stored as they are, and are not passed through the save filters. Only the markup it sends, and the separators Steadhand adds next to it, are filtered by WordPress, as for a user without unfiltered HTML. The custom CSS strip for blocks runs on the sent blocks where WordPress has it (WordPress 7.0 and later); WordPress 6.9 has no such strip, so Steadhand runs none there. A script, an iframe or an attribute it does not allow is removed from what is stored, and the call is reported with `content_modified_on_save` set to true and a warning. Another plugin can still change the post during the save, and then `content_modified_on_save` is also true. Each operation's markup must be complete blocks on its own, with no HTML comment that is not a block comment, and a break comment only as the sole inner content of its `core/more` or `core/nextpage` block (`steadhand_invalid_input`), and it must still be complete blocks after WordPress has filtered it (`steadhand_refused_unsupported_markup`). The block delimiters of the final content must come only from the stored blocks it keeps and the markup it sends, so a delimiter cannot be completed across two places (`steadhand_refused_unsupported_markup`). Where a removal joins two parts of the stored content, the bytes before the join must not leave HTML open (`steadhand_refused_unsupported_markup`). The tool refuses a post whose HTML is left open before the place where the markup would go, such as an unclosed script, a comment or an attribute value (`steadhand_refused_unsupported_markup`), and an edit that would change blocks it does not name (also `steadhand_refused_unsupported_markup`). A closed script in a block is not refused. The tool cannot add the first child of an empty container block, because it has no path: it replaces the container instead. A post over the content limits (2,000 blocks, 20 levels, 256 KB) cannot be edited with it. It returns the same fields as `update-post`.
- `steadhand-set-post-terms` takes `id`, `taxonomy` (an allowed taxonomy that the post's type uses, such as `category` or `post_tag`), `terms` (up to 100 term IDs from `steadhand-list-terms` or `steadhand-get-post-terms`; it may be empty) and `mode` (`replace`, the default, `add` or `remove`). It never creates, renames or deletes a term, and names are refused. It needs `steadhand_read`, and `steadhand_write_drafts` or `steadhand_publish` by the post's status, as `update-post` does. It returns `term_ids` and `terms` (the result, at most 500), `previous_term_ids` (what the post had before, so the terms can be put back with this tool; `steadhand-undo-change` can also reverse the change), `changed`, `link` (the permalink after the call) and `warnings`. A call may leave more than 100 terms only when it adds none, so a `remove` works on a post that is already over 100; an `add` that would take it past 100 is refused. A post format takes one term. A taxonomy that is not allowed, or that the post's type does not use, is refused, and the allowed taxonomies are named when the post is readable. A post with more than 500 terms in the taxonomy is refused until a person reduces them. If the terms change while the call runs, nothing is written and the call fails with `steadhand_refused_content_changed`. When a published post moves to a new address, the warning says so. `save_post` does not run, so plugins that listen only to it are not told of the change. Two calls that overlap in time can leave more than 100 terms on a post; the later one usually reports `steadhand_update_partial`. The moved-address warning is given for published posts only.
- `steadhand-upload-media` takes `filename` (one base name of letters, digits, `_` and `-`, at most 80 characters, then one dot and `jpg`, `jpeg` or `png` in any case; no spaces, accents, paths, second dots or Windows device names), `data_base64` (the image as standard base64 with padding, with no whitespace, no line breaks and no `data:` prefix; the decoded image is 1 byte to 5 MB) and, optionally, `title`, `alt` and `caption` (plain text) and `parent` (0, or a post you may edit). Only JPEG and PNG are accepted, judged from the file's own bytes, and the extension must match them. GIF, WebP and SVG are refused, and so is an animated PNG, a JPEG with more than 50 scans, and a file that contains `<?php` (a best-effort check: other embedded text is stored as sent, and a stored file's name always ends in `.jpg`, `.jpeg` or `.png` and its detected type is an image). Each side is 1 to 8000 pixels, and the image may have at most 16 million pixels and must fit the memory the site allows. SVG and anything else is refused. Steadhand does not decode the image itself: core decodes it when it makes the sizes, more than once for a large or rotated image. The memory check does not apply to Imagick, which was not tested. On a fatal error during the write, WordPress's fatal error handler, with a shutdown function as the fallback, removes the files and the row. If PHP runs out of memory the cleanup can itself fail, and then a file and an attachment row without metadata can remain. It needs `steadhand_media` and `steadhand_read`. The output has the `get-media` fields of the new file, plus `warnings` and `guardrail_notes`. An uploaded file is public at once, at its uploads URL, even without `steadhand_publish`. Steadhand cannot undo an upload yet. EXIF data, which can include a GPS position, and other text in the file are stored as sent. Fetching an image from a URL comes in a later change.
- `steadhand-set-featured-image` takes `id` (the post) and `media_id` (an image you can read in the media library; `0` removes the featured image). The post must pass the same check as `update-post`, and its type must support a featured image. It needs `steadhand_read` and `steadhand_media`, and `steadhand_write_drafts` or `steadhand_publish` by the post's status. It returns `previous_featured_media`, so the earlier image can be set back. `steadhand-undo-change` can reverse the change. Asking for the image the post already has changes nothing.
- `steadhand-trash-post` takes `id`. It moves one post or page to the trash and never deletes it. The output gives `previous_status`, `status` (`trash`), `title`, and `permanent_delete_after`, the time after which WordPress deletes the post unless it is restored. `warnings` says when a published, scheduled or private post left the site, how many child pages keep it as their parent, and when WordPress will delete it. It needs `steadhand_read`, `steadhand_trash`, and `steadhand_write_drafts` for a draft or pending post or `steadhand_publish` for a live one. It refuses, with nothing changed, the site's front page, posts page and privacy policy page, a sticky post (trashing it would take it out of the sticky posts, and a restore would not put it back), an auto-draft, an attachment, and any post of a type that is not allowed, and when the trash is turned off (`EMPTY_TRASH_DAYS` is zero), because WordPress would then delete the post at once. A post that is missing, in the trash, or unreadable gets the same message.
- `steadhand-restore-post` takes `id`. It restores a post that this agent trashed with `steadhand-trash-post`, and only while that trash is the current one. Any other post in the trash, including one an administrator trashed, one that was moved out of the trash by a status update and trashed again, or one that was restored and trashed again, gets the same refusal as a missing one. A restored post whose stored status is unclear comes back as a draft. A live post comes back as a draft unless the agent holds `steadhand_publish`, and `warnings` says so. A restore to a draft or pending status also needs `steadhand_write_drafts`, and the refusal says so when it is missing. The output gives `status`, the status the post has now, and `previous_status`. It is not undoable with `steadhand/undo-change`.
- `steadhand-update-menu` takes `kind` (`classic`), `menu` and `op`: `add`, `update`, `hide`, `show` or `move`. `add` takes `type` (`post` with `post_id`, or `custom` with `url` and `title`) and an optional `parent`, and puts the item last. `update` takes `item` and `title`. `hide` and `show` take `item`. `move` takes `item`, `parent` (0 for the top level) and `after` (the sibling to follow, or 0 to come first); the item's children move with it, and up to 50 items may change order. It needs `steadhand_menus`, `steadhand_read` and `steadhand_publish`. A change is live at once. It never deletes: `hide` takes an item off the public menu, and `show` brings back an item Steadhand hid. Saving the menu in Appearance > Menus also shows a hidden item again. An empty `title` on a link to a post means the item shows the post's title. It refuses block navigation, an item with a visible child (for `hide`), a post that is not published, a URL that is not absolute `http` or `https`, a title with markup, entities other than `&amp;` or invisible characters, a rename of an item whose present title it could not put back, more than 4 levels and more than 500 items. Read the menu right before a change: the tool does not compare with an earlier read. Its changes cannot be undone with `steadhand-undo-change`; each operation has an opposite call.
- `steadhand-update-option` takes `option`, `value` and optionally `expected`. `option` is `blogname` (the site title) or `blogdescription` (the tagline), unless the site allows others. `value` is the text as a person reads it (`Joe's Pizza`, `A & B`), on one line, within the option's length limit (200 characters for the title, 500 for the tagline). The title cannot be empty; an empty tagline is allowed. A value that looks serialized, or an entity written as text, is refused. `expected` is the value the agent read, and the call is refused, with nothing written, when the option has changed since. It needs `steadhand_settings`, `steadhand_read` and `steadhand_publish`. It is refused for any other option, for an option that holds structured data, for an option that is missing, and for one the site overrides in code. It never creates or deletes an option. The output's `previous` is the value before the call, as display text. Its change cannot be undone with `steadhand-undo-change`; make another call with `previous` as `value` to put it back. That works when `previous` passes the same rules. A stored value that holds markup, or an HTML entity other than the five WordPress escapes (such as `&copy;`), cannot be put back through this tool.
- `steadhand-undo-change` takes `id`, the change-log entry ID that `steadhand-get-change-log` returns. It reverses a change made by `update-post`, `edit-blocks`, `set-post-terms` or `set-featured-image`, and for the last two it needs the capability that call needed. It needs `steadhand_read`, and `steadhand_write_drafts` or `steadhand_publish` by the post's status, as `update-post` does. It returns `entry_id`, `object_type`, `object_id`, `undone` (true), `link`, `warnings` and `guardrail_notes`. Another agent's entry and a missing ID get the same refusal. It refuses, with nothing changed, an entry that is not undoable or not from one of those four tools, an entry already undone, a post whose title, content or excerpt no longer hold the text the change left or that has a revision created after the change (`steadhand_refused_changed_since`), a change with no saved state it can restore, or revisions the site no longer keeps (`steadhand_refused_not_undoable`), a page template the theme no longer has (`steadhand_refused_invalid_template`), a trashed post, and a post the agent may not change now. For post text it sends only the title, content and excerpt; for terms and the featured image it sends only the terms or the image. The undo itself cannot be undone.

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

### After you update Steadhand, reconnect the client

An MCP client reads the list of tools, with their input and output schemas, when it connects. Many clients keep that list for as long as the connection or the session lasts. After Steadhand is updated, a client that connected earlier still holds the old list:

- It does not see tools the update added.
- It may reject a tool's result. Steadhand's output schemas are strict, so when an update adds a field to a tool's output, a client that checks results against the old schema refuses the call. In Claude Code the message is "Structured content does not match the tool's output schema".

Nothing is wrong on the site. The client rejects the result after the site has answered, so a write tool that fails this way has already made its change. Check the change log before you run it again. Reconnect the Steadhand server in the client, or start a new session, and the client reads the current list.

## Builds and releases

Build a release zip:

```
bin/build.sh <dev|wporg> [version]
```

The optional version must match the `Version:` header in `steadhand.php`, with or without a leading `v`. A release must also bump `Stable tag` in `readme.txt` to the same version; the build fails if the two differ.

Each build writes one zip into `build/`:

- `build/steadhand-dev-X.Y.Z.zip`: includes the dev-only updater in `includes/Dev/`. For internal sites only.
- `build/steadhand-X.Y.Z.zip`: the WordPress.org build. It has no `includes/Dev/` folder and no update-checker code.

Both builds leave out the files listed in `.distignore`. The version comes from the `Version:` header in `steadhand.php`, which must match `STEADHAND_VERSION`. A build that fails at any step leaves no zip behind.

The build stages the files git tracks, plus untracked files that git does not ignore. A new file counts before its first commit, so commit or remove stray files before you build. Ignored files such as `vendor/` and `build/` are not staged from your working tree; the build creates the autoloader itself.

The build needs these tools on the `PATH`: `git`, `rsync`, `zip`, `unzip`, `composer` and `php`. The gate needs `find`, `grep`, `head`, `od`, `tr`, `iconv`, `wc` and `unzip`. The gate self-test (`bin/test-wporg-gate.sh`) also needs `zip`, `python3` and `iconv`.

`bin/check-wporg-build.sh` is the release gate. It fails if a WordPress.org build contains update-checker code, dev-only folders, dev and test tooling, or anything that cannot be read as plain source. CI runs it on every build. `bin/test-wporg-gate.sh` plants each known way of smuggling the updater into the WordPress.org build and checks that the gate catches it. CI runs that too.

To release, tag a commit on `main` with `vX.Y.Z`, for example `v0.1.0`. The `release.yml` workflow checks that the tagged commit is on `main`, builds and gates both zips, and creates a GitHub Release with both zips attached. The tag must match the `Version:` header and `STEADHAND_VERSION` in `steadhand.php`. Versions follow [Semantic Versioning](https://semver.org/). There is no WordPress.org deploy yet. That lands in milestone M4.

## Dev builds on internal sites

The dev zip includes an updater. It checks the latest GitHub release of this repository and installs the dev zip from that release's assets. The repository is public, so the updater needs no token. It is off until you turn it on. Until then the update library is not loaded and nothing is hooked or printed.

Turn it on for each internal site by adding this to `wp-config.php`, above the line that says to stop editing:

```php
define( 'STEADHAND_DEV_UPDATES', true );
```

The value must be the boolean `true`. `1`, `'true'` and any other value leave the updater off.

How the updater handles the download:

- It installs only the `steadhand-dev-X.Y.Z.zip` release asset, from `https://github.com/makr-holdings/steadhand/releases/download/vX.Y.Z/`. The version in the release tag and in the zip's name must be the same, or the release is skipped. When the plugin header at that tag can be read, its version must match too. It never installs GitHub's source archive, which has no `vendor/` folder. A release without the asset is skipped.
- It sends no credentials. GitHub limits requests without a token to 60 an hour for each IP address. On a server that shares its address with other sites, an update check can fail for that reason. A pending update is then hidden until a check succeeds. Checks run every 12 hours, and **Check for updates** on the Plugins screen runs one at once.

Know this risk before you turn it on:

- Anyone with write access to this repository can publish a release, by pushing a tag or uploading an asset by hand, and so run code on every site that uses the dev build. The zip is not signed. Keep write access limited.

### Sites that still have a token

Before the repository was public, the updater needed a GitHub token in the `STEADHAND_DEV_GITHUB_TOKEN` constant. A site that defines it, and does not set `STEADHAND_DEV_UPDATES` to `true`, keeps updating as before: the updater stays on and sends the token to this repository's GitHub API. When the token expires or is revoked, GitHub refuses those requests and updates stop.

The token is no longer needed. On each such site:

1. Add `define( 'STEADHAND_DEV_UPDATES', true );` to `wp-config.php`. From then on the updater sends no token, even while the token constant is still defined.
2. Remove the `STEADHAND_DEV_GITHUB_TOKEN` line.
3. Revoke the token on GitHub.

While the token constant is still defined, every plugin and theme on the site, and anyone who can read `wp-config.php` or its backups, can read the token. The updater reads it only from that constant, and never stores it in the database, writes it to a log or displays it.

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
- Drops the change-log table, `{prefix}steadhand_log`, and the snapshots table, `{prefix}steadhand_snapshots`, and removes the options `steadhand_db_version` and `steadhand_log_last_prune`.
- Deletes the Application Passwords of users whose only role was `steadhand_agent`. Those passwords exist only for agent access. Such a user is left with no role.
- Removes the four trash marker keys from the posts of the current site, when the autoloader is present. They are not removed on other sites of a network, because multisite is not supported.

Users are never deleted. A user who also holds another role keeps their Application Passwords and loses only the agent role and the Steadhand capabilities.

Deactivating Steadhand, as opposed to uninstalling it, keeps the role, its capabilities, the Application Passwords and the change-log table. Steadhand registers no deactivation hook.

`uninstall.php` loads the Composer autoloader before it does anything. In a source checkout without `vendor/`, uninstall does nothing: the role, its grants, the change-log table and the trash markers remain. Release zips always include `vendor/`.

## Contributing and security

- Work on a branch from `main` and open a pull request. The maintainer reviews and merges every pull request. Do not push to `main`.
- Coding agents and contributors should read [CLAUDE.md](CLAUDE.md) and [docs/SPEC.md](docs/SPEC.md) before they start. Extension points are listed in [docs/HOOKS.md](docs/HOOKS.md).
- To report a security problem, follow [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability.

Steadhand is licensed under the GPL-2.0-or-later. See [LICENSE](LICENSE).
