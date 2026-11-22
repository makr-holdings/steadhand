# Steadhand extension points

**Status: stub.** Steadhand defines no actions or filters yet. This page is the reference for the public extension points that Steadhand Pro and third-party code will build on. It fills in as each extension point is built. The plan is in [SPEC.md](SPEC.md), section 9.1.

## Current extension points

Steadhand defines no actions or filters yet.

To check, run this from the repository root. It should print nothing:

```
grep -rnE "apply_filters\(|do_action\(" includes steadhand.php uninstall.php --exclude-dir=vendor
```

## Stability policy

These rules apply to every extension point listed here once it exists:

- An extension point is public API. Steadhand Pro and third-party code may depend on it.
- Each one is covered by tests in Steadhand.
- Never rename, remove or change the signature of one without a deprecation. That means a `_deprecated_hook()` call, a [CHANGELOG.md](../CHANGELOG.md) entry and a version note on this page.
- New parameters go at the end of the signature only.

## Planned (not implemented)

Nothing in this section exists in the code today. The names and behaviour may change until each one ships.

### `steadhand_allowed_post_types` (filter, planned for milestone M1)

Planned in SPEC sections 5 and 14.1. It would let a site owner or developer change which post types agents may read and write through the post tools.

- Default: `post` and `page`.
- Fixed exclusions. These can never be added through the filter, whatever the filter returns: `attachment`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block`, `wp_global_styles`, `nav_menu_item` and `revision`. Media is handled by the media tools.

### Other planned extension points (no hook names yet)

SPEC section 9.1 says Free will provide these extension points for Pro. Their hook names will be set when each one is built:

- Tool registration.
- Guardrail rule registration.
- Pre-execute interception, used for approvals.
- Log retention and export filters.
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

Activation also runs Steadhand's install routine through `register_activation_hook()` in `steadhand.php`, which creates the `steadhand_agent` role.

This table was produced with:

```
grep -rnE "add_(action|filter)\(|register_(activation|deactivation|uninstall)_hook" includes steadhand.php uninstall.php --exclude-dir=vendor
```

The dev-only filters in `includes/Dev/Updater.php` are listed below.

## Dev builds only: update library hooks

Dev builds with a token only: `includes/Dev/Updater.php` hooks the library filters `puc_vcs_update_detection_strategies-steadhand` and `puc_request_info_result-steadhand`. The update library also hooks core hooks such as `http_request_args`, `upgrader_pre_download` and `admin_init`. None of these are Steadhand API.

## Hooks other code can use around Steadhand's abilities

Any plugin on the site can hook these, and Steadhand cannot stop it. They run in the same PHP process as Steadhand, so code that hooks them is trusted by necessity.

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
