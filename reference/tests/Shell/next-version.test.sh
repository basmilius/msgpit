#!/usr/bin/env bash
#
# Covers .github/next-version.sh, the script that decides what a push to main releases.
# It runs the real script against throwaway repositories, so the test cannot drift from it.
#
# Run with: fin exec tests/Shell/next-version.test.sh
set -uo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.github/next-version.sh"
WORK=$(mktemp -d)
FAILED=0

trap 'rm -rf "$WORK"' EXIT

commit() {
    git commit -q --allow-empty -m "$1"
}

check() {
    local expected="$1" label="$2" actual

    actual=$("$SCRIPT")

    if [ "$expected" = "$actual" ]; then
        printf '  ok    %-46s %s\n' "$label" "$actual"
    else
        printf '  FAIL  %-46s %s (expected %s)\n' "$label" "$actual" "$expected"
        FAILED=1
    fi
}

cd "$WORK"
git init -q .
git config user.email test@example.com
git config user.name Test

commit "chore: initial"
check "1.0.0" "the first release starts at 1.0.0"

git tag -a v1.0.0 -m release
check "skip" "nothing new since the last tag"

commit "fix: correct the segment count"
check "1.0.1" "a fix bumps patch"

commit "docs: explain the magic numbers"
check "1.0.1" "docs bumps patch as well"

commit "feat: add the messagebird provider"
check "1.1.0" "a feat bumps minor"

commit "feat(api)!: rename the delivery report endpoint"
check "2.0.0" "an exclamation mark bumps major"

git tag -a v2.0.0 -m release
commit "$(printf 'refactor: tidy storage\n\nBREAKING CHANGE: the api contract moved')"
check "3.0.0" "a breaking change in the body bumps major"

git tag -a v3.0.0 -m release
commit "chore: bump tooling"
check "3.0.1" "a chore bumps patch"

# Tags are sorted by version, not by when they were created.
git tag -a v3.0.1 -m release
commit "feat: something new"
git tag -a v2.9.9 -m release
check "3.1.0" "an older tag added later does not win"

exit "$FAILED"
