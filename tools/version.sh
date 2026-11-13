#!/bin/sh
# Sets the plugin version everywhere it appears.
#
#   tools/version.sh 0.6.0
#
# Updates the plugin header, the CLEAN_SHORT_LINKS_VERSION constant and the
# "Stable tag" in readme.txt, and adds an empty changelog entry if there is none.
# Tagging happens afterwards, see CONTRIBUTING.md.
set -e
cd "$(dirname "$0")/.."
V="$1"
echo "$V" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || { echo "Usage: tools/version.sh MAJOR.MINOR.PATCH" >&2; exit 1; }

sed -i.bak -E "s/^( \* Version:[[:space:]]+).*/\1$V/" clean-short-links.php
sed -i.bak -E "s/define\( 'CLEAN_SHORT_LINKS_VERSION', '[^']+' \);/define( 'CLEAN_SHORT_LINKS_VERSION', '$V' );/" clean-short-links.php
sed -i.bak -E "s/^Stable tag:.*/Stable tag: $V/" readme.txt
rm -f clean-short-links.php.bak readme.txt.bak

if ! grep -q "^= $V =\$" readme.txt; then
	awk -v v="$V" '{ print } /^== Changelog ==$/ { print ""; print "= " v " ="; print "* …" }' readme.txt > readme.txt.new && mv readme.txt.new readme.txt
	echo "Added a changelog entry for $V in readme.txt – please fill it in."
fi
echo "Version set to $V."
