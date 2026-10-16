#!/usr/bin/env bash
#
# Builds the release ZIP exactly like CI does, and refuses to produce a ZIP with forbidden content.
#
#   bin/build-release.sh            builds build/complianty-connector.zip
#
# Steps: copy the tree per .distignore, composer install --no-dev --classmap-authoritative inside the copy,
# verify the contents, zip, write a SHA-256 checksum. The working tree and its vendor/ stay untouched.

set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="complianty-connector"
build="$root/build"
stage="$build/stage/$slug"

command -v rsync >/dev/null || { echo "rsync is required" >&2; exit 1; }
command -v zip >/dev/null || { echo "zip is required" >&2; exit 1; }

php "$root/bin/version.php" check

rm -rf "$build"
mkdir -p "$stage"

# Copy everything the release may contain. vendor/ is rebuilt below, never copied.
rsync -a \
	--exclude-from="$root/.distignore" \
	--exclude='/.*/' \
	--exclude='/vendor' \
	--exclude='/build' \
	--exclude='/node_modules' \
	"$root/" "$stage/"

# The copy needs the lock file to install. composer.json stays in the release (Plugin Check expects it
# next to vendor/, and it documents the build), the lock file is removed again.
cp "$root/composer.lock" "$stage/"
(cd "$stage" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet)
rm -f "$stage/composer.lock"

# vendor/ keeps only the autoloader and the Composer runtime. Drop anything else Composer may leave.
find "$stage/vendor" -mindepth 1 -maxdepth 1 ! -name autoload.php ! -name composer -exec rm -rf {} +
find "$stage" -name '.DS_Store' -delete

# Verify.
fail=0
check() {
	if ! eval "$2"; then
		echo "FAIL: $1" >&2
		fail=1
	fi
}

check "main file present" "test -f '$stage/$slug.php'"
check "uninstall.php present" "test -f '$stage/uninstall.php'"
check "readme.txt present" "test -f '$stage/readme.txt'"
check "LICENSE present" "test -f '$stage/LICENSE'"
check "vendor/autoload.php present" "test -f '$stage/vendor/autoload.php'"
check "block present" "test -f '$stage/blocks/dse/block.json' && test -f '$stage/blocks/dse/edit.js'"
check "no dev packages in vendor" "! test -d '$stage/vendor/phpunit' && ! test -d '$stage/vendor/squizlabs' && ! test -d '$stage/vendor/brain'"
check "composer.json present" "test -f '$stage/composer.json'"
check "no tests" "! test -e '$stage/tests'"
check "no VCS or CI files" "! find '$stage' \\( -name '.git*' -o -name '.github' -o -name '.env*' -o -name '.wp-env.json' \\) | grep -q ."
check "no node files" "! find '$stage' \\( -name 'node_modules' -o -name 'package*.json' \\) | grep -q ."
check "no tool configs" "! find '$stage' \\( -name 'phpcs*' -o -name 'phpstan*' -o -name 'phpunit*' \\) | grep -q ."
check "no archives or logs" "! find '$stage' \\( -name '*.zip' -o -name '*.log' -o -name '*.sql' -o -name '*.map' \\) | grep -q ."
check "no symlinks" "! find '$stage' -type l | grep -q ."
check "every PHP file lints" "! find '$stage' -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors' | grep -q ."
check "autoloader is classmap-authoritative" "grep -q 'classMapAuthoritative' '$stage/vendor/composer/autoload_real.php' || grep -q 'setClassMapAuthoritative' '$stage/vendor/composer/autoload_real.php'"

if [ "$fail" -ne 0 ]; then
	echo "Release check failed." >&2
	exit 1
fi

version="$(php -r 'preg_match("/^Stable tag:\s+(\S+)$/m", file_get_contents($argv[1]), $m); echo $m[1];' "$stage/readme.txt")"
zip_name="$slug.zip"

(cd "$build/stage" && zip -qr -X "$build/$zip_name" "$slug")
(cd "$build" && shasum -a 256 "$zip_name" > "$zip_name.sha256")

echo
unzip -l "$build/$zip_name" | tail -n +4 | awk '{print $4}' | grep -v '^$' | sort | head -60
echo
echo "Built $build/$zip_name (version $version)"
cat "$build/$zip_name.sha256"
