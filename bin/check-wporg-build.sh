#!/usr/bin/env bash
# Fails if a WordPress.org build contains dev-only updater code, dev/test tooling, or
# anything that cannot be read as plain source. This is the release gate (SPEC D10).
#
# Usage: bin/check-wporg-build.sh <path-to-zip-or-directory>
#
# A zip is checked before extraction: its entries must be exactly one top-level folder,
# steadhand/, with no absolute paths, no .. parts, no backslashes and no symbolic links.
# It is then extracted to a temp dir (removed on exit) and the whole extraction is scanned.
# A directory is scanned in place. Exit 0 when clean; exit 1 with one line per problem.
#
# Checks, in order:
#   1. Layout (zip only): see above.
#   2. Tree: no symbolic links, no special files, every file and folder readable,
#      no hidden files or folders.
#   3. Files: only the extensions in ALLOWED_EXTENSIONS (plus LICENSE with no extension);
#      no archives by name or by content; allowed files contain no NUL byte and are valid UTF-8.
#   4. Names and locations of dev-only updater files and dev/test tooling.
#   5. Content: every pattern in CONTENT_PATTERNS is searched with grep -a in every file.
#      The top-level steadhand.php may contain the lines in EXEMPT_LINES, each at most once.

set -euo pipefail

# ---------------------------------------------------------------------------
# File types allowed in the WordPress.org build (checked by extension, case-sensitive).
# Exactly what the build contains today. Add .pot/.po/.mo/.css/.js/.png/.jpg/.svg here
# when the plugin ships them, and say why in the same change.
# ---------------------------------------------------------------------------
ALLOWED_EXTENSIONS=(php json md txt)
ALLOWED_NAMES=(LICENSE)

# ---------------------------------------------------------------------------
# Content patterns: a file whose text matches any of these fails the check.
# Matching is case-insensitive extended regex, one pattern per entry. Add new entries here.
# ---------------------------------------------------------------------------
CONTENT_PATTERNS=(
	'plugin-update-checker'
	'YahnisElsts'
	'PucFactory'
	'Puc_v[0-9]'
	'STEADHAND_DEV_GITHUB_TOKEN'
	'STEADHAND_DEV_UPDATES'
	'update_checker'
	'Steadhand\\Dev'
	'includes/Dev'
	# "puc_" as a token: not "public", not a suffix of a longer word such as "hiccup_".
	'(^|[^A-Za-z0-9])puc_'
	'v5p[0-9]'
	'Puc/'
)

# ---------------------------------------------------------------------------
# The only lines allowed to match CONTENT_PATTERNS, and only in the top-level steadhand.php:
# the guarded require of the dev updater (SPEC D4, CLAUDE.md). A line is exempt only when,
# after trailing whitespace and CR are removed, it is byte-for-byte equal to one of these.
# Each may occur once. Any other matching line anywhere fails the check.
# ---------------------------------------------------------------------------
EXEMPT_LINES=(
	"// Dev builds only: the dev updater exists in includes/Dev/ and is absent from the WordPress.org zip."
	"if ( is_readable( STEADHAND_DIR . 'includes/Dev/Updater.php' ) ) {"
	$'\trequire_once STEADHAND_DIR . \'includes/Dev/Updater.php\';'
)

# Dev/test tooling that must not appear at the top level of the plugin.
FORBIDDEN_TOP_LEVEL=(
	'tests'
	'node_modules'
	'.github'
	'bin'
	'phpunit.xml.dist'
	'phpcs.xml.dist'
	'.wp-env.json'
	'composer.lock'
	'vendor/phpunit'
	'vendor/squizlabs'
	'vendor/wp-coding-standards'
)

usage() {
	echo "Usage: bin/check-wporg-build.sh <path-to-zip-or-directory>" >&2
	exit 2
}

die() {
	echo "check-wporg-build: error: $*" >&2
	exit 1
}

