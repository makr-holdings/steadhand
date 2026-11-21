#!/usr/bin/env bash
# Builds a release zip into build/.
#
# Usage: bin/build.sh <dev|wporg> [version]
#   dev     -> build/steadhand-dev-<version>.zip   (includes the dev-only updater)
#   wporg   -> build/steadhand-<version>.zip       (no update-checker code; gated)
#
# Contents: the files git tracks plus untracked files that git does not ignore, minus
# .distignore. Ignored local files (build/, vendor/, node_modules/, caches) never ship.
# A build that fails at any step leaves no zip behind.
#
# The version is read from the Version header of steadhand.php, which must match
# STEADHAND_VERSION. An optional version argument (with or without a leading "v")
# must equal it.

set -euo pipefail

usage() {
	echo "Usage: bin/build.sh <dev|wporg> [version]" >&2
	exit 2
}

die() {
	echo "build.sh: error: $*" >&2
	exit 1
}

[[ $# -ge 1 && $# -le 2 ]] || usage
variant=$1
arg_version=${2:-}
case "$variant" in
dev | wporg) ;;
*) usage ;;
esac

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
root=$(cd -- "$script_dir/.." && pwd)

# --- Tools ------------------------------------------------------------------
missing=()
for tool in git rsync zip unzip composer php; do
	command -v "$tool" >/dev/null 2>&1 || missing+=("$tool")
