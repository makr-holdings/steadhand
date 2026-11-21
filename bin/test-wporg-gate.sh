#!/usr/bin/env bash
# Self-test for the WordPress.org gate (bin/check-wporg-build.sh).
#
# Builds the clean WordPress.org zip, then plants each known way of smuggling the dev updater
# into it, one case at a time, and checks that the gate fails with a useful message. Two
# controls must pass: the clean build (as a zip and as a folder) and a harmless look-alike word.
# Exit 0 only when every expectation holds.
#
# Planted material: the dev updater library in includes/Dev/vendor (run
# `composer dev-updater:install` first). Needs zip, unzip, python3 and iconv.
# Cases that need a non-root user (an unreadable file) are skipped when run as root.
#
# Usage: bin/test-wporg-gate.sh

set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
root=$(cd -- "$script_dir/.." && pwd)
gate="$script_dir/check-wporg-build.sh"
lib_dir="$root/includes/Dev/vendor/yahnis-elsts/plugin-update-checker"
lib_parent=$(dirname -- "$lib_dir")

die() {
	echo "test-wporg-gate: error: $*" >&2
	exit 1
}

for tool in zip unzip python3 iconv; do
	command -v "$tool" >/dev/null 2>&1 || die "$tool is required"
done
[[ -d "$lib_dir/Puc" ]] || die "the planted updater library is missing ($lib_dir). Run: composer dev-updater:install"

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

# --- Clean build ------------------------------------------------------------
if ! build_log=$(cd -- "$root" && bash bin/build.sh wporg 2>&1); then
	echo "$build_log" >&2
	die "bin/build.sh wporg failed, so there is no clean zip to test"
fi
shopt -s nullglob
zips=("$root"/build/steadhand-[0-9]*.zip)
shopt -u nullglob
((${#zips[@]} == 1)) || die "expected exactly one WordPress.org zip in build/, found ${#zips[@]}"
clean_zip=${zips[0]}
unzip -q -- "$clean_zip" -d "$work/clean"
clean_tree="$work/clean/steadhand"
[[ -f "$clean_tree/steadhand.php" ]] || die "the clean zip has no steadhand/steadhand.php"

# --- Helpers ----------------------------------------------------------------
pass_count=0
fail_count=0
skip_count=0
run_gate() {
	gate_rc=0
	gate_out=$("$gate" "$1" 2>&1) || gate_rc=$?
}

report() {
	# report <status> <case> <detail>
	printf '%-6s %-30s %s\n' "$1" "$2" "$3"
	case "$1" in
	PASS) pass_count=$((pass_count + 1)) ;;
	FAIL) fail_count=$((fail_count + 1)) ;;
	SKIP) skip_count=$((skip_count + 1)) ;;
	esac
}

# Replaces one exact occurrence of OLD with NEW in FILE; anything else is an error.
edit_file() {
	python3 -c '
import pathlib, sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
p = pathlib.Path(path)
text = p.read_text(encoding="utf-8")
if text.count(old) != 1:
    sys.exit(f"expected exactly one match in {path}, found {text.count(old)}")
p.write_text(text.replace(old, new, 1), encoding="utf-8")
' "$1" "$2" "$3"
}

# Appends one entry with an arbitrary name (including absolute and .. names) to a zip.
add_zip_entry() {
	python3 -c '
import sys, zipfile
zpath, name, data = sys.argv[1:4]
with zipfile.ZipFile(zpath, "a") as z:
    z.writestr(name, data.encode("utf-8"))
' "$1" "$2" "$3"
}

