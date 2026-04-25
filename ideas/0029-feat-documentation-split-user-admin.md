<!-- type: feat -->

# Documentation split — separate user docs from admin docs

Origin: post-#0019 v3.12.0 idea capture. The TalentTrack `docs/` folder mixes content for very different audiences. A coach trying to understand "how do I record an evaluation" lands in the same docs page as an admin trying to understand "how do I configure custom fields and capability gates." The signal-to-noise ratio is bad for both audiences.

This idea proposes splitting the docs into clear audience-scoped layers.

## Why this matters

- **Frontend-first product, but docs are wp-admin-style.** The plugin's whole #0019 thrust was making the frontend the primary surface for everyday users. The docs haven't followed — coaches who never open wp-admin still see admin-flavored documentation.
- **Self-service onboarding.** Once #0024 (Setup Wizard) ships, the wizard hands off to docs. The docs need to be *good* at the handoff point — landing on a page titled "Custom Fields System Architecture" is jarring.
- **Localization volume.** Admin docs are dense and technical. User docs are simpler and shorter. Splitting them lets us prioritize translating user docs (the high-leverage surface) without dragging admin docs through the same translation pipeline.

## Proposed split

Three audience layers (working names):

1. **User docs** — for coaches, players, parents. Task-oriented. "How do I…" → step-by-step. No jargon. Heavy use of screenshots. Small surface area, high translation priority.
2. **Admin docs** — for the academy admin (typically HoD or club operations). Configuration-oriented. Capability model, custom fields, role assignments, season management. Medium surface area.
3. **Developer docs** — for plugin extenders / system integrators. Architecture, hooks, REST API, capability map, theme inheritance internals. Lowest priority for translation; English-only is acceptable.

The current `docs/` folder mixes layers 1 and 2 with no separation, and layer 3 lives implicitly in code comments + DEVOPS.md.

## Open questions to resolve before shaping

1. **Folder structure.** Three top-level subfolders (`docs/user/`, `docs/admin/`, `docs/dev/`)? Or use a manifest that flags each doc with an audience tag? Folders are simpler; manifests survive cross-cutting topics.
2. **Translation priority.** User docs translated first/always; admin docs as time allows; dev docs English-only? Confirm during shaping.
3. **Linking discipline.** When a user-doc page references an admin concept ("ask your administrator to configure …"), the link should jump cleanly into the admin layer. Need a convention for cross-layer links.
4. **In-product docs viewer.** The `tt-docs` page (already exists) renders one slug at a time. Does it gain audience-aware filtering — "show me user docs only"? Or do we trust folder structure + URL paths?
5. **Migration approach.** Move-and-rewrite each existing doc into its new layer, OR ship the new structure as additive and deprecate the old paths gradually? Move-and-rewrite is cleaner but more work; gradual is easier but leaves stale content longer.
6. **Setup wizard hand-off.** Once #0024 ships, the wizard's "Done" page links into docs. Which doc page is the canonical entry point? The user-docs landing page? A dedicated "after the wizard" page?

## Touches (when shaped)

- File system reorg of `docs/`.
- `tt-docs` page (`DocumentationPage.php`) — minor changes to surface the audience structure.
- `docs/index.md` rewrite as a layered table-of-contents.
- All in-product links into docs (eg. tile descriptions linking to a help page) — audit and update.
- Localized counterparts under `docs/nl_NL/`.
- Documentation about the documentation structure (yes, meta).

## Sequence position (proposed)

Modest size (~15-20h depending on how much rewriting vs. just reorganizing). Best landed before or alongside #0024 (Setup Wizard) since the wizard's hand-off is one of the key beneficiaries. Could also bundle with #0010 (multi-language UI) since translating a clean user-docs layer first is more tractable than translating today's mixed-audience pile.
