<!-- type: epic -->

# #0064 — Custom CSS independence + WP-theme bypass

> Originally drafted as #0063 in the user's intake batch. Renumbered on intake — the cascade #0061 → #0062 → #0063 shifted everything down by one to keep ID order consistent with the shipped polish-bundle #0061.

## Problem

#0023 went one direction: inherit from the WP theme, with a curated set of brand controls (six color pickers, two font dropdowns, one global "inherit theme" toggle). That's the right default for clubs whose marketing site already has a strong identity and who want TalentTrack to blend in.

This spec is the opposite escape hatch: clubs that want TalentTrack to look exactly the way they specify, regardless of what WP theme is active, regardless of what `wp_head` is dumping into the page, regardless of which plugin lands its CSS last. Today the only path is the bespoke-child-theme approach — write a WordPress child theme that overrides plugin tokens at higher specificity. That works for one club with a developer; it doesn't scale to dozens of pilot clubs none of whom employ a frontend developer.

What's missing is a way for a non-developer club admin to:

- Upload a `.css` file and have it apply to TalentTrack surfaces only
- Or paste/edit CSS in an admin textarea with a live preview
- Or use a visual editor (color / font / spacing pickers) that generates CSS for them
- Toggle, per-surface, between "use my custom CSS" and "use what the WP theme provides via #0023's inheritance"
- Have it work the same on frontend dashboards (player/coach views) as on wp-admin TalentTrack pages — which today are a styling no-man's-land owned by neither WP core admin nor the plugin's frontend pipeline

## Proposal

Probably an epic with three child specs:

