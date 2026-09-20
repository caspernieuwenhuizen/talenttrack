# languages/pending — per-PR translation fragments

This folder decouples **content work** from the shipped translation
catalogues, so several implementation agents can drain `ready-for-dev`
issues in parallel without colliding on `languages/talenttrack-nl_NL.po`.

It is the same move `changelog.d` makes for `CHANGES.md`, for the same
reason, against the same failure.

## The rule

A PR that adds a translatable string does **not** edit
`languages/talenttrack-*.po`. It drops **one new file** here:

```
languages/pending/<issue>-<short-slug>.po
```

carrying only the entries that PR introduces, each with its Dutch
`msgstr`. A brand-new file per issue exists on exactly one branch, so it
cannot conflict with another agent's.

For a locale other than Dutch, name it
`<issue>-<slug>.<locale>.po` — e.g. `3863-fragments.de_DE.po` — or put
`"Language: de_DE\n"` in a gettext header inside the fragment. Either
spelling works; neither is required for `nl_NL`, which is the default.

## Why the catalogue is off limits in a content PR

A `.po` is not append-only. The catalogue regeneration **relocates**
entries as the `.pot` changes, so your appended entry and its relocated
copy both survive a merge — and git reports no conflict, because as far
as it is concerned nothing disagreed. The result is a duplicate `msgid`,
which `msgfmt` refuses outright, so one merge can stop every locale
compiling. The quieter version is worse: when the two copies disagree —
one translated, one emptied by a merge that lost the string — gettext
takes the first, and a Dutch string silently reverts to English with no
error anywhere.

Fragments remove the collision instead of managing it.

## Format

A fragment is an ordinary `.po` file. The minimum is the entry itself:

```po
#: src/Modules/Players/Frontend/FrontendPlayerDetailView.php:214
msgid "Back to squad"
msgstr "Terug naar de selectie"
```

Four rules, all enforced by `tools/consolidate-translations.php --check`
(run in CI on every PR that adds one):

1. **One file per issue**, named `<issue>-<slug>.po`. The issue number is
   how the release traces a string back to the work that added it.
2. **Every entry carries a non-empty `msgstr`.** A fragment exists to
   hold the translation; an empty one would consolidate into the
   catalogue as untranslated, which is the drift the i18n gate is there
   to prevent.
3. **No obsolete `#~` entries.** A fragment states what this PR adds.
   Retiring a string is the catalogue regeneration's job, at release
   time.
4. **No duplicate `(msgctxt, msgid)` pair**, within the fragment or
   against the catalogue. That pair is the key gettext itself uses.

Keep a blank line between entries.

### One-word labels need a context

A short `msgid` inherits the wrong sense from whatever the translator
saw first. `"Pass"` became `"Geslaagd"` — the exam sense, on a football
screen. Use `_x()` with a context in the PHP, and carry the `msgctxt`
through into the fragment:

```po
msgctxt "football action"
msgid "Pass"
msgstr "Pass"
```

Then read the rendered Dutch on the screen, not only the `.po`.

## What does not go here

- **Edits to an existing translation.** Changing the Dutch for a string
  that already ships is a catalogue edit; make it in
  `languages/talenttrack-nl_NL.po` directly. A fragment whose translation
  disagrees with the catalogue's is consolidated (the fragment wins) and
  reported, but that is a safety net, not the route.
- **`.pot` changes.** The template is generated from the PHP source; no
  PR writes it by hand.
- **`.mo` files.** CI compiles those at release time. Never commit one.

## Consolidating

The release agent runs, once, for the whole batch — `tools/release.ps1`
calls it next to the `changelog.d` step:

```
php tools/consolidate-translations.php            # fold in, delete the fragments
php tools/consolidate-translations.php --check    # validate only (what CI runs)
php tools/consolidate-translations.php --dry-run  # report, write nothing
```

It keys entries on `(msgctxt, msgid)` with wrapped literals joined by
content, fills in a translation for a msgid the catalogue already carries
untranslated, appends the rest above the obsolete `#~` block, and refuses
to write at all if the result would contain a duplicate msgid or collide
with an obsolete entry. After a successful run this folder holds nothing
but this README.
