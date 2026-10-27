---
title: Review gate — docs and i18n
group: developer
summary: The checklist a PR must pass when it changes anything a user sees, so the help topic, the Dutch twin and the translation fragment ship with the change.
audience: [dev]
order: 43
---

# Review gate: docs & i18n

**Triggered by** any user-visible change: a new or changed screen, button,
message, report, setting, or route.

**Run by** the developer when opening the PR; verified by QA before merge.
The reviewer ticks the list in the PR.

## Checklist

- [ ] Every user-facing string goes through `__()`, `_e()`, `_x()` or
      `_n()`; editable lists come from `tt_lookups` or `tt_config`
      (`DEVOPS.md` § Ship-along rules).
- [ ] Every new translatable string has its Dutch `msgstr` in a new
      `languages/pending/<issue>-<slug>.po` fragment; the shipped
      catalogues are untouched; `php tools/check-i18n-fragment.php` passes
      (`languages/pending/README.md`).
- [ ] A one-word or short msgid that is ambiguous in Dutch uses `_x()` with
      a context (project memory: short msgid, wrong Dutch sense).
- [ ] Lookup seed labels for Dutch go to `tt_translations`, not into the
      seed row (project memory: lookup seed translations).
- [ ] `docs/<slug>.md` and `docs/nl_NL/<slug>.md` describe the behaviour as
      it now is, in the user's words, without version numbers or issue
      numbers in a reader-facing topic (`docs/contributing.md`).
- [ ] A new `?tt_view=` route is claimed by a topic's `views:` front matter
      or listed in `config/no_help_topic.php` with a reason.
- [ ] `php tools/check-docs.php` passes: front matter complete, groups and
      modules resolve, cross-references resolve, valid UTF-8.
- [ ] A new `?tt_view=` slug a tile registers is in a `FeatureRegistry`
      entry's `view_slugs` or in `config/always_on_surfaces.php` with a
      sentence (`CLAUDE.md` §9, Switchability); `php tools/check-module-toggles.php`
      passes.
- [ ] `changelog.d/<issue>-<slug>.md` exists, written for the person who
      will read the release notes, with `Bump:` when the change is more than
      a patch (`changelog.d/README.md`); or the PR is a pure refactor, test or
      tooling change labelled `no-changelog`.
- [ ] A `from-hq` issue whose fix does not satisfy the recorded
      reproduction carries its `Verify: <persona> <METHOD> <route> -> <status>`
      line (`CLAUDE.md` §7).
- [ ] `SEQUENCE.md` updated if the work is referenced there.

## Sweep (quarterly)

Run `tools/check-docs.php` and the i18n drift report, open every topic the
install can show for each persona and check it describes the current screen,
and list topics whose Dutch twin is older than the English. File the stale
ones as one documentation issue per module.
