# Help topics are registered by the doc files themselves (#2544)

Bump: patch

Each help topic's title, group, summary and audience now live in a front-matter
block at the top of its own markdown file instead of in a separate list inside
the plugin. Dropping a documented file into the docs folder registers it — which
is what stops shipped features from having documentation nobody can reach.

Dutch titles and summaries come from the Dutch doc, so the sidebar no longer
depends on the translation catalogue for its labels.

One topic surfaces as a result: **Trial cases** was filed under a sidebar group
that does not exist, so it had never appeared in Help & Docs and could only be
reached by typing its URL. It now sits at the end of Performance.
