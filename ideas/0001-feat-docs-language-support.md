<!-- type: feat -->

# Documentation language support

Raw idea:

Documentation is only in English? It needs to have language support.

## Context (for shaping)

Today the docs/*.md files are authored in English. The wiki renderer in src/Modules/Documentation/ just loads and renders the markdown. No i18n layer for help content.

The plugin UI has full Dutch translation via talenttrack-nl_NL.po/.mo. But the wiki content is separate and English-only.

Shaping questions for when this moves to spec:
- One language at a time (nl_NL vs en_US) or side-by-side?
- Per-topic translation override, or whole-docs folder per locale?
- Fall back to English when a Dutch translation is missing?
