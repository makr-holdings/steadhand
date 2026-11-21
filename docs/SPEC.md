# Steadhand — Product & Technical Spec

**Status:** Draft v0.1 (2026-11-21)
**Owner:** MAKR Holdings LLC
**Repo:** `makr-holdings/steadhand`: the free plugin, private during development, made public at the WordPress.org launch. Pro (M5) will be a separate add-on plugin in its own private repo; see §9.1.

> Display name: **Steadhand – Secure AI Agent Access for WordPress**
> Slug / text domain: `steadhand` · PHP namespace: `Steadhand\` · Prefix: `steadhand_` / `STEADHAND_`

---

## 1. What Steadhand is

Steadhand lets AI agents (Claude, ChatGPT, Cursor, any MCP client) work on a WordPress site **safely**: each agent gets only the permissions it needs, known-dangerous writes are blocked or fixed before they land, and every action is logged and can be undone.

It is **not** an AI plugin. Steadhand never calls an LLM, stores no AI provider keys, and adds no chatbot or content generator. It is an access and governance layer. The AI lives in the client.

### Why it exists
- Existing WordPress MCP plugins tend to give an agent a single, never-expiring token with **admin** rights. One leaked token means full site compromise.
- Agents keep making the same site-breaking mistakes: writing JSON into PHP-serialized options, using color slugs that break block validation, overwriting widget and sidebar options. Today the only defense is instructions in a prompt file. Steadhand enforces them in code.
- Site owners can't currently see which agent did what, or easily roll it back.

### Positioning
"The safe one." Built by a security consultancy and used on its own sites first. Least privilege, guardrails and an audit trail are the product, not add-ons.

## 2. Goals and non-goals

**Goals (v1)**
1. Expose a focused, well-described set of WordPress tools over MCP, built on core's Abilities API and the official MCP Adapter.
2. Least privilege by default: a dedicated agent user and role, per-tool-group capabilities, nothing admin-level unless deliberately granted.
3. Enforce guardrails against known site-breaking writes.
4. Log every agent action with enough detail to audit it and undo it.
5. Pass WordPress.org review and Plugin Check on the first submission.

**Non-goals (v1)**
- Generating content, images or embeddings, or calling any AI provider.
- Installing, activating or updating plugins or themes; editing theme or plugin files; running PHP or SQL; managing users. These may never ship, and if they do, it will be Pro-only behind approvals.
- Replacing a full security plugin (firewall, malware scanning, login hardening).
- WooCommerce-specific tools (possible later add-on).

## 3. Architecture

```
MCP client (Claude, etc.)
   │  HTTPS + WordPress Application Password (Basic auth)
   ▼
MCP Adapter (official plugin): transport, protocol, errors
   │  custom server "steadhand" (explicit tool list)
   ▼
