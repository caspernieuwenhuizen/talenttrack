#!/usr/bin/env bash
#
# Regenerate languages/talenttrack.pot from PHP source and msgmerge it into
# every locale .po. The result is left in the working tree; the CALLER
# decides whether to commit and push it.
#
# This is the single definition of "regenerate the catalogue". Both the
# release path and the manual re-sync call it, so the two cannot drift.
#
#   .github/workflows/auto-release.yml  the release path — the only place
#                                       that commits the result to main
#   .github/workflows/i18n-sync.yml     manual re-sync (workflow_dispatch)
#   bash tools/i18n-sync.sh             locally, with wp-cli + gettext on PATH
#
# Exit codes:
#   0  the catalogue in the working tree is correct — inspect
#      `git status --porcelain languages/` to see whether anything changed
#   1  the sync could not be completed; languages/ has been restored to HEAD
#
# `wp i18n make-pot` is WordPress's official .pot generator: it knows the
# gettext keyword conventions (__/_e/_n/_x/esc_html__/…) and filters by
# text-domain natively. `--skip-audit` skips its lint pass; ours lives in
# release.yml.
#
# `msgmerge -U` updates each .po in place: new msgids appear with an empty
# msgstr, obsoleted ones are commented out with `#~`. `--no-fuzzy-matching`
# stops it guessing that a new string is a renamed old one, which is brittle.

set -u

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root" || exit 1

restore() {
    git checkout -- languages/ 2>/dev/null || true
}

if ! command -v wp >/dev/null 2>&1; then
    echo "::error::wp-cli is not on PATH — cannot regenerate languages/talenttrack.pot."
    exit 1
fi

if ! command -v msgmerge >/dev/null 2>&1; then
    echo "::error::msgmerge is not on PATH (install gettext) — cannot merge into the .po files."
    exit 1
fi

if ! wp i18n make-pot . languages/talenttrack.pot --slug=talenttrack --skip-audit; then
    echo "::error::wp i18n make-pot failed; leaving the catalogue as committed."
    restore
    exit 1
fi

for po in languages/*.po; do
    echo "Merging $po"
    if ! msgmerge --update --backup=none --no-fuzzy-matching "$po" languages/talenttrack.pot; then
        echo "::error::msgmerge failed on $po; leaving the catalogue as committed."
        restore
        exit 1
    fi
done

# A merged catalogue that no longer compiles is worse than a stale one: the
# .mo step downstream would either fail the release or ship a broken locale.
# Validate before handing anything back, and throw the whole merge away if it
# doesn't hold up.
if command -v msgfmt >/dev/null 2>&1; then
    for po in languages/*.po; do
        if ! msgfmt --check -o /dev/null "$po"; then
            echo "::error::$po does not compile after msgmerge; leaving the catalogue as committed."
            restore
            exit 1
        fi
    done
fi

if [ -z "$(git status --porcelain languages/)" ]; then
    echo "Catalogue already in sync; nothing changed."
    exit 0
fi

# `wp i18n make-pot` rewrites POT-Creation-Date on every run, so the date
# alone is not a real change and must not produce a commit. The .po files and
# the non-header .pot lines are the meaningful signal.
po_changed=$(git status --porcelain languages/*.po 2>/dev/null | wc -l)
pot_real_change=$(git diff --unified=0 -- languages/talenttrack.pot 2>/dev/null \
    | grep -E '^[-+]' \
    | grep -vE '^[-+]"POT-Creation-Date|^[-+]{3}' \
    | wc -l)

if [ "$po_changed" -eq 0 ] && [ "$pot_real_change" -eq 0 ]; then
    echo "Only POT-Creation-Date changed; reverting so it produces no commit."
    restore
    exit 0
fi

echo "Catalogue updated — $po_changed .po file(s) changed."
exit 0