[[ $# -eq 1 ]] || usage
target=$1

for tool in find grep head od tr iconv wc; do
	command -v "$tool" >/dev/null 2>&1 || die "$tool is required"
done

tmp=""
cleanup() {
	if [[ -n "$tmp" && -d "$tmp" ]]; then
		rm -rf -- "$tmp"
	fi
	return 0
}
trap cleanup EXIT

problems=()
add_problem() {
	problems+=("$1")
}

finish() {
	if ((${#problems[@]} > 0)); then
		echo "WordPress.org build check FAILED for $target (${#problems[@]} problem(s)):" >&2
		printf '  - %s\n' "${problems[@]}" >&2
		echo "Fix the source or the build, then re-run bin/build.sh wporg. Do not exempt anything without a reason recorded in bin/check-wporg-build.sh." >&2
		exit 1
	fi
	echo "OK: WordPress.org build check passed for $target ($file_count files checked)"
	exit 0
}

# Checks a zip's entry names and symlinks with unzip -Z, before anything is extracted.
check_zip_layout() {
	local zip=$1 names entry part top line
	local has_steadhand_folder=0
	local -a entries parts
	local -A reported_top=()

	names=$(unzip -Z1 "$zip") || die "could not list $zip"
	mapfile -t entries <<<"$names"

	for entry in "${entries[@]}"; do
		[[ -n "$entry" ]] || continue
		if [[ "$entry" == /* ]]; then
			add_problem "absolute path in zip: $entry"
			continue
		fi
		if [[ "$entry" == *\\* ]]; then
			add_problem "backslash in zip entry name: $entry"
			continue
		fi
		IFS=/ read -r -a parts <<<"$entry"
		for part in "${parts[@]}"; do
			if [[ "$part" == .. ]]; then
				add_problem "parent-directory (..) path in zip: $entry"
				break
			fi
		done
		top=${parts[0]:-}
		if [[ "$top" != steadhand ]]; then
			if [[ -z "${reported_top[$top]:-}" ]]; then
				reported_top[$top]=1
				add_problem "unexpected top-level entry in zip: $top (the zip must hold exactly one folder, steadhand/)"
			fi
			continue
		fi
		if [[ "$entry" == steadhand ]]; then
			add_problem "steadhand in the zip is a file, not a folder"
		elif [[ "$entry" == */ || ${#parts[@]} -gt 1 ]]; then
			has_steadhand_folder=1
		fi
	done

	if ((has_steadhand_folder == 0)); then
		add_problem "the zip has no steadhand/ folder"
	fi

	links=$(unzip -Zl "$zip" | grep -E '^l' || true)
	while IFS= read -r line; do
		[[ -n "$line" ]] || continue
		add_problem "symbolic link in zip (not allowed): ${line##* }"
	done <<<"$links"
}

if [[ -f "$target" && "$target" == *.zip ]]; then
	command -v unzip >/dev/null 2>&1 || die "unzip is required to check a zip"
	zip_abs="$(cd -- "$(dirname -- "$target")" && pwd)/$(basename -- "$target")"
	check_zip_layout "$zip_abs"
	if ((${#problems[@]} > 0)); then
		file_count=0
		finish
	fi
	tmp=$(mktemp -d)
	unzip -q "$zip_abs" -d "$tmp" || die "could not extract $target"
	scan=$tmp
	root=$tmp/steadhand
elif [[ -d "$target" ]]; then
	scan=$(cd -- "$target" && pwd)
	root=$scan
else
	usage
fi

# Path of the plugin root relative to the scanned tree ("" or "steadhand"), and the
# name grep reports for the top-level steadhand.php from inside the scanned tree.
root_rel=${root#"$scan"}
root_rel=${root_rel#/}
exempt_rel=${root_rel:+$root_rel/}steadhand.php

# --- Required files ---------------------------------------------------------
[[ -f "$root/steadhand.php" ]] || add_problem "steadhand.php is missing"
[[ -f "$root/vendor/autoload.php" ]] || add_problem "vendor/autoload.php is missing (build it with bin/build.sh wporg)"

# --- Tree: links, special files, readability, hidden entries ----------------
while IFS= read -r p; do
	add_problem "symbolic link (not allowed anywhere in the build): ${p#"$scan"/}"
done < <(find "$scan" -type l)

while IFS= read -r p; do
	add_problem "not a regular file or folder: ${p#"$scan"/}"
done < <(find "$scan" ! -type f ! -type d ! -type l)

while IFS= read -r p; do
	add_problem "not readable: ${p#"$scan"/}"
done < <(find "$scan" \( -type f -o -type d \) ! -readable)

while IFS= read -r p; do
	add_problem "hidden file or folder: ${p#"$scan"/}"
done < <(find "$scan" -mindepth 1 -name '.*')

# --- Dev updater directory and names ----------------------------------------
while IFS= read -r d; do
	add_problem "dev-only directory present: ${d#"$scan"/}"
done < <(find "$scan" -type d -path '*/includes/Dev')

while IFS= read -r p; do
	add_problem "updater-related name: ${p#"$scan"/}"
done < <(find "$scan" \( -iname '*plugin-update-checker*' -o -iname 'puc*' -o -iname 'Updater.php' \))

# --- Dev/test tooling -------------------------------------------------------
for rel in "${FORBIDDEN_TOP_LEVEL[@]}"; do
	if [[ -e "$root/$rel" ]]; then
		add_problem "dev/test tooling present: $rel"
	fi
done

while IFS= read -r p; do
	add_problem "dev/test tooling present: ${p#"$scan"/}"
done < <(find "$scan" -name composer.lock -type f)

# --- File types, archives, encoding -----------------------------------------
while IFS= read -r -d '' f; do
	rel=${f#"$scan"/}
	base=${f##*/}
	lower=${base,,}

	case "$lower" in
	*.zip | *.phar | *.tar* | *.tgz | *.gz | *.bz2 | *.xz | *.7z | *.rar)
		add_problem "archive file (not allowed): $rel"
		continue
		;;
	esac

	allowed=0
	if [[ "$base" == *.* ]]; then
		ext=${base##*.}
		for e in "${ALLOWED_EXTENSIONS[@]}"; do
			[[ "$ext" == "$e" ]] && allowed=1
		done
	else
		for n in "${ALLOWED_NAMES[@]}"; do
			[[ "$base" == "$n" ]] && allowed=1
		done
	fi
	if ((allowed == 0)); then
		add_problem "file type not allowed in the WordPress.org build: $rel (allowed: .php .json .md .txt, and LICENSE)"
		continue
	fi

	# An unreadable file is already reported above.
	[[ -r "$f" ]] || continue

	magic=$(head -c 4 -- "$f" | od -An -tx1 | tr -d ' \n')
	case "$magic" in
	504b03* | 504b05* | 504b07* | 1f8b* | 425a68* | fd377a58* | 377abcaf* | 52617221*)
		add_problem "archive content (not allowed, whatever the file name): $rel"
		;;
	esac

	nuls=$(LC_ALL=C tr -cd '\000' <"$f" | wc -c | tr -d '[:space:]')
	if [[ "$nuls" != 0 ]]; then
		add_problem "NUL byte in $rel (binary or UTF-16 content is not allowed)"
	fi

	if ! iconv -f UTF-8 -t UTF-8 <"$f" >/dev/null 2>&1; then
		add_problem "not valid UTF-8: $rel"
	fi
done < <(find "$scan" -type f -print0)

# --- steadhand.php exemptions -----------------------------------------------
# Pre-pass over the top-level steadhand.php: mark each exempt line by line number.
# The first copy of an exempt line is allowed; a repeat is not.
declare -A exempt_line=() repeat_line=() exempt_used=()
exempt_file="$root/steadhand.php"
if [[ -f "$exempt_file" ]]; then
	lineno=0
	while IFS= read -r line || [[ -n "$line" ]]; do
		lineno=$((lineno + 1))
		norm=${line%"${line##*[![:space:]]}"}
		for i in "${!EXEMPT_LINES[@]}"; do
			if [[ "$norm" == "${EXEMPT_LINES[i]}" ]]; then
				if [[ -n "${exempt_used[$i]:-}" ]]; then
					repeat_line[$lineno]=1
				else
					exempt_used[$i]=1
					exempt_line[$lineno]=1
				fi
				break
			fi
		done
	done <"$exempt_file"
fi

# --- Content ----------------------------------------------------------------
# grep -Z separates the file name from the rest with NUL, which a shell variable cannot
# hold, so the hits go to a temp file. grep -a treats binary files as text.
grep_failed=""
hits_file=$(mktemp)
for pat in "${CONTENT_PATTERNS[@]}"; do
	rc=0
	(cd -- "$scan" && grep -raiEnZ -e "$pat" -- . >"$hits_file" 2>/dev/null) || rc=$?
	if ((rc == 2)); then
		grep_failed="pattern '$pat'"
	fi
	((rc == 0)) || continue

	while IFS= read -r -d '' file && IFS= read -r rest; do
		file=${file#./}
		lineno=${rest%%:*}
		text=${rest#*:}
		if [[ "$file" == "$exempt_rel" && -n "${exempt_line[$lineno]:-}" ]]; then
			continue
		fi
		text=${text#"${text%%[![:space:]]*}"}
		msg="pattern '$pat' in $file:$lineno: ${text:0:160}"
		if [[ "$file" == "$exempt_rel" && -n "${repeat_line[$lineno]:-}" ]]; then
			msg+=" (repeat of an allowed guarded-require line; each may occur once)"
		fi
		add_problem "$msg"
	done <"$hits_file"
done
rm -f -- "$hits_file"

if [[ -n "$grep_failed" ]]; then
	add_problem "content search failed for $grep_failed (grep exit 2: a file could not be read)"
fi

file_count=$(find "$scan" -type f | wc -l | tr -d '[:space:]')
finish
