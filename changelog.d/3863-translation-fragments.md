# Translations ship as per-PR fragments instead of edits to the shared catalogue (#3863)

A pull request that adds a translatable string no longer edits
`languages/talenttrack-nl_NL.po`. It drops one brand-new file,
`languages/pending/<issue>-<slug>.po`, holding only the entries it
introduces with their Dutch translations — the same move `changelog.d`
makes for the changelog, for the same reason. A file that exists on one
branch cannot collide with another branch's, and the catalogue was the
only file parallel work ever collided on.

The release step folds the fragments in, once, for the whole batch:
`tools/release.ps1` now calls `tools/consolidate-translations.php`
alongside the changelog consolidation, and `languages/pending/` is empty
again afterwards. The consolidator keys entries on the `(msgctxt, msgid)`
pair with wrapped literals joined by content — so a string written across
continuation lines and the same string on one line are recognised as one
entry — fills in the translation for a msgid the catalogue already carries
untranslated, and refuses to write at all if the result would contain a
duplicate msgid or collide with a retired entry. No user-visible change:
this is how translations reach the catalogue, not what they say.
