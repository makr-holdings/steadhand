=== Steadhand – Secure AI Agent Access for WordPress ===
Contributors: makrholdings
Tags: mcp, ai, security, agents, audit-log
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets AI agents work on your WordPress site over MCP, with least-privilege agent users, guardrails on writes and a change log with undo.

== Description ==

**Steadhand is pre-release.** It has been tested only on its maintainers' own sites and has had no outside security review. Do not rely on it on a production site yet.

Steadhand is an MCP server that lets an AI agent work on your WordPress site without an administrator's account. Every write is checked before it runs and recorded in a change log, and changes to post text, terms and featured images can be undone.

= Least-privilege agent users =

Each agent is a user whose only role is the agent role. An administrator turns on only the capabilities the agent needs. The Agents screen gives each agent up to 10 connections (Application Passwords). Each is named in the change log and revocable alone. An agent cannot sign in with a password, and its credentials work only at the Steadhand endpoint.

= Guardrails on every write =

Built-in checks refuse a write before it runs, with a message the agent can act on, when:

* the content is over 256 KB, has more than 2,000 blocks, nests deeper than 20 levels, is not valid UTF-8 or has malformed block comments
* the agent makes more than 30 writes in a minute (the default), or more than 100 uploads in a day
* another user has the post open in the block editor

Block comments are checked for structure only. No check rewrites content.

= A change log with undo =

Every write, and every refused or failed call that reaches Steadhand's checks, is logged with the time, the connection name, the tool, the post ID, the result and the input. Secrets are masked where possible. A call that WordPress rejects as malformed before Steadhand's checks run is not logged. An administrator with no agent role can undo a change from its log entry. An agent can undo its own change to post text, terms or a featured image with steadhand-undo-change. An undo is refused if the content has changed since. Undoing a text change needs the site to keep WordPress revisions, and works only for the newest change to that post.

= What an agent can do =

Read:

* Site details and what the agent may do
* Posts and pages, except the trash, with their blocks and rendered HTML
* Terms, and menus
* Media library items
* Diagnostics, such as recent PHP errors
* The agent's own change log entries

Write:

* Create posts and pages, as drafts unless the agent may publish
* Edit drafts of any author, and published posts when the agent may publish
* Edit individual blocks of a post, by path
* Set a post's terms, from terms that already exist
* Upload a JPEG or PNG image and set a featured image, with the media capability. Uploads are public at once.
* Undo its own change to post text, terms or a featured image
* Move a post or page to the trash and restore one it trashed, with the trash capability. It cannot delete permanently.
* Add, rename, move, hide and show items of a classic menu, with the menus and publish capabilities. It cannot delete an item or a menu, or change where a theme shows a menu.
* Change the site title and the tagline, with the settings and publish capabilities. It cannot change any other option, create an option or delete one.

= What an agent can never do while Steadhand is active =

* Use the REST API, XML-RPC or wp-admin. Its credentials work only at the Steadhand endpoint.
* Sign in with a password.
* Manage users, roles or plugins, change a setting other than the site title and tagline, delete a post or page permanently, or run PHP, SQL or shell commands. No tool does these.
* Publish, schedule or make content private, unless an administrator turns on the publish capability for it. It is off by default.
* Change a menu, unless an administrator turns on the menus capability and the publish capability. Both are off by default. A menu change is live on the public site at once.
* Change the site title or tagline, unless an administrator turns on the settings capability and the publish capability. Both are off by default. The change is live on every page at once.
* Trash a post or page, or restore one, unless an administrator turns on the trash capability. It is off by default. WordPress itself deletes a trashed post for good after the site's trash period.
* Connect with any account that holds a role besides the agent role, such as an administrator.

= Requirements =

WordPress 6.9 or later, PHP 8.1 or later, the MCP Adapter plugin, and HTTPS, which Application Passwords need.

Steadhand makes no calls to AI providers and sends no telemetry.

== Installation ==

1. Install and activate the MCP Adapter plugin.
2. Install and activate Steadhand. WordPress will not activate it until the MCP Adapter is active. If the adapter is missing later, Steadhand shows a notice and offers no tools.
3. In wp-admin, go to Steadhand, then Agents, and choose Add agent. Enter a username, tick the capabilities the agent may use, and enter a connection name for its first Application Password. The connection name appears in the change log. Use a new user for the agent, not an administrator account.
4. Copy the Application Password when it is shown, with the connection command printed below it. It is shown once and cannot be shown again. If it is lost, revoke it and add another on the Agents screen.
5. In your MCP client, add a server at https://your-site.example/wp-json/steadhand/mcp, using your site's address. Use HTTP Basic authentication, with the agent's username and the Application Password.
6. Ask the client to call steadhand-get-site-info. It returns the site name, its address and what the agent may do, which confirms the connection.

== Frequently Asked Questions ==

= Why does an agent need its own user? =

A WordPress user is the unit that Steadhand grants, logs and revokes. Each agent has its own capabilities, connections and entries in the change log.

= Can I connect with my administrator account? =

No. Only a user whose only role is the agent role can connect. Create an agent on the Agents screen instead. Do not give the agent role to a person's account, because that account's Application Passwords then stop working everywhere.

= What can an agent change by default? =

