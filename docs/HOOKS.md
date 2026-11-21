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

Steadhand attaches to these actions. Other plugins can see them too.

| Hook | Type | Where | Purpose |
|---|---|---|---|
| `plugins_loaded` | action | `steadhand.php` | Boots Steadhand, or shows the dependency notice |
| `admin_notices` | action | `steadhand.php` (PHP older than 8.1, and missing autoloader); `includes/Plugin.php` (missing dependency) | Shows a notice only when Steadhand cannot run. The `includes/Plugin.php` callback is `Requirements::render_notice()` in `includes/Requirements.php`, which only supplies the callback. Output goes only to users who can activate plugins |
| `wp_abilities_api_categories_init` | action | `includes/Plugin.php` | Registers the `steadhand` ability category |
| `wp_abilities_api_init` | action | `includes/Plugin.php` | Registers the Steadhand abilities |
| `mcp_adapter_init` | action | `includes/Plugin.php` | Registers the `steadhand` MCP server, from the MCP Adapter |

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

These are MCP Adapter filters, which run on the adapter's tool-call path:

- `mcp_adapter_pre_tool_call` can rewrite the tool arguments after the permission check. Steadhand validates them again.
- `mcp_adapter_tool_call_result` can change the result before the response is sent.

These hooks belong in the pentest scope (SPEC section 10).
