# Translation catalogue is refreshed at release instead of after every merge (#3865)

Bump: patch

The `.pot` regeneration and `msgmerge` that keep `languages/` in step with
the source tree used to run on every push to `main` that touched PHP, and
commit the result. That put every open pull request one commit behind on
`languages/*.po` — a file GitHub cannot auto-merge — so merging one branch
forced a manual merge and a full CI re-run on all the others. On a 22-branch
drain that was around twenty avoidable re-integrations.

The refresh now runs once per release, inside `auto-release.yml`, immediately
before the `.mo` files are compiled, so the translations a release publishes
are built from the freshly synced catalogue and `main` ends up as current as
it was before. The shared mechanics live in `tools/i18n-sync.sh`, and the old
`i18n-sync` workflow remains as a manual **Run workflow** button for
re-baselining between releases. The release-time step is deliberately
non-fatal: if the refresh cannot run, the release still ships and the run
carries a warning, because an untranslated string falls back to English while
a blocked release reaches nobody.