Drafts. A new agent can read content, create drafts, edit drafts of any author and set their terms. It cannot publish until an administrator turns on the publish capability, and it cannot upload images or set featured images until an administrator turns on the media capability. It cannot change menus, settings, users or plugins, and no tool deletes a post or page. Trashing a post or page, or restoring one it trashed, needs the trash capability, which is off until an administrator turns it on. Restoring a draft also needs the drafts capability. A sticky post, the front page and the posts page cannot be trashed.

= Can an agent publish? =

Not by default. An administrator can turn on the publish capability for one agent. The agent can then publish drafts, schedule posts, make posts private and edit published posts. The Agents screen warns that this changes the public site.

= How does undo work? =

The agent calls steadhand-undo-change with the entry ID, which steadhand-get-change-log returns. Four kinds of change can be undone: post text changed by steadhand-update-post or steadhand-edit-blocks, terms changed by steadhand-set-post-terms, and featured images changed by steadhand-set-featured-image. The earlier state is restored only if the content still matches the state the change left. Otherwise the undo is refused and nothing changes. A post text change that also sets status, slug or date cannot be undone. Creating a post and uploading an image cannot be undone yet.

= How long is the change log kept? =

Thirty days by default. Old entries are removed when new entries are written, in batches, so a site that is rarely written to can keep them longer.

= A tool stopped working after I updated Steadhand. What should I do? =

Reconnect the Steadhand server in your MCP client, or start a new session. A client reads the list of tools and their schemas when it connects, and many clients keep that list until they reconnect. After an update, a client that connected earlier does not see new tools, and it may reject a result that has a field the old schema does not list. Nothing is wrong on the site. If a write tool fails this way, the change was already made, so check the change log before you run it again.

= What happens when I deactivate Steadhand? =

The access rules stop while Steadhand is inactive. Agent users and their Application Passwords remain, and their Application Passwords work as ordinary low-privilege credentials on the REST API and XML-RPC until they are revoked. To take a site out of service, disable the agents on the Agents screen while Steadhand is active, which revokes their connections, or uninstall Steadhand. Uninstalling removes the agent role and the change log, and revokes the Application Passwords of accounts whose only role was the agent role. It never deletes users.

= Does it work on multisite? =

No, not in this version. Steadhand supports single sites only. On a network, the Agents screen refuses every change and shows a notice. Network activation has not been tested.

= Does Steadhand send my content to an AI service? =

No. Steadhand never calls an AI provider and stores no AI keys. Content leaves the site only when an agent reads it through its MCP client, and what the client does with it depends on that client.

== Changelog ==

= 0.4.0 =
* New tools: steadhand-trash-post moves a post or page to the trash, and steadhand-restore-post restores one that the same agent trashed. Both need the trash capability, which is off by default. Neither deletes permanently.
* The Agents screen offers the trash capability.
* A sticky post, the front page, the posts page and the privacy policy page cannot be trashed. Trashing is refused on a site that deletes trashed posts at once.
* The description opens with a pre-release warning.

= 0.3.2 =
* An agent can be disabled, enabled again, or deleted on its page. Disabling revokes every connection and keeps the user, its drafts and its change log entries. Deleting is offered only when no content is attributed to the agent.
* An agent's page shows how to connect an MCP client, and a new connection's password comes with the finished command for Claude Code.
* The list of agents shows each agent's status.
* Creating an agent with Connect unticked is refused, and so is adding a connection to a disabled agent.

= 0.3.1 =
* The Agents screen has a list of agents, a page for each agent, and an add form.
* Creating an agent sets its capabilities on the form.
* Revoking a connection, or saving an agent's capabilities, returns to that agent's page.

= 0.3.0 =
* An Agents screen under Steadhand, to create agents, manage their connections and set their capabilities.
* A change log screen under Steadhand, with filters and a view of each entry's change.
* Undo for changes to post text, terms and featured images, for agents (steadhand-undo-change) and for administrators (an Undo button on the entry).
* Changes to terms and featured images are now recorded so that they can be undone.

= 0.2.1 =
* Security fix: an error raised during a permission check, including one raised by another plugin, no longer reaches the agent. The call is refused.
* Refusals over MCP now end with an error code, such as steadhand_forbidden.
* Fixed: the refusal for a non-draft status sent without publish permission now says that nothing was saved.
* Fixed: new drafts have a modified date in the create-post and update-post output.

= 0.2.0 =
* Write tools: create posts, update posts, edit blocks, set post terms, upload images and set featured images.
* A change log of every refused, failed and written call, and a tool that returns an agent's own entries.
* Guardrails on every write: content size and nesting, block comment structure, write and upload rate limits, and an edit lock for posts open in the block editor.
* Post text changes save a WordPress revision of the earlier text.

= 0.1.0 =
* First build, for internal testing. It is read-only: agents connect with Application Passwords and read posts, blocks, rendered content, terms, menus, media and diagnostics.
* Agent users with their own role. Agent credentials are refused everywhere except the Steadhand endpoint.

The full changelog is in CHANGELOG.md in the plugin folder.

== Upgrade Notice ==

= 0.4.0 =
Adds trash and restore tools for agents, behind a capability that is off by default.

= 0.3.2 =
Adds disable, enable and delete for agents, and connection instructions on the Agents screen.

= 0.3.1 =
The Agents screen is reworked into a list, a page for each agent and an add form. Creating an agent now sets its capabilities on the form.
