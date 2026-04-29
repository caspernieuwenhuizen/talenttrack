<!-- type: feat -->

# #0043 — Knowledge Base platform — searchable article CMS for end-user content

> ## ⚠ STATUS: PARKED
>
> This spec is fully shaped, but **do not implement until at least one trigger condition fires**:
>
> - `docs/` article count crosses ~30, or
> - A paying customer asks for KB search more than twice in a quarter, or
> - Marketing wants a public KB for SEO.
>
> Until a trigger fires, #0042's markdown-in-`docs/` approach is the canonical surface for end-user content. This spec exists so that when a trigger does fire, the design decisions are already locked and the build can start immediately.

## Problem

#0042 ships KB articles as plain markdown in the existing `docs/` tree (reusing #0029's role-filtered TOC + audience markers) — that works fine while the article count is small. Once the catalogue grows past roughly 30 articles, discovery breaks down: linear navigation, no search, no related-article hints, no "was this helpful?" telemetry.

This spec captures the heavyweight version that takes over when the markdown approach hits its ceiling.

Who will feel it (when triggers fire): end users (players, parents, coaches) trying to find the right article in a growing catalogue; the support inbox (linking to articles instead of writing the same answer twice); marketing (SEO for organic acquisition).

## Proposal

A **public-facing KB** at `kb.talenttrack.app`, built as a static-site generator (Eleventy / Astro / Hugo) with content stored in the `talenttrack-branding` repo (#0030). The plugin embeds article snippets via REST when contextual help is needed.

This means the KB is **not a plugin feature** in the strict sense — most of the engineering happens in the branding repo. The plugin-side scope is a thin client that fetches/embeds KB articles by ID for in-app help.

Splitting it this way:

- Avoids putting CMS infrastructure inside every club's WP install (fewer attack surface, fewer plugin updates).
- Makes the KB shared across all installs — TalentTrack-authored content, not club-customisable.
- Drives SEO + lead generation for marketing.

## Scope

### Layer 1 — KB site (lives in `talenttrack-branding` repo, NOT in this plugin)

Static site at `kb.talenttrack.app`:

- **Stack**: Eleventy (recommended — minimal dependencies, fast, simple) or Astro (if interactive components emerge later).
- **Content**: markdown files under `content/kb/<slug>/<lang>.md`. Frontmatter declares audience (`player` / `parent` / `coach` / `admin`), topic (`onboarding` / `evaluations` / `methodology` / `troubleshooting`), platform tags (`ios` / `android` / `web`), updated-at, last-reviewed-at.
- **Search**: FlexSearch (client-side, ~10KB gzipped, fine up to ~500 articles). Index built at SSG build time.
- **Multilingual**: hand-authored NL+EN parallels for cornerstone articles; auto-translate (#0025 pattern) for everything else.
- **Telemetry**: lightweight thumbs-up/down + view counter via the same anonymous-telemetry pattern the demo install uses. Defer to v1.1 if it slows down the build.
- **Last-reviewed badge**: shown on every article. Auto-stale after 12 months without an edit (rendered as a "this article hasn't been reviewed in over a year" banner).

### Layer 2 — REST surface (lives in `talenttrack-branding` repo)

`GET https://kb.talenttrack.app/api/articles/{slug}` returns:

```json
{
  "slug": "install-on-iphone",
  "title": "Install TalentTrack on iPhone",
  "audience": ["player", "parent"],
  "html": "<p>Tap Share → Add to Home Screen…</p>",
  "updated_at": "2026-04-15",
  "url": "https://kb.talenttrack.app/install-on-iphone"
}
```

- Public, no auth.
- CORS-enabled for `*.wordpress.com` and any TalentTrack-installing domain (configurable list).
- Cached aggressively (CDN-friendly).

### Layer 3 — Plugin-side client (this is the only part inside `talenttrack` repo)

A small client + UI inside the WP plugin, in `src/Infrastructure/KB/`:

- **`KbClient`** — `fetchArticle( $slug )`, `searchArticles( $query, $audience )`. Backed by `wp_remote_get` with 5s timeout, fallback to cached value if the request fails. Cache for 24 hours per article.
- **`KbHelpDrawer`** — extends the existing context-aware help drawer pattern from v3.28.0. Replaces the current "fetch from local docs" path with "fetch from KB if the slug is registered as a KB article, else fall back to local docs."
- **`HelpTopicMap`** — extended with a `kb_slug` field per topic. When the slug is set, the help drawer fetches from the KB instead of rendering the local markdown.

### Migration from #0042's `docs/`

When the trigger fires:

1. Move the `docs/<slug>.md` user-tier articles into the KB site's `content/kb/`.
2. Add 301 redirects so any external links to `docs.talenttrack.app/<slug>` (if that ever existed) resolve to `kb.talenttrack.app/<slug>`.
3. Update `HelpTopicMap` entries so the help-drawer pulls from KB instead of local files.
4. Leave `docs/<slug>.md` files as **stubs** with a single line: `> This article has moved to https://kb.talenttrack.app/<slug>.`
5. Eventually delete the stubs (separate cleanup PR).

Admin-tier docs (architecture, contributing, devops) stay in `docs/` — they're not user-facing KB content.

### Authoring + governance

- **Who maintains content**: TalentTrack team only for v1. Reconsider community contributions when the catalogue is mature.
- **Editorial workflow**: PRs to the `talenttrack-branding` repo. SSG build runs in CI; preview deploys on every PR. Merge to `main` triggers prod deploy.
- **Translation discipline**: cornerstone articles get hand-authored NL+EN. Long-tail articles use auto-translate, with the auto-translated locale clearly marked ("Translated automatically — please flag inaccuracies").

## Wizard plan (per #0058)

Exemption: this spec creates no new record-creation flow inside the plugin. The plugin-side change is a help-drawer integration, not a CRUD surface.

## Out of scope

- **Real-time chat support** — different surface, different problem.
- **Authoring UX inside wp-admin** — the KB lives outside the plugin.
- **Per-club KB content / white-labelling** — KB is shared content authored by TalentTrack, not customisable.
- **Auth-gated KB** — public-facing for SEO. (If a paying-customer-only knowledge surface is later needed, that's a separate idea.)
- **In-app full-text search of the KB** — for v1, the help drawer fetches a specific article by slug. Search lives on the public KB site only.
- **Versioning per release** — articles describe current behaviour. Historical "in v3.x this worked differently" content is out of scope.

## Acceptance criteria (when implementation starts)

### KB site (in `talenttrack-branding`)

- [ ] `kb.talenttrack.app` resolves to the SSG-built site.
- [ ] At least 30 articles seeded (the trigger threshold) — moved from `docs/` user-tier markdown.
- [ ] FlexSearch index built and searchable.
- [ ] NL + EN parallels for cornerstone articles; auto-translated for the rest with the marker.
- [ ] Public REST endpoint returns article HTML by slug.
- [ ] Last-reviewed badge + auto-stale after 12 months.

### Plugin-side (in `talenttrack`)

- [ ] `KbClient` fetches articles with 5s timeout + 24h cache + fallback to local docs.
- [ ] `KbHelpDrawer` integrates with the existing v3.28.0 help-drawer surface.
- [ ] `HelpTopicMap` entries with `kb_slug` route to KB; entries without fall back to local docs.
- [ ] No regression on the existing local-docs help drawer for entries not yet migrated.
- [ ] CORS request from the plugin to `kb.talenttrack.app` works on installs running on arbitrary hostnames.

### Migration

- [ ] User-tier markdown articles moved to KB; admin-tier stays in `docs/`.
- [ ] Stub markdown files left behind with redirect notice.
- [ ] `HelpTopicMap` entries updated.

### Telemetry (optional, can ship in v1.1)

- [ ] Thumbs-up/down per article, anonymous, cached at the SSG layer.
- [ ] View counter, anonymous, cached.

## Notes

### Sizing

| Variant | Estimate |
| - | - |
| Subdomain SSG + FlexSearch + REST + plugin-side client | ~40-60h |
| Plugin-internal CPT alternative (rejected) | ~30-45h |
| Embedded inside marketing site (rejected) | ~25-35h |

**Recommendation: subdomain variant, ~40-60h.** Public-facing helps with SEO + lead gen, decouples KB from any single WP install, and the SSG-based stack is operationally trivial.

### Hard decisions locked during shaping

1. **KB lives at `kb.talenttrack.app`** — separate subdomain on top of the SSG. Not in any single WP install; not embedded in marketing site.
2. **Public-facing** — no auth gate. SEO + lead-gen optimisation.
3. **FlexSearch** — client-side, free, fine up to ~500 articles. Meilisearch only if catalogue grows past 500.
4. **TalentTrack team authors all content** — no community contributions in v1.
5. **Hand-authored NL+EN cornerstones, auto-translate the long tail** — best ratio of effort vs. coverage.
6. **Help drawer integration via slug-keyed `HelpTopicMap`** — minimal plugin-side surface.
7. **Last-updated badge + 12-month auto-stale** — gentle pressure to keep content current.
8. **Audience + topic taxonomy** — platform tags only on platform-specific articles.

### Cross-references

- **#0042** — youth contact strategy uses markdown-in-`docs/` for v1; promotion to this spec is the upgrade path.
- **#0029** — current `docs/` audience markers + role-filtered TOC; the migration unit when this spec ships.
- **#0030** — `talenttrack-branding` marketing site; the recommended host for the KB subdomain.
- **#0025** — auto-translate engine; reused for non-cornerstone KB content.
- **#0027** — football methodology PDF content; longer-form authored content that could move into the KB if it fits.

### Trigger to promote from PARKED to ACTIVE

Promote when **either**:

- `docs/` article count crosses ~30, or
- A paying customer asks for KB search more than twice in a quarter, or
- Marketing wants a public KB for SEO.

When a trigger fires, this spec moves from "Parked" to "Ready" in `SEQUENCE.md` and the build starts.