Steadhand
   ├─ Transport gate: the request user must hold `steadhand_connect`
   ├─ Abilities (steadhand/*): schema-validated input → permission check → guardrails → execute → log
   ├─ Guardrail engine: rules evaluated before any write
   ├─ Change log + snapshots: audit trail and undo
   └─ Admin UI: agents, permissions, guardrails, log
```

### 3.1 Dependencies
- **WordPress 6.9+** (Abilities API in core). Target: tested against the current release (7.1.x).
- **MCP Adapter, required as a separate plugin** (decided, D1). Declared with the `Requires Plugins:` header so WordPress installs and enforces it. Not bundled, which avoids version conflicts with other plugins that bundle it.
- **PHP 8.1+** (decided, D2).

### 3.2 MCP server
- Steadhand registers its **own** server on `mcp_adapter_init` using `$adapter->create_server(...)`, with an **explicit tool list**. Agents see each tool directly in `tools/list` with its full schema, with no discover/execute indirection through the default server.
- Server ID `steadhand`, REST namespace `steadhand`, route `mcp`, so the endpoint is `/wp-json/steadhand/mcp`.
- The **transport permission callback** requires `current_user_can( 'steadhand_connect' )`. Admins do *not* get this capability automatically. Only users deliberately set up as agents connect.
- Steadhand's abilities are **not** marked `meta.public = true`, so they don't leak into the adapter's default server or other consumers. They are exposed only through the Steadhand server. (Verify during M1 that this works as expected.)

### 3.3 Abilities
- Category `steadhand`, registered on `wp_abilities_api_categories_init`. Abilities are registered on `wp_abilities_api_init`. (If the category isn't registered, the ability fails *silently* when `WP_DEBUG` is off. Add a self-check in the admin UI.)
- Naming: `steadhand/<verb>-<noun>`, e.g. `steadhand/get-post`, `steadhand/update-post`.
- Each ability declares: `label`, a **description written for an LLM** (what it does, when to use it, what it will refuse), `input_schema`, `output_schema`, `permission_callback`, `execute_callback`, and MCP annotations (`readonly`, `destructive`, `idempotent`).
- Every `execute_callback` runs through one shared pipeline: **validate → authorize → guardrails → snapshot → execute → log**. No ability writes to the database outside that pipeline.

## 4. Identity, authentication, permissions

### 4.1 Agent users
- Admins create **agent users** from Steadhand's admin screen. Each gets the custom role `steadhand_agent`, which has *no* native WordPress capabilities beyond `read`, plus Steadhand capabilities.
- Agents authenticate with **WordPress core Application Passwords**: no custom token scheme, revocable per password, and stored hashed by core.
- The **application password's name is the agent label** ("Claude Desktop – Rick", "Ops agent", and so on). It appears in the log, so you can tell agents apart even under the same user.
- Agent users cannot log in to wp-admin interactively (block password login for the role).
- Optional IP allowlist per agent user: in Free, off by default (decided, D3).

### 4.2 Capabilities (tool groups)
Each tool belongs to a group. Each group is a capability the admin toggles per agent user:

| Capability | Tools | Default for a new agent |
|---|---|---|
| `steadhand_connect` | can reach the server at all | on |
| `steadhand_read` | site info, posts/pages, blocks, terms, menus, media lists, diagnostics | on |
| `steadhand_write_drafts` | create and update **drafts**, edit blocks on drafts | on |
| `steadhand_publish` | publish, update published content | **off** |
| `steadhand_media` | upload media, set featured image, edit alt/captions | off |
| `steadhand_menus` | edit navigation menus | off |
| `steadhand_trash` | move to trash and restore (never permanent delete) | off |
| `steadhand_settings` | read/write **allowlisted** options only (site title, tagline, and similar) | off |

Each ability's `permission_callback` checks **both** the Steadhand capability **and** the native WordPress check for the specific object (e.g. `edit_post` for *this* post ID), carried out by mapping meta capabilities for the `steadhand_agent` role. Defense in depth: neither check alone is enough.

## 5. Tool catalog (v1)

Read (`readonly: true`):
- `get-site-info`: name, URL, WordPress/PHP versions, active theme, Steadhand version, what this agent is allowed to do.
- `list-posts` / `get-post`: any public post type, with filters (status, type, search, date), paged.
- `get-blocks`: parsed block tree of a post.
- `get-rendered`: rendered HTML of a post (for checking output).
- `list-terms` / `get-post-terms`
- `list-menus` / `get-menu`
- `list-media` / `get-media`
- `get-diagnostics`: environment, recent PHP errors (if a debug log exists), overdue cron, Site Health issues.
- `get-change-log`: this site's Steadhand log (agents can see what's been done).

Write:
- `create-post`: always creates a **draft** unless the agent has `steadhand_publish` and asks to publish.
- `update-post`: title, content, excerpt, slug, status, meta (meta keys allowlisted).
- `edit-blocks`: targeted block edits (by path/index) rather than full content replacement.
- `set-post-terms`
- `upload-media`: from a base64 payload or an https URL (SSRF-safe: no private IP ranges, size/type limits, using core's sideload functions).
- `set-featured-image`
- `update-menu`
- `trash-post` / `restore-post`
- `update-option`: only options on the allowlist; everything else is refused with an explanation.
- `undo-change`: reverse a logged change by ID (only changes this agent can see and that are still undoable).

Explicitly **not** in v1: user create/update/delete, plugin/theme management, file editing, arbitrary option writes, raw SQL, permanent delete, comments (deferred to v1.1).

## 6. Guardrail engine

Runs on every write, before execution. Each rule has a **mode**: `block` (refuse, with a clear message the agent can act on), `fix` (rewrite input safely, then log what changed), or `warn` (allow, but flag it in the log and the response).

### 6.1 Built-in rules (Free, always on)
| Rule | Mode | Why |
|---|---|---|
| Never write `widget_block`, `sidebars_widgets`, `theme_mods_*` | block | JSON-vs-serialized corruption; site-wide fatal errors |
| Never write options a plugin stores as PHP-serialized arrays (detected by reading the existing value) | block | The plugin silently ignores the value, or corrupts it |
| Option writes limited to the allowlist | block | Arbitrary options can break sites or escalate privileges |
| Block markup must re-validate (parse → serialize round-trip; block validation check) | block | Prevents "This block contains unexpected content" damage |
| Post content size and nesting limits | block | Runaway or looping agents |
| Rate limit per agent (writes/minute) | block | Runaway agents |
| Never touch `wp_users`, roles, capabilities, `active_plugins`, `siteurl`/`home` | block (hard-coded, not configurable) | Privilege escalation and takeover |

### 6.2 Presets (Free, toggleable)
- **Color slugs → hex** (`fix`): converts named color slugs in block attributes (e.g. `"backgroundColor":"white"`) to explicit hex `style` values. This catches a known cause of block validation errors with some themes.
- **Kadence preset:** block `theme_mods_kadence`; refuse unsupported attributes such as column `border.radius`.
- **Rank Math preset:** block writes to `rank-math-options-*`; route SEO meta through per-post meta keys only.

### 6.3 Custom rules (Pro)
Admin-defined rules: protected posts/pages (e.g. "never modify the homepage or legal pages"), option/meta allow and block lists, content rules (regex/blocklist), per-agent overrides, and scheduled "read-only windows".

## 7. Change log and undo

- Custom table `{prefix}steadhand_log`: `id`, `created_at`, `user_id`, `agent_label` (application password name), `ability`, `object_type`, `object_id`, `input` (secrets redacted, size-capped), `result` (ok / blocked / fixed / error), `guardrail_notes`, `snapshot_id`, `undoable`, `undone_by`.
- Snapshots: posts use **core revisions** (store the revision ID); menus, terms, options and meta store a compact before-state in `{prefix}steadhand_snapshots`.
- **Undo** restores the snapshot if the object hasn't changed since (otherwise it refuses and explains, like AI Engine's "edited again since").
- Admin screen: filterable log (agent, ability, result, date), single-change view with a diff, undo button.
- Retention: Free keeps 30 days; Pro is configurable, with CSV/JSON export.
- Logging must not fail open: if the log write fails, the change is rolled back or not executed.

## 8. Approvals (Pro)

- Per capability or per guardrail rule: "requires approval".
- The agent's call returns `pending` with an approval ID and a clear message. The admin approves or rejects in wp-admin (optionally with an email notification containing a signed link). The agent can poll with `get-approval-status`.
- Approved actions run through the same pipeline at approval time, with guardrails re-checked.

## 9. Free vs. Pro

| Feature | Free | Pro |
|---|---|---|
| MCP server, all v1 tools | ✓ | ✓ |
| Agent users, capability groups, Application Password auth | ✓ | ✓ |
| Built-in guardrails + presets | ✓ | ✓ |
| Change log (30 days) + undo | ✓ | ✓ |
| IP allowlist per agent | ✓ | ✓ |
| Custom guardrail rules, protected content | | ✓ |
| Approvals workflow | | ✓ |
| Log retention, export, email/webhook alerts | | ✓ |
| Multi-site dashboard (manage several sites' agents and logs) | | ✓ (later) |

Principle: **Free must be genuinely safe on its own.** Pro adds control and convenience, never the basic safety.

### 9.1 How Free and Pro relate (development model)
- **Pro is an add-on plugin, never a copy.** WordPress.org doesn't allow locked or trialware features in hosted plugins, so no Pro code ever ships inside Free. Pro declares `Requires Plugins: steadhand` and a minimum Free version, and contains only Pro features.
- **Free provides the extension points Pro plugs into:** tool registration, guardrail rule registration, a pre-execute interception point (for approvals), log retention/export filters, and admin screen slots. Free features reach Pro automatically because Pro runs on top of Free, so nothing is written twice.
- **The extension points are a public API.** They are documented in `docs/HOOKS.md`, covered by tests in Free, and never changed or removed without a deprecation period and a changelog entry. This is what keeps Pro from breaking, and third-party developers can build on them too.
- **Repo plan:** one repo now (this one). At M5, Pro gets its own private repo whose CI installs the latest Free and runs Pro's tests against it, so any Free change that breaks Pro is caught immediately. Revisit if that becomes painful.

### 9.2 Positioning and go-to-market (notes)
- **Free is the marketing:** WordPress.org listing, search, reviews, trust. It must fully solve "let my AI agent work on my site safely."
- **Pro buyer = someone accountable** for what agents do: agencies, multi-person businesses, client work. The pitch is control and accountability (approvals, custom rules, audit history and export, multi-site).
- **Upsell rules:** one "Steadhand Pro" admin page, plus prompts only where Pro solves the problem the user just hit (log retention limit, a blocked action that a custom rule could handle). No dashboard banners, no countdown sales. Check against the current WordPress.org guidelines before M4.
- **Pricing (tentative):** annual license per site count (1 / 5 / unlimited).
- **Credibility:** "built by a security consultancy and run on its own sites." Pro can be bundled into MAKR security engagements.
- **Channels:** WordPress.org, the MAKR blog (real "how AI agents break WordPress" stories), MCP server directories and registries, and AI client communities.

## 10. Security requirements (non-negotiable)

- Every REST/ability entry point: capability check → schema validation → sanitization. Every output escaped by context.
- All SQL through `$wpdb->prepare()`; no dynamic table or column names from input.
- Admin UI: nonces on every form and action; `manage_options` required to manage agents and guardrails.
- No secrets in logs (redact any field matching password/token/key/secret patterns).
- SSRF protection on any URL fetch (`wp_safe_remote_get`, block private/reserved ranges, size and type limits).
- No `eval`, no dynamic includes, no shell calls, no unserialize of untrusted input.
- Uninstall: remove our tables, options and roles (`uninstall.php`), leaving no orphans.
- `SECURITY.md` with a disclosure policy; GitHub private vulnerability reporting enabled; security fixes released within 7 days of a confirmed report.
- Before each release: PHPCS (WordPress Coding Standards + security sniffs), Plugin Check, the test suite, and a MAKR pentest pass against a staging site (auth bypass, capability escalation, guardrail bypass, injection, SSRF).

## 11. Distribution and updates

- **Free:** WordPress.org. Tag a GitHub release → GitHub Action (`10up/action-wordpress-plugin-deploy`) pushes to SVN.
- **Before WordPress.org approval (dogfooding), decided (D4):** builds include Plugin Update Checker, pointed at this repo's GitHub releases, so makrholdings.com and rickearley.me auto-update during development and internal testing. It lives in a single dev-only file (`includes/Dev/Updater.php`) that the **WordPress.org build script excludes**, and CI fails if it appears in the WordPress.org zip. (WordPress.org doesn't allow external updaters.)
- **Pro:** licensing/update service TBD (Freemius, Lemon Squeezy, or similar). *Open decision D5.*

## 12. Development workflow

- Branches: `main` is always releasable; feature branches → PR → review → merge. Nobody pushes to `main` directly (enforce with branch protection once the repo is public).
- Local dev: `@wordpress/env` (wp-env), with the MCP Adapter installed in the environment.
- CI (GitHub Actions; the EX44 self-hosted runner can take private-repo jobs): PHPCS, PHPUnit (integration tests via wp-env), Plugin Check, building the release zip.
- Issues: GitHub Issue Forms (bug, feature, guardrail request). Security reports go to private vulnerability reporting only.
- Coding agent: the Claude Code GitHub Action runs **only** on issues or PRs where a maintainer applied the `agent-ready` label. It works on a branch, opens a PR, and cannot merge. Issue text is untrusted input.

## 13. Milestones

| # | Milestone | Done when |
|---|---|---|
| M0 | Scaffold | Plugin boots, category and server registered, CI green, `CLAUDE.md` in place |
| M1 | Read-only | Agent user + Application Password connects from Claude; all read tools work; installed on rickearley.me |
| M2 | Safe writes | Draft/update/blocks/terms/media tools; guardrail engine with built-ins; change log |
| M3 | Undo + admin UI | Log screen, undo, agent management UI; **AI Engine MCP turned off on both MAKR sites** |
| M4 | WordPress.org | Readme, assets, Plugin Check clean, pentest pass, submitted; repo made public |
| M5 | Pro | Approvals, custom rules, retention/export, licensing |

## 14. Decisions

| ID | Question | Status |
|---|---|---|
| D1 | Require MCP Adapter as a plugin vs. bundle via Composer | **Decided:** require as a plugin |
| D2 | Minimum PHP | **Decided:** 8.1+ |
| D3 | IP allowlist in Free | **Decided:** yes, off by default |
| D4 | Private auto-updates during dogfooding | **Decided:** Plugin Update Checker in a dev-only file, excluded from the WordPress.org build |
| D5 | Pro licensing service | Open, decide at M4 |
| D6 | Comments tools in v1 or v1.1 | v1.1 |
| D7 | Multisite (network) support in v1 | Single-site only in v1; network-activate untested |
