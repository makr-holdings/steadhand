# Steadhand extension points

**Status:** Steadhand defines three filters and one action so far (see [Filters](#filters) and [Actions](#actions)). This page is the reference for the public extension points that Steadhand Pro and third-party code will build on. It fills in as each extension point is built. The plan is in [SPEC.md](SPEC.md), section 9.1.

## Current extension points

Steadhand defines three filters, documented in [Filters](#filters), and one action, documented in [Actions](#actions).

To check, run this from the repository root. It lists Steadhand's own hooks: the filters `steadhand_allowed_post_types`, `steadhand_log_read_calls` and `steadhand_log_retention_days`, and the action `steadhand_log_entry_stored`. It also lists the WordPress hook that Steadhand invokes, `the_content`, which `get-rendered` runs on purpose. Today the output has five lines: one for each of the four Steadhand hooks, and one for `the_content`. Any other `apply_filters()` or `do_action()` call in this output is a mistake, or a new extension point that needs a section here:

```
grep -rnE "apply_filters\(|do_action\(" includes steadhand.php uninstall.php --exclude-dir=vendor
```

## Stability policy

These rules apply to every extension point listed here once it exists:

- An extension point is public API. Steadhand Pro and third-party code may depend on it.
- Each one is covered by tests in Steadhand.
- Never rename, remove or change the signature of one without a deprecation. That means a `_deprecated_hook()` call, a [CHANGELOG.md](../CHANGELOG.md) entry and a version note on this page.
- New parameters go at the end of the signature only.

## Filters

### `steadhand_allowed_post_types`

Chooses which post types Steadhand's post tools may use. It is applied by `Steadhand\PostTypes::allowed()`.

- **Since:** 0.1.0.
- **Type:** filter.
- **Parameter:** `$post_types` (`string[]`). The default is `array( 'post', 'page' )`.
- **Return:** `string[]`, a list of post type names. Whatever the filter returns, Steadhand checks it before use:
  - A value that is not an array falls back to the default list.
  - A value that is not a string, is not unchanged by `sanitize_key()`, or is not a registered post type is dropped. Nothing is reported and no warning is raised.
  - Duplicates are removed.
- **Always removed:** `attachment`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block`, `wp_global_styles`, `nav_menu_item` and `revision`. In addition, every built-in WordPress post type other than `post` and `page` is removed: every type registered with `_builtin` true, such as `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`, `wp_font_family` and `wp_font_face`, and any built-in type added by a future WordPress release. These are removed even when the filter adds them, so no filter can bring them back. Media is handled by the media tools, and the rest are site-building and internal types. A custom post type that is not built in can still be added (see the example below).
- **Taxonomies follow from it:** the term tools read a taxonomy only when it is public and attached to a type on this list (and is not one of the fixed exclusions in SPEC section 5). So this filter also decides which taxonomies agents may read. There is no separate filter for taxonomies.
- **What it does not do:** adding a type only makes it eligible. A call still needs the Steadhand capability for the tool and the object-level checks, and the status rules in SPEC section 14.1 still apply.

The filter is evaluated on every call, so a type must be registered before agents use it. This example adds a custom post type named `portfolio`:

```php
add_action(
	'init',
	static function (): void {
		register_post_type( 'portfolio', array( 'public' => true, 'label' => 'Portfolio' ) );
	}
);

add_filter(
	'steadhand_allowed_post_types',
	static function ( array $post_types ): array {
		return array_merge( $post_types, array( 'portfolio' ) );
	}
);
```

This filter is public API. The [stability policy](#stability-policy) above applies: it will not be renamed, removed or changed in signature without a deprecation notice and a [CHANGELOG.md](../CHANGELOG.md) entry. Any new parameter will go at the end.

### `steadhand_log_read_calls`

Turns on change-log entries for successful calls to read-only abilities that need `steadhand_read`. Those calls are not logged by default. It is applied in `Steadhand\Pipeline\Pipeline::should_log()`.

- **Since:** 0.2.0.
- **Type:** filter.
- **Parameters:**
  - `$log` (`bool`), the current decision. The default is `false`.
  - `$ability_name` (`string`), the ability that was called, for example `steadhand/get-post`.
- **Return:** `bool`. Only `true`, by strict comparison, turns logging on. `1`, `'1'`, `'yes'`, `null` and every other value leave the call unlogged.
- **When it applies:** only to a call that succeeded, whose ability is read-only and whose capability is `steadhand_read`. Both must hold. Refused calls and failed calls are always logged, whatever this filter returns. Successful calls to any other ability are always logged too.
- **If a callback throws:** the value counts as `false` and the call is not affected. An exception stops the callbacks on this filter that come after the one that threw, so they do not run.
- **What it does not do:** it does not skip the input redaction or the 16 KB input cap, which apply to every entry that is stored. It does not change the refusal throttle. If the entry for a read call cannot be stored, the call returns `steadhand_log_failed` instead of its data. It is called once for each successful read call, so keep the callback cheap.

The filter is evaluated on every successful read call. This example logs every successful call to `steadhand/get-post`:

```php
add_filter(
	'steadhand_log_read_calls',
	static function ( bool $log, string $ability_name ): bool {
		return 'steadhand/get-post' === $ability_name ? true : $log;
	},
	10,
	2
);
```

This filter is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_log_retention_days`

Sets how many days change-log entries are kept. It is applied in `Steadhand\Log\DbLogger` when pruning runs.

- **Since:** 0.2.0.
- **Type:** filter.
- **Parameter:** `$days` (`int`). The default is 30.
- **Return:** `int`, a whole number of days. Steadhand checks it before use:
  - A string made only of digits, such as `'14'`, is converted to an integer. Any other string is not used, and the default of 30 applies.
  - A value that is not an integer, or is less than 1, is replaced by 30. This covers zero, negative numbers, floats, booleans, `null` and arrays. A filter therefore cannot make entries expire at once, and it cannot turn pruning off by returning 0.
  - A value above 3650 is reduced to 3650, which is ten years. Entries cannot be kept longer than that through this filter.
  - If a callback throws, 30 is used.
- **When it applies:** pruning runs at most once a day, and only as part of storing an entry. It deletes entries older than the number of days returned, in batches of 1,000 rows, up to 20 batches per pass. A pass that runs out of batches is continued by the next write. The batch size and the batch count cannot be changed by a filter. A new value takes effect at the next prune run, so entries older than the new value are not removed until then. While no entry is being stored, no pruning happens at all.
- **What it does not do:** it does not schedule anything with WP-Cron, and it does not change which calls are logged. It does not delete entries on any other event.

This example keeps entries for at least 90 days:

```php
add_filter(
	'steadhand_log_retention_days',
	static fn( int $days ): int => max( $days, 90 )
);
```

This filter is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

## Actions

### `steadhand_log_entry_stored`

Runs after Steadhand has stored a change-log entry. It is applied in `Steadhand\Log\DbLogger`.

- **Since:** 0.2.0.
- **Type:** action.
- **Parameters:**
  - `$id` (`int`), the ID of the new row.
  - `$row` (`array<string, mixed>`), the stored row. Its keys are listed here in the order the code builds them:
    - `id` (`int`): the same value as `$id`. It is put first.
    - `created_at` (`string`): the time the entry was written, in UTC, as `Y-m-d H:i:s`.
    - `user_id` (`int`): the agent user ID, or 0.
    - `agent_label` (`string`): the name of the Application Password that made the call, or `''`.
    - `ability` (`string`): the ability name, for example `steadhand/get-post`.
    - `object_type` (`string`): `post`, `attachment`, or `''` when the call names no object.
    - `object_id` (`int`): the ID the call named, or 0.
    - `input` (`string`): the stored input as JSON text. It is a string, not an array. It is either the redacted input or a summary (`{"truncated":true,"bytes":N,"keys":[...]}`).
    - `result` (`string`): `ok`, `blocked`, `fixed` or `error`.
    - `message` (`string`): the message for a refused or failed call, or `''`.
    - `guardrail_notes` (`string`): always `''` today, because no guardrail runs yet.
    - `snapshot_id` (`int`): always 0 today.
    - `undoable` (`int`): 0 or 1. It is an integer, not a boolean.
    - `undone_by` (`int`): always 0 today.
- **Values:** every string value and every key of the input is already redacted, and every string is size-capped, before it is stored. The listener receives exactly what was stored. The agent label is cut at 191 bytes. The message and guardrail note are cut at 2000 bytes. The input is capped as described above. Redaction is best-effort, so a listener must not assume that a value has no secret in it.
- **When it runs:** once for each row that is inserted, after the insert succeeds. That includes the refusal-throttle marker row. It does not run for an entry that the throttle drops, or for an entry whose insert fails. It runs before the pruning step of the same call.
- **If a listener throws:** the exception is caught and ignored. The entry stays stored, and the call's result is not changed. An exception also stops the listeners on this action that come after the one that threw, so they do not run for that entry.
- **Trust:** listeners are trusted code, and they run inside the agent's request. The exception handling above contains a thrown exception only. Output that a listener prints goes into the agent's response, and a call to `exit` ends the request before the call returns. Neither is contained.
- **What it does not do:** it cannot change or delete the row, change whether the entry is stored, or change the result that the agent receives. It has no return value. It runs synchronously inside the agent's request, so a slow listener makes that call slower.

This example writes a line to the PHP error log for each refused call:

```php
add_action(
	'steadhand_log_entry_stored',
	static function ( int $id, array $row ): void {
		if ( 'blocked' === $row['result'] ) {
			error_log( sprintf( 'Steadhand refused %s (entry %d)', $row['ability'], $id ) );
		}
	},
	10,
	2
);
```

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

## Planned (not implemented)

Nothing in this section exists in the code today. The names and behaviour may change until each one ships.

### Other planned extension points (no hook names yet)

SPEC section 9.1 says Free will provide these extension points for Pro. Their hook names will be set when each one is built:

- Tool registration.
- Guardrail rule registration.
- Pre-execute interception, used for approvals.
- Log export filters.
- Admin screen slots.

## Hooks from WordPress and the MCP Adapter that Steadhand uses

Steadhand attaches to these hooks. Other plugins can see them too.

| Hook | Type | Where | Purpose |
|---|---|---|---|
| `plugins_loaded` | action | `steadhand.php` | Boots Steadhand, or shows the dependency notice |
| `admin_notices` | action | `steadhand.php` (PHP older than 8.1, and missing autoloader); `includes/Plugin.php` (missing dependency) | Shows a notice only when Steadhand cannot run. The `includes/Plugin.php` callback is `Requirements::render_notice()` in `includes/Requirements.php`, which only supplies the callback. Output goes only to users who can activate plugins |
| `wp_abilities_api_categories_init` | action | `includes/Plugin.php` | Registers the `steadhand` ability category |
| `wp_abilities_api_init` | action | `includes/Plugin.php` | Registers the Steadhand abilities |
| `mcp_adapter_init` | action | `includes/Plugin.php` | Registers the `steadhand` MCP server, from the MCP Adapter |
| `authenticate` | filter | `includes/Lockdown.php` (`register()`, called from `includes/Plugin.php` before the requirements check) | Refuses password sign-in by a dedicated agent, and Application Password sign-in by a user with the agent role and another role (XML-RPC), at the last priority (`PHP_INT_MAX`), so core's result is replaced |
| `determine_current_user` | filter | `includes/Lockdown.php` | At `PHP_INT_MAX`. A dedicated agent resolves to no user unless the request is a REST request for the Steadhand endpoint, authenticated by its own Application Password. A user with the agent role and another role resolves to no user when authenticated by its own Application Password |
| `rest_pre_dispatch` | filter | `includes/Lockdown.php` | At `PHP_INT_MIN` (was priority 0), so it runs before other plugins' callbacks on this filter. Refuses every REST route to a blocked user except the Steadhand MCP endpoint |
| `rest_request_before_callbacks` | filter | `includes/Lockdown.php` | New. At `PHP_INT_MAX`. Second check: for every matched REST request, including `/batch/v1` sub-requests, before the route's permission callback and callback. Refuses a blocked user on every route except the Steadhand endpoint |
| `pre_http_request` | filter | `includes/Abilities/GetRendered.php` (`render_guards()`), during `get-rendered` only | Fails every request through the WordPress HTTP API with `steadhand_render_no_http`. Runs at `PHP_INT_MAX - 1`, so it runs after every other callback except one at `PHP_INT_MAX` |
| `pre_oembed_result` | filter | same | Returns `false`, which core reads as "no result, do not fetch", at `PHP_INT_MAX` |
| `embed_oembed_discover` | filter | same | Returns `false`: no link-tag discovery |
| `add_post_metadata`, `update_post_metadata` | filter | same | Return `false` for meta keys that start with `_oembed_`, so no oEmbed cache is written |
| `wp_insert_post_empty_content` | filter | same | Returns `true` for `oembed_cache` posts, so none is inserted or updated |
| `post_password_required` | filter | same | Unlocks only the post being rendered, for the password check |
| `pre_render_block` | filter | same | Omits `core/loginout`, every `core/avatar` (with or without a `userId`, since its `isLink` wrapper would name the user's author archive), and `core/navigation` with a `ref` that is not a published `wp_navigation` post. Other blocks render normally |
| `pre_get_avatar` | filter | same | Returns an empty string, so `get_avatar()` prints nothing, whatever another plugin returns from this filter, at `PHP_INT_MAX` |
| `get_avatar` | filter | same | Returns an empty string, at `PHP_INT_MAX`. The early `pre_get_avatar` return still passes through this filter (`pluggable.php`), so another plugin's markup here would otherwise print |
| `pre_get_avatar_data`, `get_avatar_data` | filter | same | Set the avatar `url` to an empty string and `found_avatar` to `false`, at `PHP_INT_MAX`. `get_avatar_url()` and markup built from `get_avatar_data()` get no Gravatar URL. `get_avatar_data` is the last filter on every path |

The rows above are attached only for the duration of a `get-rendered` call. They are added at the start of the render and removed in a `finally` block, so none remains after the call, including after an error. Core's own callbacks on the same hooks are unchanged. The `pre_http_request`, `pre_oembed_result` and `embed_oembed_discover` rows stop outbound requests. The meta, `oembed_cache` and block rows stop stored data from being written or shown.

Activation also runs Steadhand's install routine through `register_activation_hook()` in `steadhand.php`, which creates the `steadhand_agent` role.

This table was produced with the grep below. It lists the static calls only. The render guards in the table above are attached from a list in `GetRendered::render_guards()`, so their hook names do not appear in the grep output:

```
grep -rnE "add_(action|filter)\(|register_(activation|deactivation|uninstall)_hook" includes steadhand.php uninstall.php --exclude-dir=vendor
```

The dev-only filters in `includes/Dev/Updater.php` are listed below.

## Dev builds only: update library hooks

Dev builds with a token only: `includes/Dev/Updater.php` hooks the library filters `puc_vcs_update_detection_strategies-steadhand` and `puc_request_info_result-steadhand`. The update library also hooks core hooks such as `http_request_args`, `upgrader_pre_download` and `admin_init`. None of these are Steadhand API.

## Hooks other code can use around Steadhand's abilities

Any plugin on the site can hook these, and Steadhand cannot stop it. They run in the same PHP process as Steadhand, so code that hooks them is trusted by necessity.

While `get-rendered` renders a post, the post's shortcodes and blocks, and any filters on `the_content` added by other plugins, run inside a Steadhand call. They see the same read grant the agent has, because the post capability mapping applies while the pipeline runs. The temporary filters in the table above are attached for that time. A callback added at a higher priority than Steadhand's can still undo one of them (for example, a `pre_http_request` callback at `PHP_INT_MAX` that returns `false`), and that is one of the places where code in the same PHP process is trusted.

These are WordPress core hooks. All of them except `wp_before_execute_ability` were added in WordPress 7.1, so they do not exist on 6.9. `wp_before_execute_ability` exists on 6.9, and its `$ability` parameter was added in 7.1.

- `wp_ability_permission_result` (filter, 7.1+) can deny a call that Steadhand allowed. A forced grant is refused again by Steadhand's pipeline, which re-authorizes before it executes.
- `wp_pre_execute_ability` (filter, 7.1+) can return its own result, which skips Steadhand's pipeline, including its log entry.
- `wp_ability_execute_result` (filter, 7.1+) and `wp_ability_validate_output` (filter, 7.1+) can change what the agent receives after Steadhand has logged the call.
- `wp_ability_invoked` (action, 7.1+) and `wp_before_execute_ability` (action) see the agent's raw input.

These are REST filters that Steadhand's lockdown uses. The lockdown answers them, so other code can change its result only at the same priority or later:

- `rest_pre_dispatch` (filter, core): Steadhand's first check runs at `PHP_INT_MIN`, before any other callback here. A callback that runs after it can cancel that check by returning null or dropping the refusal. The second check still refuses the request, because it runs later (next item).
- `rest_request_before_callbacks` (filter, core): Steadhand's second check runs at `PHP_INT_MAX`. A callback at a lower priority runs before it and cannot lift it. A callback added at `PHP_INT_MAX` after Steadhand has booted runs after it and can undo it. This was checked with a test probe: a null-returning callback at that priority turned the refused request into a 200. Steadhand cannot prevent that, and it is one of the places where code running in the same PHP process is trusted.

These are MCP Adapter filters, which run on the adapter's tool-call path:

- `mcp_adapter_pre_tool_call` can rewrite the tool arguments after the permission check. Steadhand validates them again.
- `mcp_adapter_tool_call_result` can change the result before the response is sent.

These hooks belong in the pentest scope (SPEC section 10).