- **`feat-css-foundation`** — storage model (per-club, versioned), enqueue pipeline that beats anything the WP theme ships, isolation strategy decision (Q1 below), per-surface toggle on `tt_clubs`. No authoring UI yet.
- **`feat-css-authoring-surfaces`** — the three authoring paths: file upload, in-admin textarea with live preview, visual settings → generated CSS. Each is a sub-feature within the spec.
- **`feat-css-wp-admin-coverage`** — extending the pipeline to wp-admin TalentTrack pages, which need a different enqueue hook and a narrower isolation strategy (we can't shadow-DOM-isolate wp-admin without breaking it).

Splitting Foundation lets Authoring and Admin-coverage run in parallel afterward.

## How it composes with #0023

#0023 and this idea cooperate cleanly if we get the toggle model right:

| Mode | Frontend toggle | Admin toggle | Result |
|------|----------------|--------------|--------|
| TalentTrack default | OFF | OFF | Plugin's bundled CSS only — what every install looks like out of the box. |
| #0023 theme inheritance | "Inherit theme" | OFF | Frontend defers typography and links to the active WP theme. Wp-admin keeps TT defaults. |
| Custom CSS, frontend only | "Custom CSS" | OFF | This idea, frontend only. Wp-admin keeps TT defaults. |
| Custom CSS, admin only | OFF | "Custom CSS" | This idea, wp-admin only. Frontend uses TT defaults. |
| Custom CSS, both | "Custom CSS" | "Custom CSS" | This idea, everywhere. WP theme is fully bypassed for TT surfaces. |
| Mixed | "Inherit theme" | "Custom CSS" | #0023 on frontend, this idea in admin. Probably rare but legal. |

The key constraint: this idea's "Custom CSS" mode and #0023's "Inherit theme" toggle should be mutually exclusive on the same surface — picking one disables the other in the UI. Combining "inherit from theme" with "but also override with custom CSS" is a confusing config space we don't need.

## The three authoring paths

All three write into the same storage; they're three lenses on one CSS payload.

### Path A — Upload a `.css` file

Admin uploads `club.css`; we validate (size cap, MIME, basic syntax check), store the body on `tt_clubs`, increment a version counter, enqueue with that version as the cache-buster.

Hardest bit: server-side CSS sanitization. We can't allow `@import url(http://attacker)`, `expression()`, `url(javascript:...)`, or external `@font-face` URLs that exfiltrate IP. Probably ship with a pragmatic block-list (regex against the obvious badness) plus a documented "if you need an external font URL, paste it explicitly into the textarea path, not file upload."

### Path B — Textarea editor with live preview

CodeMirror or Monaco in the admin page; same storage as Path A. A "Preview" iframe alongside the editor renders the player dashboard with the candidate CSS applied. Save commits.

Hardest bit: the live preview iframe needs to render a real TalentTrack surface. The simplest approach is a `?tt_preview_css=draft` query param that loads pending-not-saved CSS for the current admin user only. Cap-gated, nonce-required.

### Path C — Visual settings → generated CSS

Form with color pickers, font selectors, spacing sliders, a "rounded vs sharp" corners toggle, etc. — covering the most-asked customizations. Save generates a CSS snippet from the form values and writes it into the same storage as Path A/B.

Hardest bit: round-trip. If a club uses Path C then opens Path B and edits the textarea, can they go back to Path C without losing their tweaks? Probably not — once you've hand-edited, the visual tab is read-only or shows a "your CSS has been hand-edited; revert to use this tool" banner. Same pattern Mailchimp + every other "visual editor over a textarea" tool uses.

A nice side-effect of Path C: it's the only path that knows about TalentTrack's `--tt-*` tokens explicitly, so its output is naturally well-scoped. Paths A/B let the admin write whatever they want.

## Isolation strategy — the load-bearing decision

This is **Q1**, the most important shaping question. Three credible options:

### Option 1 — Scoped class prefix

Wrap every TalentTrack surface in `<div class="tt-root">` and require all custom CSS rules to be prefixed with `.tt-root` (or auto-prefix on save). The custom CSS still lives in the same DOM as the WP theme's CSS; specificity wars are managed by careful prefixing.

- ✅ Simplest to implement, no JS runtime cost.
- ✅ Wp-admin works the same as frontend — both wrap their root in `.tt-root`.
- ❌ Auto-prefixing CSS is a real parser, not a regex. Use a real CSS parser library or punt to "we'll only auto-prefix Path C output; Paths A and B are responsibility-of-author."
- ❌ Bleed risk: a careless `body { ... }` in custom CSS escapes the prefix.

### Option 2 — Shadow DOM

Render TalentTrack surfaces inside a Shadow DOM element. Custom CSS lives inside the shadow root; WP theme CSS literally cannot reach in.

- ✅ True isolation, no specificity war possible.
- ❌ Form submissions, focus management, third-party widgets (datepickers, charts) often break inside shadow roots.
- ❌ Wp-admin can't be shadow-rooted — it's WP's territory and the admin chrome (nav, screen options, etc.) needs to keep working.

### Option 3 — iframe for frontend, scoped class for admin

Frontend dashboards render inside an iframe that sources only TalentTrack's CSS + the custom CSS. Wp-admin gets the scoped-class treatment.

- ✅ Hardest possible isolation on frontend.
- ❌ Iframe + WP cookie auth + responsive sizing + accessibility is a saga. Probably a non-starter.

**Lean is Option 1, scoped class.** Reasons:

- We already have `--tt-*` tokens that act as a soft contract — clubs wanting to customize mostly want to override token values, which is one rule each, naturally scoped.
- The hard cases (datepickers, charts) keep working without special handling.
- We can layer Option 2 later for the few surfaces that genuinely need it (a customer-facing public-link view of a player profile, maybe) without retrofitting everything.

## Mobile-first per CLAUDE.md § 2

Custom CSS must not break the mobile-first guarantees CLAUDE.md § 2 specifies — touch target sizes, layout breakpoints, performance budget. Two safeguards:

- The plugin always loads its base mobile-first stylesheet first; custom CSS loads after. So a club that doesn't override layout-related rules keeps the plugin's mobile-first layout for free.
- Path C (visual settings) deliberately does not expose layout-affecting controls — only colors, fonts, weights, corners, spacing scale. Layout is non-negotiable.
- Paths A and B come with a documented warning that overriding layout properties is at the club's own risk and may break on small screens. We don't try to programmatically prevent this — it'd be too restrictive.

## Wizard plan

**Exemption** — this is a settings surface, not a record-creation flow. Path C (visual editor) is multi-step but the steps are tabs in a settings panel, not a wizard. Save is one click. Per `CLAUDE.md` § 3 settings panels are exempt from the wizard-first rule.

## Cross-cutting concerns

- **Per-club storage** — CSS lives on `tt_clubs.custom_css_frontend`, `tt_clubs.custom_css_admin`, plus `*_enabled` toggles and `*_version` counters. Per #0052 tenancy, every load scopes by `CurrentClub::id()`.
- **Version + cache-busting** — every save bumps a per-surface version integer; the version goes into the enqueue URL as `?ver=N`. WP/CDN caches do the right thing automatically.
- **Audit trail** — every save writes one #0021 audit row with author + size + version. We don't store the full CSS in audit (too big); just the diff line-count or a hash.
- **Capability** — `tt_admin_styling` (new), gated to club admins. Not granted to coaches by default; some clubs might want to delegate to a "marketing manager" role.
- **Fail-safe** — every CSS surface has a `?tt_safe_css=1` URL parameter that loads zero custom CSS. Helps a non-technical admin recover from "I uploaded a file that broke everything" without database access.
- **Right-to-erasure interaction** — custom CSS isn't personal data; no special handling on player erasure.
- **Branding registry overlap** — #0023's brand kit (logo, primary/secondary colors) keeps working independently. Custom CSS layers on top. A club using Path C visual settings effectively duplicates some #0023 fields; we live with this rather than merging them.
- **Live preview security** — the preview iframe loads a real TalentTrack page with the current admin's draft CSS. Nonce-gated, single-use, expires in 10 minutes. Never accessible to other users.

## Open shaping questions

| # | Question | Why it matters |
|---|----------|----------------|
| Q1 | Isolation strategy — scoped class, Shadow DOM, or iframe? | Single biggest decision. Affects every other choice. Lean: scoped class. |
| Q2 | CSS sanitization on save — block-list, allow-list, or full parser? | Block-list is pragmatic, allow-list is safest, full parser is overkill. Probably block-list with a documented threat model. |
| Q3 | Path C scope — colors+fonts only, or also spacing/corners/weights/shadows? | Wider scope = more value but more complex generator. Lean: start narrow, expand based on demand. |
| Q4 | Should custom CSS be downloadable/exportable as a `.css` file? | Yes — Export module (#0063) can render it. Useful for clubs that want to version-control or hand off to a developer. |
| Q5 | Per-team styling (sub-club granularity) or club-only? | v1 is club-only. Per-team is a separate spec if it's ever asked for. |
| Q6 | What happens to custom CSS when #0023 inheritance toggle is ON for the same surface? | UI makes them mutually exclusive — picking one disables the other. Locked above. |
| Q7 | Wp-admin coverage — is admin a v1 must, or can it ship as a fast follow? | Authoring lives in admin so admin styling means we're styling our own UI. Probably ship admin in v2 to de-risk v1. |
| Q8 | Live preview UX — alongside editor (split pane), full-screen toggle, or new tab? | Mobile usability of the admin panel matters per CLAUDE.md § 2; full-screen probably wins on small screens. |
| Q9 | Templates / starter themes — do we ship a small library of pre-made club themes (e.g. "modern dark", "classic football", "minimal")? | Shortens time-to-value for non-developer clubs. Probably yes, ~3 templates in v1. |
| Q10 | Versioning + rollback — keep last N saves with a "revert" button, or just one rollback point? | "Last 10" is friendly and cheap; database cost is trivial. Probably last 10 + named "saved presets." |

## Out of scope (provisional)

- Marketing site styling — TalentTrack is the plugin only. The club's WP marketing site is the WP theme's job.
- Per-page CSS overrides — one CSS payload per surface (frontend, admin), not one per page.
- JS injection — strictly CSS. No `<script>` tags, no JS uploads.
- Live theming preview for end-users — the preview iframe is for the admin authoring the CSS, not a public "try a different theme" feature.
- Custom HTML / template overrides — out of scope, never. The plugin owns its templates.
- CSS-in-JS / runtime CSS generation — static CSS only.
- Per-team or per-coach styling — club-level only in v1.

## Cross-references

- **#0023** Styling options + WP-theme inheritance — sibling, opposite-direction. Toggles on the same surface are mutually exclusive. Both write to `tt_clubs`. Shared `--tt-*` token system.
- **#0011 / #0030** Branding — brand kit (logo, primary/secondary colors) keeps its current behaviour; custom CSS layers on top.
- **#0021** Audit log — every CSS save writes one row.
- **#0033** Authorization and module management — new `tt_admin_styling` capability registers here.
- **#0052** SaaS-readiness REST + tenancy — every endpoint scopes by `CurrentClub::id()`; storage is per-club.
- **#0063** Export module — custom CSS is exportable as a `.css` file via the export pipeline (use case "club CSS export").
- **`assets/css/frontend-admin.css`** + **`src/Shared/Frontend/BrandStyles.php`** — existing token system this idea layers on top of, not around.
- **Pilot client theme** — the bespoke WP theme built for a pilot install demonstrates the override-via-child-theme pattern. This idea exists to make that approach unnecessary for non-developer clubs.

## Things to verify before shaping

- 30-minute spike: how late in the cascade does the WP-theme CSS land vs the plugin's CSS today? If the theme always wins via specificity, our enqueue hook + version need extra care.
- Audit every wp-admin TalentTrack page and identify which ones today rely on WP admin CSS for layout (forms, tabs, list tables). Those need to keep working when custom admin CSS is OFF; identifying them now sizes the admin-coverage spec correctly.
- Spike: try Shadow DOM on one frontend surface (player dashboard) for an afternoon. If it cleanly works without breaking interactive bits, Q1 reopens. If it breaks the date pickers / charts as expected, scoped-class wins by default.
- Look at how Elementor / Bricks / Oxygen handle "custom CSS per page/site" — prior art on sanitization, textarea UX, and live preview.
- Check Freemius's plugin-store rules on shipping a CSS-paste textarea — some marketplaces flag it as a security concern even though it's well-precedented.

## Estimated effort once shaped

| Phase | Focus | Effort |
|-------|-------|--------|
| Foundation | Storage model + enqueue pipeline + scoped-class wrap + per-surface toggle + safe-mode URL param | ~20-28h |
| Path A | File upload + sanitization + size cap + version counter | ~10-14h |
| Path B | Textarea editor + live preview iframe + nonce-gated draft loading | ~16-22h |
| Path C | Visual settings UI + CSS generator + round-trip warning | ~20-28h |
| Wp-admin coverage | Admin-side enqueue + identify breakage points + mobile-first guardrails verification | ~14-20h |
| Templates | 3 starter themes ("modern dark", "classic football", "minimal") | ~6-10h |
| Versioning + rollback | Last-10 saves table + "revert" UI | ~8-12h |
| Docs + safety net | `docs/custom-css.md` + `nl_NL` counterpart + the `?tt_safe_css=1` recovery flow | ~4-8h |

**Total: ~100-140 hours**, sequenced across several sprints. Foundation is the gate; Paths A/B/C and admin coverage can run in parallel afterward. Templates and rollback can slip to v2 if the rest is bigger than expected.
