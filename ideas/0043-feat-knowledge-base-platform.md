<!-- type: feat -->

# Knowledge Base platform — searchable article CMS for end-user content

Origin: 27 April 2026 conversation, parked as the broader-scope counterpart to #0042. #0042 ships KB articles as plain markdown in the existing `docs/` tree (reusing #0029's role-filtered TOC + audience markers) — that works fine while article count is small. Once the catalogue grows past roughly 30 articles, discovery breaks down: linear navigation, no search, no related-article hints, no "was this helpful?" telemetry. This idea captures the heavyweight version that takes over when the markdown approach hits its ceiling.

## Why this is parked, not active

- **Trigger condition not yet met.** TalentTrack has only a handful of end-user-facing articles today; #0042 will add maybe 4-8 more (mobile-install how-tos). The markdown approach holds.
- **Real KB infrastructure is non-trivial.** Custom post type, full-text search, hierarchical taxonomy, per-article audience targeting, "last updated" stamps, related-article suggestions, optional public visibility for SEO — each is a small thing on its own, but the combination is a 40-60h build.
- **Deciding *where* it lives changes the scope dramatically.** Inside the plugin (each club's WP) vs. on the marketing site (#0030 talenttrack-branding) vs. on a separate KB-only site are three very different builds with different audiences, different SEO posture, and different content-ownership models.

## Working assumption

KB content is **TalentTrack-authored, not club-authored**. Clubs don't write KB articles; the TalentTrack team does, and the content is shared across all installs. This implies the KB lives **outside** any individual club's WP install — most likely on the marketing site (#0030 talenttrack-branding repo) or a dedicated `kb.talenttrack.app` subdomain.

If that assumption holds, this idea is mostly a marketing-site project, not a plugin project — substantially reducing the plugin-side scope to "a small client that fetches/embeds KB articles by ID for in-app contextual help."

## What needs a shaping conversation

1. **Where does the KB live?** Three candidates, each with very different engineering scope. **Recommendation: separate `kb.talenttrack.app` subdomain on top of a static-site generator (Eleventy, Astro, Hugo) with content stored in the talenttrack-branding repo. Public-facing for SEO. The plugin embeds article snippets via REST when contextual help is needed.**
2. **Public-facing vs. auth-gated?** Public KB drives SEO and reduces signup friction. Auth-gated KB is slightly more controlled but invisible to search engines. **Recommendation: public.**
3. **Search engine choice.** FlexSearch (client-side, free, fine up to ~500 articles), Algolia free tier (10k searches/mo), self-hosted Meilisearch, or stay simple with the SSG's built-in search. **Recommendation: FlexSearch for v1; Meilisearch only if the catalogue grows past 500 articles.**
4. **Who maintains content?** TalentTrack team only, or community-contributable via PRs to the branding repo? **Recommendation: TalentTrack team only for v1. Reconsider community contributions when the catalogue is mature.**
5. **Multilingual approach.** Single-language with auto-translate (#0025 pattern), or hand-authored NL+EN parallels (#0029 pattern)? **Recommendation: hand-authored NL+EN for the cornerstone articles; auto-translate for everything else.**
6. **In-app contextual help integration.** When a TalentTrack screen says "see how this works," does it deep-link to the KB or embed the article inline via a help-drawer? **Recommendation: the help drawer pattern from v3.28.0 is the right pattern — fetch the KB article over REST, render in-place.**
7. **Versioning + last-updated.** Show "updated 2 weeks ago" on every article? Mark articles as stale automatically after N months without an edit? **Recommendation: yes to last-updated; auto-stale at 12 months.**
8. **Article taxonomy.** Audience (`player`, `parent`, `coach`, `admin`), topic (onboarding, evaluations, methodology, troubleshooting), platform (iOS, Android, web)? **Recommendation: audience + topic for v1; platform tags only on platform-specific articles.**
9. **Migration path from #0042's markdown.** Move the markdown articles into the new KB platform, or keep both surfaces? **Recommendation: move them once the platform exists; redirect the `docs/` URLs to the new location for any external links.**
10. **Telemetry.** "Was this helpful?" thumbs, page views per article? **Recommendation: lightweight thumbs + view counter via the same anonymous-telemetry pattern the demo install uses; defer to v1.1 if it slows down the build.**

## Scope estimate

Rough — strongly dependent on Q1 (where it lives):

| Variant | Estimate |
| - | - |
| Subdomain + SSG + FlexSearch + REST API for in-app embed | ~40-60h |
| Plugin-internal CPT with WP search | ~30-45h |
| Embedded inside talenttrack-branding marketing site | ~25-35h |

**Recommendation: subdomain variant, ~40-60h.** Public-facing helps with SEO + lead gen, decouples KB from any single WP install, and the SSG-based stack is operationally trivial.

## Out of scope (this idea)

- Real-time chat support — different surface, different problem.
- Authoring UX inside wp-admin — the KB lives outside the plugin in the recommended variant.
- Per-club KB content — explicit design decision: KB is shared content authored by TalentTrack, not white-label-customisable.

## Trigger to promote from "parked" to "active"

Promote when **either**:

- `docs/` article count crosses ~30, or
- A paying customer asks for KB search more than twice in a quarter, or
- Marketing wants a public KB for SEO.

Until one of those triggers fires, this idea stays parked and #0042's markdown-in-`docs/` approach is the canonical surface.

## Cross-references

- **#0042** — youth contact strategy uses markdown-in-`docs/` for v1; promotion to this idea is the upgrade path.
- **#0029** — current `docs/` audience markers + role-filtered TOC; the migration unit when this idea ships.
- **#0030** — talenttrack-branding marketing site; the recommended host for the KB subdomain.
- **#0025** — auto-translate engine; potential reuse for non-cornerstone KB content.
- **#0027** — football methodology PDF content; longer-form authored content that could move into the KB if it fits.
