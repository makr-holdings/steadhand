#!/usr/bin/env bash
#
# Create a Steadhand agent user and an Application Password on the local wp-env
# development site (SPEC §13.1 item 4).
#
# LOCAL DEVELOPMENT ONLY. Never run this against a real site. The password is
# printed once, to this terminal. It is not saved anywhere by this script.
#
# Usage: bin/create-agent.sh [username] [label]
#   username  default: steadhand-agent  (1-60 characters: a-z 0-9 _ -, starting with a letter or digit)
#   label     default: Local agent      (1-60 characters, starting with a letter or digit; shown in the
#                                        user's Application Passwords list)
#
# The endpoint is the site's own siteurl (wp-env picks the port), read from the running site.
#
# Requires: the wp-env site running (npx wp-env start), npx, base64.

set -euo pipefail

readonly DEFAULT_USERNAME='steadhand-agent'
readonly DEFAULT_LABEL='Local agent'
# A leading "-" would be read by WP-CLI as an option, so both values must start with a letter or digit.
readonly USERNAME_PATTERN='^[a-z0-9][a-z0-9_-]{0,59}$'
readonly LABEL_PATTERN='^[A-Za-z0-9]'
readonly ROLE='steadhand_agent'

username="${1:-$DEFAULT_USERNAME}"
label="${2:-$DEFAULT_LABEL}"

if [[ ! "$username" =~ $USERNAME_PATTERN ]]; then
	echo "Invalid username '${username}'. Use 1-60 characters: a-z, 0-9, _ and -, starting with a letter or digit." >&2
	exit 1
fi

if [[ -z "$label" || ! "$label" =~ $LABEL_PATTERN || "$label" == *$'\n'* || ${#label} -gt 60 ]]; then
	echo "Invalid label '${label}'. Use 1-60 characters on one line, starting with a letter or digit." >&2
	exit 1
fi

for tool in npx base64; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "Required command not found: ${tool}" >&2
		exit 1
	fi
done

# Run from the repository root, where .wp-env.json lives.
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Arguments go to wp-env as separate argv entries (no shell), so spaces in the label are safe.
wp_cli() {
	npx wp-env run cli wp "$@"
}

# 1. The plugin must be active. Activation creates the steadhand_agent role.
if ! wp_cli plugin is-active steadhand >/dev/null 2>&1; then
	echo "Activating the steadhand plugin..."
	wp_cli plugin activate steadhand >/dev/null
fi

if ! wp_cli role exists "$ROLE" >/dev/null 2>&1; then
	echo "Role '${ROLE}' does not exist after activation. Check the plugin's activation hook." >&2
	exit 1
fi

# 2. The endpoint. wp-env chooses the port (8888 unless it is busy), so ask the site for its address
#    instead of assuming one. Only a local http address is accepted: this script never talks to a real site.
if ! site_output="$(wp_cli option get siteurl)"; then
	echo "Could not read the site address from the wp-env site. Is it running? Try: npx wp-env start" >&2
	exit 1
fi
siteurl="$(printf '%s\n' "$site_output" | tr -d '\r' | grep -E '^https?://[^[:space:]]+$' | tail -n 1 || true)"
if [[ -z "$siteurl" ]]; then
	echo "The wp-env site did not report a site address (siteurl)." >&2
	exit 1
fi
if [[ ! "$siteurl" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$ ]]; then
	echo "The site address '${siteurl}' is not a local http://localhost address. This script runs only against the local wp-env site." >&2
	exit 1
fi
ENDPOINT="${siteurl%/}/wp-json/steadhand/mcp"

# 3. The agent user. Create it if missing. Reuse it only if it holds exactly the agent role.
email="${username}@example.test"

if wp_cli user get "$username" --field=ID >/dev/null 2>&1; then
	roles="$(wp_cli user get "$username" --field=roles | tr -d '\r')"
	if [[ "$roles" != "$ROLE" ]]; then
		echo "User '${username}' has role(s) '${roles}', not '${ROLE}'. Refusing to create a password for it." >&2
		exit 1
	fi
	echo "Using existing agent user '${username}'."
else
	# No password is passed here: wp-env echoes the full command line to the terminal, so
	# a --user_pass argument would be printed. WP-CLI generates a random password instead,
	# and its stdout is discarded. Agents authenticate with the Application Password below.
	wp_cli user create "$username" "$email" --role="$ROLE" >/dev/null
	echo "Created agent user '${username}'."
fi

# 4. The Application Password. --porcelain prints the bare password on stdout. wp-env's own
#    status lines are filtered out by matching the 24-character alphanumeric format.
app_output="$(wp_cli user application-password create "$username" "$label" --porcelain)"
password="$(printf '%s\n' "$app_output" | grep -E '^[A-Za-z0-9]{24}$' | tail -n 1 || true)"
unset app_output

if [[ -z "$password" ]]; then
	echo "Could not read the Application Password from WP-CLI output. No password was printed." >&2
	exit 1
fi

basic_token="$(printf '%s' "${username}:${password}" | base64 | tr -d '\n')"

cat <<OUTPUT

Steadhand agent ready (local development site only).

  MCP endpoint:         ${ENDPOINT}
  Username:             ${username}
  Application password: ${password}

This password is shown once and is not saved by this script. Copy it now.
Do not use this agent or password on a real site.

Connect Claude Code with HTTP Basic auth:

  WARNING: this command stores the credential in plaintext, in your shell history and in
  the MCP client's config file. Base64 is an encoding, not encryption: anyone who can read
  either file has the password. Use it only for this local development site, and revoke
  the Application Password when you are done with it.

  claude mcp add --transport http steadhand ${ENDPOINT} --header "Authorization: Basic ${basic_token}"

Any other MCP client needs this header on every request (the same warning applies):

  Authorization: Basic ${basic_token}
OUTPUT