# ---------------------------------------------------------------------------
# Mutators. Each runs in the case folder (the parent of steadhand/) and changes the copy.
# ---------------------------------------------------------------------------
m_nested_library_zip() {
	(cd -- "$lib_parent" && zip -rqX "$PWD_CASE/steadhand/includes/bundle.zip" plugin-update-checker)
}
m_library_as_phar() {
	(cd -- "$lib_parent" && zip -rqX "$PWD_CASE/steadhand/includes/bundle.phar" plugin-update-checker)
}
m_nul_in_comment() {
	printf '\n/* \000 YahnisElsts */\n' >>steadhand/includes/Plugin.php
}
m_utf16_copy() {
	printf '%s' '<?php // YahnisElsts PucFactory' | iconv -f UTF-8 -t UTF-16 >steadhand/includes/Log/Utf16.php
}
m_invalid_utf8() {
	printf '<?php\n// caf\351 YahnisElsts\n' >steadhand/includes/Latin1.php
}
m_unreadable_file() {
	printf '<?php\n// YahnisElsts\n' >steadhand/includes/Hidden.php
	chmod 000 steadhand/includes/Hidden.php
}
m_symlink() {
	ln -s ../vendor/composer/ClassLoader.php steadhand/includes/Helper.php
}
m_extra_top_level() {
	mkdir extra
	printf 'YahnisElsts\n' >extra/notes.txt
}
m_second_statement_on_require_line() {
	edit_file steadhand/steadhand.php \
		"require_once STEADHAND_DIR . 'includes/Dev/Updater.php';" \
		"require_once STEADHAND_DIR . 'includes/Dev/Updater.php'; require_once STEADHAND_DIR . 'includes/Dev/Other.php';"
}
m_second_guarded_require_elsewhere() {
	printf '%s\n' "require_once STEADHAND_DIR . 'includes/Dev/Updater.php';" >>steadhand/includes/Plugin.php
}
m_duplicate_guard_block() {
	tab=$'\t'
	printf '%s\n' \
		"// Dev builds only: the dev updater exists in includes/Dev/ and is absent from the WordPress.org zip." \
		"if ( is_readable( STEADHAND_DIR . 'includes/Dev/Updater.php' ) ) {" \
		"${tab}require_once STEADHAND_DIR . 'includes/Dev/Updater.php';" \
		"}" >>steadhand/steadhand.php
}
m_namespace_reference() {
	printf '%s\n' '$updater = new \Steadhand\Dev\Updater();' >>steadhand/includes/Plugin.php
}
m_puc_prefix_token() {
	printf '%s\n' '// puc_factory helper' >>steadhand/includes/Plugin.php
}
m_puc_path_and_version() {
	printf '%s\n' '// see Puc/v5p7 for the library layout' >>steadhand/includes/Plugin.php
}
c_dev_directory_restored() {
	mkdir -p steadhand/includes/Dev
	cp -a -- "$root/includes/Dev/vendor" steadhand/includes/Dev/
}
c_vendor_autoload_missing() {
	rm -f -- steadhand/vendor/autoload.php
}
c_look_alike_words() {
	printf '%s\n' '// Public API, public_key handling and publication notes.' >>steadhand/includes/Plugin.php
}

# Extra zip entry used by the layout cases (set by the case, read by case_run).
EXTRA_ENTRY_NAME=""
EXTRA_ENTRY_DATA=""
m_absolute_entry() {
	EXTRA_ENTRY_NAME="/steadhand-evil/updater.php"
	EXTRA_ENTRY_DATA='<?php // YahnisElsts'
}
m_parent_entry() {
	EXTRA_ENTRY_NAME="steadhand/../escape.php"
	EXTRA_ENTRY_DATA='<?php // YahnisElsts'
}

