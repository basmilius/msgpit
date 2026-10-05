#!/usr/bin/env bash
#
# Prints the version a push to main should release, derived from the conventional commits since
# the last v* tag: a breaking change bumps major, a feat bumps minor, anything else bumps patch.
# Prints "skip" when there is nothing new to release.
#
# Used by .github/workflows/publish.yml and covered by tests/Shell/next-version.test.sh.
set -euo pipefail

previous=$(git tag --list 'v*' --sort=-v:refname | head -n1)

if [ -z "$previous" ]; then
    echo "1.0.0"
    exit 0
fi

range="${previous}..HEAD"

if [ -z "$(git log --format=%H "$range")" ]; then
    echo "skip"
    exit 0
fi

subjects=$(git log --format='%s%n%b' "$range")
IFS=. read -r major minor patch <<< "${previous#v}"

if grep -qE '^[a-z]+(\(.+\))?!:|^BREAKING[ -]CHANGE' <<< "$subjects"; then
    major=$((major + 1))
    minor=0
    patch=0
elif grep -qE '^feat(\(.+\))?:' <<< "$subjects"; then
    minor=$((minor + 1))
    patch=0
else
    patch=$((patch + 1))
fi

echo "${major}.${minor}.${patch}"