done
if ((${#missing[@]} > 0)); then
	die "missing required tool(s): ${missing[*]}"
fi

# --- Version ----------------------------------------------------------------
cd -- "$root"
[[ -f steadhand.php ]] || die "steadhand.php not found in $root"

header_version=$(sed -n -E '/^[[:space:]*]*Version:[[:space:]]*/{s/^[[:space:]*]*Version:[[:space:]]*([^[:space:]]+).*/\1/p;q}' steadhand.php)
const_version=$(sed -n -E "/^define\( 'STEADHAND_VERSION'/{s/^define\( 'STEADHAND_VERSION', '([^']*)' \);.*/\1/p;q}" steadhand.php)

semver_re='^[0-9]+\.[0-9]+\.[0-9]+$'
[[ "$header_version" =~ $semver_re ]] || die "the Version header in steadhand.php is '$header_version'; it must be X.Y.Z digits"
[[ "$const_version" =~ $semver_re ]] || die "STEADHAND_VERSION in steadhand.php is '$const_version'; it must be X.Y.Z digits"
if [[ "$header_version" != "$const_version" ]]; then
	die "steadhand.php disagrees with itself: Version header is $header_version but STEADHAND_VERSION is $const_version"
fi
version=$header_version

if [[ -n "$arg_version" ]]; then
	arg_version=${arg_version#v}
	[[ "$arg_version" =~ $semver_re ]] || die "version argument '$2' must be X.Y.Z digits (a leading v is allowed)"
	if [[ "$arg_version" != "$version" ]]; then
		die "requested version $arg_version does not match steadhand.php ($version). Bump the Version header and STEADHAND_VERSION first."
	fi
fi

# --- Output paths and cleanup -----------------------------------------------
build_dir="$root/build"
variant_dir="$build_dir/$variant"
stage="$variant_dir/steadhand"
if [[ "$variant" == "dev" ]]; then
	zip_name="steadhand-dev-$version.zip"
else
	zip_name="steadhand-$version.zip"
fi
zip_path="$build_dir/$zip_name"

# A zip from an earlier run must never survive into this one. Delete it before staging,
# and delete it again if this run fails at any step.
rm -f -- "$zip_path"
finished=0
on_exit() {
	if ((finished == 0)); then
		rm -f -- "$zip_path"
		echo "build.sh: the build did not finish; no zip was left at $zip_path" >&2
	fi
}
trap on_exit EXIT

# Only ever remove build/<variant>, never anything else.
[[ -n "$variant_dir" && "$variant_dir" == "$root/build/$variant" ]] || die "refusing to clean '$variant_dir'"
rm -rf -- "$variant_dir"
mkdir -p -- "$stage"

assert_no_symlinks() {
	local links
	links=$(find "$stage" -type l)
	if [[ -n "$links" ]]; then
		echo "build.sh: error: the stage contains symbolic links (a zip must not carry links):" >&2
		sed -e 's/^/  /' <<<"$links" >&2
		exit 1
	fi
}

# --- Stage ------------------------------------------------------------------
# The list is tracked files plus untracked files that git does not ignore, so a new file
# counts before its first commit and ignored local files never do. rsync ignores its exclude
# rules for --files-from entries, so .distignore is applied to the list here. Its entries are
# plain root-anchored paths ("/name"); anything else is refused rather than guessed at.
git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "build.sh needs a git checkout: the stage is built from git's file list"

excludes=()
while IFS= read -r line || [[ -n "$line" ]]; do
	line=${line%$'\r'}
	[[ -z "$line" || "$line" == \#* ]] && continue
	[[ "$line" == /* ]] || die ".distignore entry '$line' must start with / (root-anchored paths only)"
	[[ "$line" != *[*?[]* ]] || die ".distignore entry '$line' uses wildcards, which this script does not support"
	excludes+=("${line#/}")
done <"$root/.distignore"

filtered_list() {
	local f pat skip
	while IFS= read -r -d '' f; do
		skip=0
		for pat in "${excludes[@]}"; do
			if [[ "$f" == "$pat" || "$f" == "$pat"/* ]]; then
				skip=1
				break
			fi
		done
		if ((skip == 0)); then
			printf '%s\0' "$f"
		fi
	done
}

git -C "$root" ls-files -z --cached --others --exclude-standard | filtered_list |
	rsync -a --from0 --files-from=- "$root/" "$stage/"
assert_no_symlinks

if [[ "$variant" == "dev" ]]; then
	[[ -f "$stage/includes/Dev/Updater.php" ]] || die "dev build needs includes/Dev/Updater.php, which is not in the source tree yet. Add the dev updater and re-run."
	[[ -f "$stage/includes/Dev/composer.lock" ]] || die "dev build needs includes/Dev/composer.lock, which is not in the source tree yet. Add it and re-run."
fi

if [[ "$variant" == "wporg" ]]; then
	[[ "$stage" == "$root/build/wporg/steadhand" ]] || die "refusing to remove includes/Dev from '$stage'"
	rm -rf -- "$stage/includes/Dev"
	# The dev-only composer script that installs the updater's library names includes/Dev,
	# which the WordPress.org build must not mention. Drop that one script from the staged copy.
	php -r '
		$path = $argv[1];
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (isset($data["scripts"]["dev-updater:install"])) {
			unset($data["scripts"]["dev-updater:install"]);
			file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
	' "$stage/composer.json" || die "could not strip the dev-only script from the staged composer.json"
fi

# --- Autoloader ---------------------------------------------------------------
# The plugin has no production dependencies. The root composer.lock is not staged (.distignore),
# so `composer install` here would resolve the dev packages over the network. Generate only the
# PSR-4 autoloader for Steadhand\ instead: no network, no lock, no dev packages.
(cd -- "$stage" && composer dump-autoload --no-dev --optimize --no-interaction)

if [[ "$variant" == "dev" ]]; then
	# The updater's library is pinned by includes/Dev/composer.lock, which must stay in the stage until this install is done.
	composer -d "$stage/includes/Dev" install --no-dev --no-interaction --no-progress
fi

# Lock files are build inputs, not release content.
find "$stage" -name composer.lock -type f -delete

# --- Checks on the stage ------------------------------------------------------
if [[ "$variant" == "dev" ]]; then
	# Positive checks: the dev build must contain the updater, its library, and the library's
	# GitHub API class. The glob keeps working when the library's version folder changes.
	[[ -f "$stage/includes/Dev/vendor/autoload.php" ]] || die "dev build: the updater library did not install (includes/Dev/vendor/autoload.php is missing)"
	github_api=$(find "$stage/includes/Dev/vendor" -path '*/Puc/v*/Vcs/GitHubApi.php' -type f)
	[[ -n "$github_api" ]] || die "dev build: no Puc/v*/Vcs/GitHubApi.php in the installed updater library; the library layout may have changed"
	grep -qE "^[[:space:]]*require_once STEADHAND_DIR \. 'includes/Dev/Updater\.php';[[:space:]]*\$" "$stage/steadhand.php" ||
		die "dev build: steadhand.php does not contain the guarded require of includes/Dev/Updater.php"
fi

if [[ "$variant" == "wporg" ]]; then
	"$script_dir/check-wporg-build.sh" "$stage" || die "the WordPress.org build failed its leak check; nothing was zipped"
fi

# --- Zip ----------------------------------------------------------------------
# -y stores symbolic links as links instead of following them (there are none: see above).
(cd -- "$variant_dir" && zip -rqXy "$zip_path" steadhand)

if [[ "$variant" == "wporg" ]]; then
	# The gate runs on the finished zip too, so what is uploaded is what was checked.
	"$script_dir/check-wporg-build.sh" "$zip_path" || die "the WordPress.org zip failed its leak check; the zip has been removed"
else
	listing=$(unzip -Z1 "$zip_path")
	grep -qxF "steadhand/includes/Dev/Updater.php" <<<"$listing" || die "dev zip is missing steadhand/includes/Dev/Updater.php"
	grep -qxF "steadhand/includes/Dev/vendor/autoload.php" <<<"$listing" || die "dev zip is missing steadhand/includes/Dev/vendor/autoload.php"
	grep -qE '^steadhand/includes/Dev/vendor/.*/Puc/v[^/]+/Vcs/GitHubApi\.php$' <<<"$listing" || die "dev zip is missing the updater library's GitHub API class"
fi

finished=1
file_count=$(find "$stage" -type f | wc -l | tr -d '[:space:]')
echo "Built $zip_path"
echo "Variant: $variant  Version: $version  Files: $file_count"