# case_run <case name> <zip|dir|pass-dir> <mutator> <expected: PASS|regex> [needs-non-root]
case_run() {
	local name=$1 kind=$2 mutator=$3 expect=$4 needs_user=${5:-0}
	if [[ "$needs_user" == 1 && "$(id -u)" == 0 ]]; then
		report SKIP "$name" "needs a non-root user (root can read every file)"
		return 0
	fi
	local slug case_dir
	slug=$(tr -c 'a-zA-Z0-9' '-' <<<"$name")
	case_dir="$work/cases/$slug"
	rm -rf -- "$case_dir"
	mkdir -p -- "$case_dir"
	cp -a -- "$clean_tree" "$case_dir/steadhand"
	EXTRA_ENTRY_NAME=""
	EXTRA_ENTRY_DATA=""
	# Not a subshell: a mutator may set EXTRA_ENTRY_* for the zip step below.
	PWD_CASE=$case_dir
	pushd -- "$case_dir" >/dev/null
	"$mutator" || die "mutator $mutator failed for $name"
	popd >/dev/null

	local target
	if [[ "$kind" == zip ]]; then
		target="$work/cases/$slug.zip"
		local -a top
		mapfile -t top < <(ls -A -- "$case_dir")
		(cd -- "$case_dir" && zip -rqX -y "$target" "${top[@]}")
		if [[ -n "$EXTRA_ENTRY_NAME" ]]; then
			add_zip_entry "$target" "$EXTRA_ENTRY_NAME" "$EXTRA_ENTRY_DATA"
		fi
	else
		target="$case_dir/steadhand"
	fi

	run_gate "$target"
	if [[ "$expect" == PASS ]]; then
		if ((gate_rc == 0)); then
			report PASS "$name" "$(tail -n 1 <<<"$gate_out")"
		else
			report FAIL "$name" "expected a pass, gate exited $gate_rc: $(head -n 1 <<<"$gate_out")"
		fi
		return 0
	fi
	if ((gate_rc == 0)); then
		report FAIL "$name" "NOT CAUGHT: gate passed"
	else
		# Only the gate's problem lines ("  - ...") count as the message, never its header.
		local problems first
		problems=$(grep -aE '^  - ' <<<"$gate_out" || true)
		if first=$(grep -aE -m 1 -- "$expect" <<<"$problems"); then
			report PASS "$name" "failed as expected: $(sed -e 's/^  - //' <<<"$first" | cut -c1-150)"
		else
			report FAIL "$name" "failed, but without the expected message ($expect): $(head -n 2 <<<"$gate_out" | tr '\n' ' ' | cut -c1-200)"
		fi
	fi
}

echo "Clean build: $clean_zip"
echo
printf '%-6s %-30s %s\n' RESULT CASE DETAIL
run_gate "$clean_zip"
if ((gate_rc == 0)); then report PASS "clean zip" "$(tail -n 1 <<<"$gate_out")"; else report FAIL "clean zip" "$gate_out"; fi
run_gate "$clean_tree"
if ((gate_rc == 0)); then report PASS "clean folder" "$(tail -n 1 <<<"$gate_out")"; else report FAIL "clean folder" "$gate_out"; fi

case_run "look-alike words" dir c_look_alike_words PASS

# Binary and encoded content (grep -I used to skip these).
case_run "nested library zip" dir m_nested_library_zip "archive file" 0
case_run "library as .phar" dir m_library_as_phar "archive file" 0
case_run "NUL byte in php comment" dir m_nul_in_comment "NUL byte in" 0
case_run "UTF-16 copy" dir m_utf16_copy "NUL byte in" 0
case_run "invalid UTF-8 in php" dir m_invalid_utf8 "not valid UTF-8" 0
# Unreadable files (grep exit 2 used to be swallowed).
case_run "unreadable file" dir m_unreadable_file "not readable" 1
# Symlinks, in a folder and in a zip.
case_run "symlink in folder" dir m_symlink "symbolic link" 0
case_run "symlink in zip" zip m_symlink "symbolic link" 0
# Zip layout.
case_run "sibling top-level folder" zip m_extra_top_level "unexpected top-level entry in zip: extra" 0
case_run "absolute path entry" zip m_absolute_entry "absolute path in zip" 0
case_run "parent (..) path entry" zip m_parent_entry "parent-directory" 0
# steadhand.php exemption.
case_run "second statement on require line" dir m_second_statement_on_require_line "includes/Dev" 0
case_run "second guarded require elsewhere" dir m_second_guarded_require_elsewhere "includes/Dev" 0
case_run "duplicate guard block" dir m_duplicate_guard_block "repeat of an allowed guarded-require" 0
case_run "Steadhand\\Dev reference" dir m_namespace_reference "Steadhand.Dev" 0
case_run "puc_ token" dir m_puc_prefix_token "puc_" 0
case_run "Puc/ path and v5p7" dir m_puc_path_and_version "Puc/|v5p" 0
# Controls: the dev directory and the autoloader are checked as before.
case_run "dev directory restored" dir c_dev_directory_restored "dev-only directory present" 0
case_run "vendor autoload missing" dir c_vendor_autoload_missing "bin/build.sh wporg" 0

echo
echo "Summary: $pass_count passed, $fail_count failed, $skip_count skipped"
((fail_count == 0)) || exit 1
exit 0
