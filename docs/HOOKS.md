# Steadhand extension points

**Status:** Steadhand defines four filters and seven actions so far (see [Filters](#filters) and [Actions](#actions)), and the public guardrail classes in the [Guardrail API](#guardrail-api) section. This page is the reference for the public extension points that Steadhand Pro and third-party code will build on. It fills in as each extension point is built. The plan is in [SPEC.md](SPEC.md), section 9.1.

## Current extension points

Steadhand defines four filters, documented in [Filters](#filters), and seven actions, documented in [Actions](#actions). The [Guardrail API](#guardrail-api) section documents the public classes that third-party guardrail rules use.

To check, run this from the repository root. It lists Steadhand's own hooks: the filters `steadhand_allowed_post_types`, `steadhand_log_read_calls`, `steadhand_log_retention_days` and `steadhand_rate_limit_per_minute`, and the actions `steadhand_admin_agent_row`, `steadhand_admin_log_entry`, `steadhand_admin_log_toolbar`, `steadhand_agent_capabilities_changed`, `steadhand_agent_created`, `steadhand_agent_deleted`, `steadhand_agent_disabled`, `steadhand_agent_enabled`, `steadhand_log_entry_stored` and `steadhand_register_guardrails`. It also lists three WordPress hooks that Steadhand calls rather than attaches to: `the_content`, which `get-rendered` runs on purpose, and `wp_check_post_lock_window`, which the edit-lock rule reads, and `image_memory_limit`, which the media tools read. Today the output has seventeen lines: one for each of the fourteen Steadhand hooks (four filters and ten actions), and one for each of the three WordPress hooks. Any other `apply_filters()` or `do_action()` call in this output is a mistake, or a new extension point that needs a section here:

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
- **Narrowed in the unreleased version:** a post type registered with `map_meta_cap` off is removed whatever the filter returns, so the filter can no longer add one. Core makes none of its per-post checks for such a type, and some of its capabilities can be renamed to one the agent holds. The filter's signature is unchanged. WordPress turns `map_meta_cap` off by default when a type sets `capabilities`, or a `capability_type` other than `post` or `page`; register such a type with `'map_meta_cap' => true` to make it allowable. A dropped type is not reported. See [CHANGELOG.md](../CHANGELOG.md) and SPEC section 14.1.
- **Always removed:** `attachment`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block`, `wp_global_styles`, `nav_menu_item` and `revision`. Every type registered with `map_meta_cap` off is removed too, as described above. In addition, every built-in WordPress post type other than `post` and `page` is removed: every type registered with `_builtin` true, such as `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`, `wp_font_family` and `wp_font_face`, and any built-in type added by a future WordPress release. These are removed even when the filter adds them, so no filter can bring them back. Media is handled by the media tools, and the rest are site-building and internal types. A custom post type that is not built in can still be added (see the example below).
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
- **When it applies:** pruning runs at most once a day, and only as part of storing an entry. It deletes entries older than the number of days returned (and, in the same pass, the saved states in `{prefix}steadhand_snapshots` that are that old), in batches of 1,000 rows, up to 20 batches per pass. A pass that runs out of batches is continued by the next write. The batch size and the batch count cannot be changed by a filter. A new value takes effect at the next prune run, so entries older than the new value are not removed until then. While no entry is being stored, no pruning happens at all.
- **What it does not do:** it does not schedule anything with WP-Cron, and it does not change which calls are logged. It does not delete entries on any other event.

This example keeps entries for at least 90 days:

```php
add_filter(
	'steadhand_log_retention_days',
	static fn( int $days ): int => max( $days, 90 )
);
```

This filter is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_rate_limit_per_minute`

Sets how many writes an agent may make in one rolling minute. It is applied in `Steadhand\Guardrails\Rules\RateLimit` on every write, before the write runs.

- **Since:** 0.2.0.
- **Type:** filter.
- **Parameters:**
  - `$limit` (`int`), the current limit. The default is 30.
  - `$user_id` (`int`), the agent user making the write.
- **Return:** `int`, a whole number from 1 to 600. Steadhand checks it before use:
  - A string made only of digits, such as `'60'`, is converted to an integer.
  - Any other value is replaced by 30, the default. This covers zero, negative numbers, floats, booleans, `null`, arrays and other strings. A value above 600 is not reduced to 600; it also gives 30.
  - If a callback throws, 30 is used.
- **It cannot turn the limit off.** Zero and negative numbers give 30, not no limit.
- **What is counted:** the change-log rows for write abilities (every ability that is not read-only) made by this user, with result `ok`, `fixed` or `error`, in the last 60 seconds. A write counts from the moment its row is reserved, so a write that is still running counts. Refused calls are not counted.

This example allows 60 writes a minute for every agent:

```php
add_filter(
	'steadhand_rate_limit_per_minute',
	static fn( int $limit ): int => 60
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
    - `input` (`string`): the stored input as JSON text. It is a string, not an array. It is either the redacted input or a summary (`{"truncated":true,"bytes":N,"keys":[...]}`). Post content is stored as a digest of the submitted text, not as text (SPEC section 7).
    - `result` (`string`): `ok`, `blocked`, `fixed` or `error`. `fixed` means a guardrail rewrote the input. No built-in rule does that yet.
    - `message` (`string`): the message for a refused or failed call, or `''`.
    - `guardrail_notes` (`string`): the notes the guardrails recorded for a write, joined by newlines, or `''`.
    - `snapshot_id` (`int`): the ID of the snapshot for the call's before state, or 0. For an update it is the newest non-autosave revision matching the post's title, content and excerpt (SPEC section 7). For `set-post-terms` and `set-featured-image` it is a row of `{prefix}steadhand_snapshots`.
    - `undoable` (`int`): 0 or 1. It is an integer, not a boolean.
    - `undone_by` (`int`): 0 until the change is undone; then the change-log ID of the `steadhand/undo-change` call that undid it (SPEC section 7).
    - `after_snapshot_id` (`int`): the ID of the snapshot that holds the post's state after the write, of the same kind as `snapshot_id`, or 0. Only an undoable write has one (SPEC section 7). The row has this key. The order of the keys is not part of the contract, even though the list above follows the order the code builds them.
- **Values:** every string value and every key of the input is already redacted, and every string is size-capped, before it is stored. The listener receives exactly what was stored. The agent label is cut at 191 bytes. The message and guardrail note are cut at 2000 bytes. The input is capped as described above. Redaction is best-effort, so a listener must not assume that a value has no secret in it. For a write, the row is read back from the table once its outcome is recorded, and the integer columns (`id`, `user_id`, `object_id`, `snapshot_id`, `after_snapshot_id`, `undoable` and `undone_by`) are cast to `int` the same way, so the types listed above apply to both. Text columns are strings.
- **When it runs:** once for each row that is stored in one step, after the insert succeeds. That includes the refusal-throttle marker row, and for a stored row it runs before the pruning step of the same call. A write is stored in two steps: its row is reserved before the change, and the listener runs once, when the outcome is recorded and the row changes. The reservation itself does not run it. It does not run for an entry that the throttle drops, or for an entry whose insert fails.
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

### `steadhand_register_guardrails`

Lets a plugin add guardrail rules to the write path. Steadhand runs it once per request, when the first write in that request reaches the guardrails. It is applied in `Steadhand\Guardrails\Engine`.

- **Since:** 0.2.0.
- **Type:** action.
- **Parameter:** `$registry` (`Steadhand\Guardrails\Registry`). Call `$registry->add( $rule )` for each rule. The rule interface and `Registry::add()` are described in the [Guardrail API](#guardrail-api) section.
- **When it runs:** only for a write that reaches the guardrail step. It does not run for reads, for calls refused at the permission check, or at boot. It runs at most once per request, so a listener must be attached before the first write, for example on `init`. A listener attached later in the same request is not called.
- **Order:** rules run in the order they were added, after the built-in rules that run first and before the built-in rules that run last. See [Rules of engagement](#rules-of-engagement).
- **If a listener throws:** the exception is caught. The rules added before the throw still apply, and the listeners on this action after the one that threw do not run for that request. The built-in rules are not affected, so a listener cannot turn them off.
- **What it does not do:** it cannot remove or reorder a built-in rule, and it cannot change a call. A rule changes a call only through its `Result`.
- **Trust:** listeners are trusted code and run inside the agent's request. See [Hooks other code can use](#hooks-other-code-can-use-around-steadhands-abilities).

This example adds a rule that refuses a title written in capitals:

```php
add_action(
	'steadhand_register_guardrails',
	static function ( \Steadhand\Guardrails\Registry $registry ): void {
		$registry->add(
			new class implements \Steadhand\Guardrails\Rule {
				public function id(): string {
					return 'example/no-shouting';
				}

				public function evaluate( \Steadhand\Guardrails\Context $context ): \Steadhand\Guardrails\Result {
					$title = (string) ( $context->input['title'] ?? '' );
					if ( '' !== $title && $title === strtoupper( $title ) && $title !== strtolower( $title ) ) {
						return \Steadhand\Guardrails\Result::block( 'Write the title in normal case, not all capitals.' );
					}

					return \Steadhand\Guardrails\Result::allow();
				}
			}
		);
	}
);
```

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_admin_log_toolbar`

Runs on the list view of the change-log screen (under Steadhand in the admin menu, SPEC section 7), after the filter form. Use it to add controls to the toolbar above the list.

- **Since:** 0.3.0.
- **Type:** action.
- **Parameter:** `$filters` (`array<string, string>`), the validated filters the form shows. The keys are `agent` (a user ID), `ability`, `result`, `from` and `to` (dates as `Y-m-d`, in the site's timezone), `paged` and `entry`. A filter that is not set, or is not valid, is `''`.
- **When it runs:** once for each render of the list view, after the capability check has passed. It runs even when a filter is not valid, in which case the list shows no rows. It does not run on the single-entry view, and not for a viewer who is refused.
- **Who gets it:** only a user with `manage_options` who is not an agent.
- **If a listener throws:** the exception is caught and ignored, so the screen still renders.
- **Trust and output:** listeners are trusted code, and they run inside an administrator's request. Whatever a listener prints appears on the page, so the listener must escape its own output.
- **What it does not do:** it cannot change the rows or the filters the screen shows. It has no return value.

This example prints the agent the list is filtered to:

```php
add_action(
	'steadhand_admin_log_toolbar',
	static function ( array $filters ): void {
		printf(
			'<p>%s</p>',
			esc_html( '' === $filters['agent'] ? 'All agents' : 'Agent ' . $filters['agent'] )
		);
	}
);
```

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_admin_log_entry`

Runs on the single-entry view of the change-log screen, after the entry's stored fields and its input, and before its diff.

- **Since:** 0.3.0.
- **Type:** action.
- **Parameter:** `$entry` (`array<string, mixed>`), the entry as `Steadhand\Log\DbLogger::find_any()` returns it: every column of the change log, with the types that `steadhand_log_entry_stored` gives them. Unlike the list, it includes `input`, which is the stored JSON text.
- **When it runs:** once for each render of an entry that exists, after the capability check has passed. It does not run for an entry that does not exist, nor for a viewer who is refused.
- **Who gets it:** the same viewers as `steadhand_admin_log_toolbar`.
- **If a listener throws:** the exception is caught and ignored, so the screen still renders.
- **Trust and output:** the same as `steadhand_admin_log_toolbar`. The listener must escape what it prints, and `input` is an agent's data.
- **Hidden fields:** the screen hides `message`, `guardrail_notes` and `input` from a viewer who may not edit the post the entry names (SPEC section 7). This action still receives the full row, including those fields, because listeners are trusted code. A listener that prints them must check `edit_post` for that post itself.
- **Undo rows:** an administrator's undo is stored like any other row: `ability` is `steadhand/admin-undo`, `user_id` is the administrator, and `undoable` is 0. It reaches this action when its entry is shown. The entry it reversed shows `undone_by` set to that row's ID.
- **What it does not do:** it cannot change the entry, the diff, or what the screen shows. It has no return value.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_agent_created`

Runs after the Agents screen (SPEC section 4.1) creates an agent and its first Application Password.

- **Since:** 0.3.0.
- **Type:** action.
- **Parameters:** `$user_id` (`int`), the new agent's user ID; `$label` (`string`), the connection name of its first Application Password (the label), which is the agent label in the change log.
- **When it runs:** once for each create that has saved the agent's first Application Password, after the password is made and before the final check. The capabilities chosen on the form are already stored when this action fires: each offered capability was written as true or false, and read back, before the password was made. The final check runs after the action, on the user as it is now. It compares the stored capabilities with the chosen ones, and it must hold the agent role and those capabilities, and nothing else, under the posted login. **The action can fire for an agent that the final check then refuses.** In that case the agent is rolled back (its password is revoked, and the user is deleted or, when content is attributed to it, kept with no role and no password), and the screen shows no password and reports the refusal. A listener therefore cannot assume that a call of this action means the agent exists: a listener that records "created" must allow for the refusal that may follow. The screen shows a password only when the final check passes, so a call of this action without a password on the screen is possible. It does not run when the create is refused earlier.
- **Who gets it:** any code on the site. The action is reached only from the Agents screen, by an administrator who holds no agent role.
- **The password is not passed.** Listeners get the user ID and the label, and nothing else of the secret.
- **The role is not checked.** The final check reads the user's stored capabilities, not what the `steadhand_agent` role grants. A Steadhand capability added to the role does not reach an agent created on the screen with chosen capabilities, because that agent stores an explicit false for each one. It does reach an agent that stores no user-level value: one made by `bin/create-agent.sh`, or by a create request without the capability marker. Any other (non-Steadhand) capability added to the role reaches every agent.
- **If a listener throws:** the exception is caught and ignored, and the final check still runs. The agent exists until that check refuses it.
- **What it does not do:** it cannot change the result or stop the creation, except by changing the user, which the final check then sees. It has no return value.

```php
add_action(
	'steadhand_agent_created',
	static function ( int $user_id, string $label ): void {
		error_log( sprintf( 'Steadhand agent %d created with label %s', $user_id, $label ) );
	},
	10,
	2
);
```

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_agent_capabilities_changed`

Runs after the Agents screen saves an agent's capabilities.

- **Since:** 0.3.0.
- **Type:** action.
- **Parameters:** `$user_id` (`int`), the agent; `$capabilities` (`array<string, bool>`), each offered capability with the value it was saved with. The offered capabilities are the ones the Agents screen shows (`steadhand_connect` and every capability a registered ability uses). Capabilities that are not offered are not in the array and are not changed.
- **When it runs:** after every offered capability is saved and read back as saved. It does not run when the save is refused or does not read back. It does not run when an agent is created with chosen capabilities.
- **If a listener throws:** the exception is caught and ignored. The capabilities stay saved.
- **What it does not do:** it cannot change the values. It has no return value.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_agent_disabled`

Runs after the Agents screen disables a dedicated agent.

- **Since:** 0.3.2.
- **Type:** action.
- **Parameter:** `$agent` (`WP_User`), the agent, read again after the disable was checked.
- **When it runs:** once, after the agent's stored `steadhand_connect` reads back as false and the agent holds no Application Password. It does not run when the disable is refused or does not read back.
- **If a listener throws:** the exception is caught and ignored. The agent stays disabled.
- **What it does not do:** it cannot change the agent or the result. It has no return value. It does not fire `steadhand_agent_capabilities_changed`, which runs only when the capabilities form is saved.
- **Unticking Connect in the capabilities form does not fire it.** Turning `steadhand_connect` off through that form only stores the value; it revokes nothing and fires `steadhand_agent_capabilities_changed`, not this action. A listener that wants every change of connect must listen to both this action and `steadhand_agent_capabilities_changed`, and to `steadhand_agent_enabled` as well.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_agent_enabled`

Runs after the Agents screen enables a dedicated agent.

- **Since:** 0.3.2.
- **Type:** action.
- **Parameter:** `$agent` (`WP_User`), the agent, read again after the enable was checked.
- **When it runs:** once, after the agent's stored `steadhand_connect` reads back as true. Enabling creates no Application Password, so the agent may hold none when this runs. It does not run when the enable is refused or does not read back.
- **If a listener throws:** the exception is caught and ignored. The agent stays enabled.
- **What it does not do:** it cannot change the agent or the result. It has no return value.
- **Storing Connect on through the capabilities form does not fire it.** Ticking Connect in that form stores the value and fires `steadhand_agent_capabilities_changed`, not this action. A listener that wants every change of connect must listen to both this action and `steadhand_agent_capabilities_changed`, and to `steadhand_agent_disabled` as well.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_agent_deleted`

Runs after the Agents screen deletes a dedicated agent's user account.

- **Since:** 0.3.2.
- **Type:** action.
- **Parameters:** `$user_id` (`int`), the ID of the deleted user; `$user_login` (`string`), the login the user had. The user no longer exists, so there is no `WP_User` object.
- **When it runs:** once, after a fresh read finds the user gone. It does not run when the delete is refused, or when the user still exists afterwards. A delete is refused before any change when the request is not confirmed or when posts or links are attributed to the agent. It can also be refused after the agent's connections were revoked, when content appears during the delete (`delete_has_content_revoked`) or a role is added (`not_agent_revoked`); then nothing is deleted and this action does not run.
- **What is kept:** Steadhand never deletes content. The change-log entries of the user are kept.
- **If a listener throws:** the exception is caught and ignored. The user stays deleted.
- **What it does not do:** it cannot undo the delete or change the result. It has no return value.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

### `steadhand_admin_agent_row`

Runs on the Agents screen's detail view of one dedicated agent (a user whose only role is `steadhand_agent`), after that agent's sections: its capabilities, its connections and the link to its change-log entries.

- **Since:** 0.3.0.
- **Changed:** 0.3.1. In 0.3.0 it ran once for each dedicated agent in the list. It now runs once, in the detail view of one agent. The name and the parameter are unchanged.
- **Type:** action.
- **Parameter:** `$agent` (`WP_User`), the dedicated agent.
- **When it runs:** once for each render of the detail view of a dedicated agent (`agent=<id>`), after the capability check has passed. That includes the response to a create or an add-password that shows the agent's detail. It does not run in the list view or the add view, for a user who holds the agent role and another role, for an ID that is not a dedicated agent, or for a viewer who is refused.
- **Who gets it:** the same viewers as the screen: an administrator who holds no agent role.
- **If a listener throws:** the exception is caught and ignored, so the screen still renders.
- **Trust and output:** listeners are trusted code, and they run inside an administrator's request. Whatever a listener prints appears inside the agent's detail, after its sections, so the listener must escape its own output.
- **What it does not do:** it cannot change the agent or the screen. It has no return value.

This action is public API. The [stability policy](#stability-policy) above applies. Any new parameter will go at the end.

## Guardrail API

Third-party guardrail rules are written against four classes in `Steadhand\Guardrails`: `Rule`, `Context`, `Result` and `Registry`. They are public API under the [stability policy](#stability-policy). The engine (`Engine`) and the verdict (`Verdict`) are internal and may change.

A rule is added from the [`steadhand_register_guardrails`](#steadhand_register_guardrails) action with `Registry::add()`.

### `Rule`

An interface with two methods.

- `id(): string` returns the rule's ID, in the form `vendor/name`: exactly one slash, lowercase letters, digits and hyphens, at most 40 characters before the slash and 60 after it. An ID must not start with `steadhand/`, which is reserved for the built-in rules.
- `evaluate( Context $context ): Result` checks one call and returns a `Result`. A rule must not change the call. It returns a result, and the engine acts on it. A rule that throws, or that returns anything other than a `Result`, blocks the call.

### `Context`

The call as the rules see it. Every property is public and read-only.

- `ability` (`string`): the ability name: one of the seven write tools, `steadhand/create-post`, `steadhand/update-post`, `steadhand/edit-blocks`, `steadhand/set-post-terms`, `steadhand/upload-media`, `steadhand/set-featured-image` or `steadhand/undo-change`. An undo is also seen as the direct call it amounts to, see [An undo is evaluated twice](#an-undo-is-evaluated-twice).
- `capability` (`string`): the Steadhand capability the ability names. The four post write tools name `steadhand_write_drafts`. A call that edits a live post or sets a live status also needs `steadhand_publish`. The object check decides that, not this property. `upload-media` and `set-featured-image` name `steadhand_media`, and both also need `steadhand_read`.
- `user_id` (`int`): the agent user making the call.
- `input` (`array<string, mixed>`): for the call itself, the validated and sanitized input, as the rules before this one left it. Defaults are filled in, so `create-post` always has `status` and `post_type`. After a fix, it is the rewritten input, already checked again. For the second evaluation of an undo, the input is synthetic and is described under [An undo is evaluated twice](#an-undo-is-evaluated-twice): for post text it holds only `id`, which does not pass the tool's own input rules, so a rule must not assume any other key; for terms and the featured image it is validated against the tool's schema.
  - **Warning, `steadhand/upload-media`:** `input` carries the raw base64 payload, in `data_base64`, as the caller sent it: up to 7,200,000 characters, which decode to at most 5 MB. A rule must not log it, store it, or return it in a message. Log its digest, or nothing. The change log itself stores only the digest of the decoded bytes.
- `object_type` (`string`): `post` for the four post write tools and for `set-featured-image`; `attachment` for `upload-media`, whose object ID is 0 until the file is stored and the new attachment's ID after.
- `object_id` (`int`): the ID of the post the call changes. It is 0 for `create-post`, which has no ID until it runs.
- `content` (`array<string, string>`): the input fields that will be stored as post content, keyed by field name, each holding block markup. For `create-post` and `update-post` it has `content` when the caller sent it, and is empty otherwise. For `edit-blocks` it has `content`, the whole resulting content, when the operations can be applied. When they cannot, it has `operations.<n>.markup` for each operation that sends markup instead. Those keys are not stored, and nothing is written. `set-post-terms` has no content, so its `content` is always empty. Title and excerpt are not included.

### `Result`

A decision. Build one with a named constructor. The constructor is private.

- `Result::allow()`: the call proceeds unchanged.
- `Result::block( string $message )`: the call is refused. The message is shown to the agent. It is cut at 500 bytes, and an empty message is replaced by "Refused by a guardrail."
- `Result::warn( string $note )`: the call proceeds. The note is recorded in the change log and in the response.
- `Result::fix( array $input, string $note )`: the call proceeds with `$input` in place of its input. The engine checks the new input before it is used (see [Rules of engagement](#rules-of-engagement)). The call is recorded with result `fixed`.

Getters:

- `mode()` returns `Result::MODE_ALLOW` (`'allow'`), `Result::MODE_BLOCK` (`'block'`), `Result::MODE_WARN` (`'warn'`) or `Result::MODE_FIX` (`'fix'`).
- `message()` returns the refusal text. It is empty unless the mode is block.
- `note()` returns the note. It is empty unless the mode is warn or fix.
- `input()` returns the rewritten input. It is empty unless the mode is fix.

Each note is cut at 300 bytes, including the rule's ID and `: ` in front of it, and a call keeps at most 20 notes. The engine adds one note for each warn and each fix, and one for a block, in the form `vendor/name: blocked`.

### `Registry`

`add( Rule $rule ): bool` adds a rule and returns `true`. It returns `false`, and adds nothing, when:

- `id()` throws;
- the ID does not match the form described under [`Rule`](#rule);
- the ID starts with `steadhand/`;
- another rule already has that ID.

It does not throw for these cases. Rules run in the order they were added.

### Rules of engagement

These rules apply to every write. The order is fixed:

1. The built-in rules that run first: `steadhand/rate-limit`, then `steadhand/daily-upload-limit`, then `steadhand/edit-lock`.
2. The third-party rules, in the order they were added.
3. The built-in rules that run last: `steadhand/content-limits`, then `steadhand/block-markup`.

- Each rule sees the input as the rules before it left it.
- The two rate rules count the change log before the write's row is reserved, so writes that arrive at the same moment can pass together: they are best effort under concurrent requests.
- A block stops evaluation. The call is refused, and no later rule runs.
- A warn adds its note, and evaluation continues.
- A fix passes the new input through the same checks as the original call: the schema, the ability's own input rule, and the object check. If any of them fails, the call is blocked with "A guardrail changed this call into one that is not allowed, so it was refused." A fix that changes `object_type` or `object_id` is blocked with "A guardrail changed which object this call is about, so it was refused." Otherwise the call continues with the new input, and the rules after it see that input.
- The built-in rules that run last always judge the final input, after every fix.
- A rule that throws, or that returns anything other than a `Result`, blocks the call with "A guardrail could not check this call, so it was refused."
- The block note is always recorded, even when the list of notes is full.
- Rules run only on the write path. Reads and calls refused before the guardrail step never reach them.

### An undo is evaluated twice

A `steadhand/undo-change` call that reverses a change is evaluated twice, and only the rules that pass both evaluations let it run.

1. The rules see the undo itself: `ability` `steadhand/undo-change`, with input `{ id }`, the change-log entry, and no content.
2. When every rule has passed the undo, the rules see the direct call the undo amounts to, with the same user and the same object:
   - a terms change: `steadhand/set-post-terms`, its capability, and the input the undo writes with: `{ id, taxonomy, terms, mode }`, where `terms` are the term IDs the post had before the change and `mode` is `replace`; no content;
   - a featured-image change: `steadhand/set-featured-image`, and the input `{ id, media_id }`, where `media_id` is the image the post had before the change, or 0 when it had none; no content;
   - a post-text change (`update-post` or `edit-blocks`): `steadhand/update-post`, with the input `{ id }` only, and no content. The undo restores the post's own earlier stored text, which is not run through the content rules (`steadhand/content-limits`, `steadhand/block-markup`), so a rule that reads `content` does not see the restored text.

The input of the second evaluation is synthetic. For post text it is `{ id }` only, and it would not pass the tool's own input rules, so a rule must not assume any other key. For terms and the featured image it is the input the restore writes with, validated and sanitized against the tool's schema, the way the pipeline validates a call. Its `content` is always empty.

A block on either evaluation refuses the undo, and the refusal is logged as `blocked` with the message of the rule that blocked it. A rule that would rewrite the direct call refuses the undo too, with the message "A guardrail changed this call into one that is not allowed, so it was refused.", because a rewrite cannot be applied to a restore. A rule that throws refuses the undo as it refuses any call. If the direct call cannot be determined (the entry is not usable, or its saved state cannot be read), the undo is refused the same way, and nothing is written.

The rules may rewrite the undo's own input, `{ id }`, to another entry. That rewrite is authorized again like any other: the second evaluation describes the new entry, and a rewrite to another agent's entry, or to an entry of another post, refuses the undo. Between the second evaluation and the write, the undo checks again that the direct call it would make is the one the rules saw; if it changed, nothing is written.

Every other ability is evaluated once, as before. The rate limit counts stored change-log rows, not evaluations, and the undo's own row is written after both evaluations, so the two evaluations count the same calls, once.

These classes are public API. A change to one of them is a breaking change, and needs a deprecation under the stability policy first.

## Planned (not implemented)

Nothing in this section exists in the code today. The names and behaviour may change until each one ships.

### Other planned extension points (no hook names yet)

SPEC section 9.1 says Free will provide these extension points for Pro. Their hook names will be set when each one is built:

- Tool registration.
- Pre-execute interception, used for approvals.
- Log export filters.

The admin screen slots of the change-log screen are the actions `steadhand_admin_log_toolbar` and `steadhand_admin_log_entry`, documented above. The slots of the agents and guardrails screens come with those screens.

**Tool registration is not public yet.** `Steadhand\Abilities\Ability` is an internal base class. The tool list is a fixed list in `Steadhand\Abilities\Registry::abilities()`, and no filter or action adds a tool, so a third party cannot add an MCP tool today.

## Hooks from WordPress and the MCP Adapter that Steadhand uses

Steadhand attaches to these hooks, or calls them where the row says so. Other plugins can see them too.

| Hook | Type | Where | Purpose |
|---|---|---|---|
| `plugins_loaded` | action | `steadhand.php` | Boots Steadhand, or shows the dependency notice |
| `admin_notices` | action | `steadhand.php` (PHP older than 8.1, and missing autoloader); `includes/Plugin.php` (missing dependency) | Shows a notice only when Steadhand cannot run. The `includes/Plugin.php` callback is `Requirements::render_notice()` in `includes/Requirements.php`, which only supplies the callback. Output goes only to users who can activate plugins |
| `wp_abilities_api_categories_init` | action | `includes/Plugin.php` | Registers the `steadhand` ability category |
| `wp_abilities_api_init` | action | `includes/Plugin.php` | Registers the Steadhand abilities |
| `mcp_adapter_init` | action | `includes/Plugin.php` | Registers the `steadhand` MCP server, from the MCP Adapter |
| `load-{page}` | action | `includes/Admin/Admin.php` (the Agents screen's page hook) | Runs the Agents screen's POST actions, before the page renders, so a new password is shown in the response to the POST that made it |
| `wp_is_application_passwords_available` | filter | called by `includes/Admin/AgentsHandler.php` and `includes/Admin/AgentsScreen.php` | Read only: the Agents screen offers no create or add form when it is false |
| `wp_is_application_passwords_available_for_user` | filter | called by `includes/Admin/AgentsHandler.php` | Read only: an agent that has no Application Passwords available gets no password |
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
| `pre_do_shortcode_tag` | filter | same | Returns `''` for a `gallery` or `playlist` shortcode that would list an attachment the agent may not read through `get-media`, or the children of a post the agent may not read through `get-post`. Any other shortcode is unchanged. Attached at `PHP_INT_MAX`. The check is `GetRendered::media_shortcode_refused()`. A callback another plugin adds later at the same priority on this filter, or on `shortcode_atts_gallery`, `post_gallery` or `post_playlist`, can change what the guard decided. A playlist's track cover images are not checked. |
| `pre_render_block` | filter | same, at `PHP_INT_MAX` | Omits `core/loginout`, every `core/avatar` (with or without a `userId`, since its `isLink` wrapper would name the user's author archive), `core/navigation` with a `ref` that is not a published `wp_navigation` post, every `core/legacy-widget`, `core/breadcrumbs` and `core/post-author-biography`, `core/post-author` with `showBio` or `isLink`, `core/post-author-name` with `isLink`, `core/video` with an `id` that is not a readable attachment, and any block with a `core/post-meta` binding. Navigation links and submenus with a `core/post-data` or `core/term-data` binding are omitted too. Other blocks render normally |
| `render_block` | filter | same, at `PHP_INT_MAX`, 2 arguments | The second layer of the rule above, applied to each block's rendered output. Core's `navigation`, `navigation-submenu`, `navigation-link` and `widget-group` blocks render their inner blocks by calling `render()` directly, which skips `pre_render_block`, so this layer omits a guarded block nested in them. The block's own render code still runs; only its output is replaced with an empty string |
| `pre_get_avatar` | filter | same | Returns an empty string, so `get_avatar()` prints nothing, whatever another plugin returns from this filter, at `PHP_INT_MAX` |
| `get_avatar` | filter | same | Returns an empty string, at `PHP_INT_MAX`. The early `pre_get_avatar` return still passes through this filter (`pluggable.php`), so another plugin's markup here would otherwise print |
| `pre_get_avatar_data`, `get_avatar_data` | filter | same | Set the avatar `url` to an empty string and `found_avatar` to `false`, at `PHP_INT_MAX`. `get_avatar_url()` and markup built from `get_avatar_data()` get no Gravatar URL. `get_avatar_data` is the last filter on every path |
| `user_has_cap` | filter | `includes/CapabilityGuard.php`, registered in `includes/Plugin.php` (`boot()`, before the requirements check), at `PHP_INT_MAX` | Sets every Steadhand capability to the value the user holds, and `steadhand_no_grant` to false, so no other `user_has_cap` filter can grant or remove one. Other capabilities are not changed, so a third party's filter still applies to them and may change core's answers for an administrator |
| `map_meta_cap` | filter | `includes/MetaCaps.php` (`register()`), priority 10 | Maps `read_post` for a dedicated agent during every Steadhand call, and `edit_post` and `publish_post` only during a write call (SPEC §14.1). Any requirement core adds beyond the post type's own capabilities is refused. A post of a type registered with `map_meta_cap` off is refused too. Such a type is never allowed, so the allowlist already refuses it (`steadhand_allowed_post_types`), and this is a second layer. A `do_not_allow` from core or an earlier callback is returned unchanged |
| `admin_post_steadhand_undo_entry` | action | `includes/Admin/Admin.php` (`register()`), handled by `includes/Admin/UndoHandler.php` | The change-log screen's Undo button. Logged-in users only: there is no `nopriv` variant. The handler checks the request is a POST, the entry ID (digits), the nonce for that entry and `manage_options`, then runs the undo through `Pipeline::run_admin()` |
| `admin_init` | action | `includes/Lockdown.php`, priority 1 | Refuses wp-admin, admin-ajax.php and admin-post.php to a dedicated agent with 403 (SPEC §4.1) |
| `posts_clauses` | filter | `includes/Abilities/ListMedia.php` (`list-media` only, during its query) | Adds the media rule's parent condition, a join and a where clause, to that one query. Removed in a `finally` block |
| `pre_http_request` | filter | `includes/Abilities/GetDiagnostics.php` (`execute()`, `get-diagnostics` only) | Fails every HTTP request made during the call with `steadhand_diagnostics_no_http`, at `PHP_INT_MAX - 1`. Removed in a `finally` block |
| `wp_check_post_lock_window` | filter, applied and not attached | `includes/Guardrails/Rules/EditLock.php` | Core's edit-lock window in seconds, default 150. Steadhand reads it on each write that is checked. A value that is not a whole number from 1 to 3600 gives 150 |
| `title_save_pre`, `content_save_pre`, `excerpt_save_pre`, `content_filtered_save_pre` | filter | `includes/Pipeline/PostWriter.php` (`with_filters_added()`; it does not call `kses_init_filters()` or `kses_remove_filters()`) | During a post write, these four filters are in place for the whole write, for every field, as core adds them. Steadhand adds the ones that are missing and removes afterwards only the ones that write added, and never removes one during a write. The callbacks are those core uses: `wp_filter_kses` to `title_save_pre`, `wp_filter_post_kses` to `content_save_pre`, `excerpt_save_pre` and `content_filtered_save_pre`. The custom CSS strip, `wp_strip_custom_css_from_blocks`, is added to `content_save_pre` and `content_filtered_save_pre` at core's priority 8, for every Steadhand write, and only when core has the function. Core adds it only for users without `edit_css`; Steadhand's copy also applies to a user who may edit CSS. Steadhand does not add or remove `wp_filter_global_styles_post` |
| `wp_insert_post_data` | filter | `includes/Pipeline/PostWriter.php` (`save()`, used by `update()` and `splice()`), at `PHP_INT_MAX` with 4 arguments | During one update or splice of one post, and only once: puts back the stored title, content, excerpt and filtered content of that post for each of those fields the write does not send, and for `splice()` the content it assembled, set slashed as core passes `$data`. It acts only when the update flag, the ID and the stored post type all match, and it is removed in `finally`. Steadhand does not offer this as an extension point. A plugin that changes the row after this filter can still alter an unsent field; `update-post` reports that after the write as `steadhand_update_partial` |
| `wp_insert_post_data` | filter | `includes/Pipeline/PostWriter.php` (`with_revision_mirror()`, used by `save()` and by `Snapshots::post_revision()`), at `PHP_INT_MAX` with 4 arguments | While a post is saved, or its revision snapshot is taken: for each revision of that post that is not an autosave, sets the title, content and excerpt to the post's row as stored at that moment, read uncached, slashed as core passes `$data`. Removed in `finally`. Steadhand does not offer this as an extension point. A revision of another post is not changed |

The `get-rendered` rows (`pre_http_request` through `pre_get_avatar_data`, where the Where column names `GetRendered` or says `same`) are attached only for the duration of a `get-rendered` call. They are added at the start of the render and removed in a `finally` block, so none remains after the call, including after an error. Core's own callbacks on the same hooks are unchanged. The `pre_http_request`, `pre_oembed_result` and `embed_oembed_discover` rows stop outbound requests. The meta, `oembed_cache` and block rows stop stored data from being written or shown. The `posts_clauses` and `pre_http_request` rows for `list-media` and `get-diagnostics` are attached and removed the same way, for their one call.

Activation also runs Steadhand's install routine through `register_activation_hook()` in `steadhand.php`, which creates the `steadhand_agent` role.

This table was produced with the grep below. It lists the static calls only. The render guards in the table above are attached from a list in `GetRendered::render_guards()`, so their hook names do not appear in the grep output:

```
grep -rnE "add_(action|filter)\(|register_(activation|deactivation|uninstall)_hook" includes steadhand.php uninstall.php --exclude-dir=vendor
```

The KSES filters in the table are added where they are missing, and removed afterwards only when the write added them, by Steadhand's `with_filters_added()` during a write, so the grep does not show them. The `wp_insert_post_data` filter that the shared save adds for one call (for `update()` or `splice()`) is added and removed the same way. The render guards are attached from a list, as noted above. `wp_check_post_lock_window` is applied rather than attached, so it appears in the first grep output and in [Current extension points](#current-extension-points), not here.

The dev-only filters in `includes/Dev/Updater.php` are listed below.

## Dev builds only: update library hooks

Dev builds with a token only: `includes/Dev/Updater.php` hooks the library filters `puc_vcs_update_detection_strategies-steadhand` and `puc_request_info_result-steadhand`. The update library also hooks core hooks such as `http_request_args`, `upgrader_pre_download` and `admin_init`. None of these are Steadhand API.

## Hooks other code can use around Steadhand's abilities

Any plugin on the site can hook these, and Steadhand cannot stop it. They run in the same PHP process as Steadhand, so code that hooks them is trusted by necessity.

While `get-rendered` renders a post, the post's shortcodes and blocks, and any filters on `the_content` added by other plugins, run inside a Steadhand call. They see the same read grant the agent has, because the post capability mapping applies while the pipeline runs. The temporary filters in the table above are attached for that time. A callback added at a higher priority than Steadhand's can still undo one of them (for example, a `pre_http_request` callback at `PHP_INT_MAX` that returns `false`), and that is one of the places where code in the same PHP process is trusted.

A write runs WordPress's own post functions (`wp_insert_post()` or `wp_update_post()`), so the core save hooks run inside it, among them `wp_insert_post_data` and `save_post`. Listeners on those hooks run in the agent's request and are trusted code. An exception from one of them is caught by the pipeline, which records the call as `error`, but the post may already have been saved.

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
