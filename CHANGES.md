# TalentTrack v2.22.0 — Hierarchical Back Button + Help Wiki

## Summary

Two items shipped together — one a critical bug fix, one the long-carried help wiki from the 2.19/2.20 backlog:

1. **Back button rewritten as hierarchical parent-map navigation** — fixes a real bug where clicking back twice would ping-pong you back to your edit form. Adds breadcrumb UI above the back link.
2. **Help wiki** — markdown-based, 18 topics, full TOC sidebar with search, "? Help on this topic" contextual links across 13 admin pages, release-discipline commitment going forward.

No schema changes. No migrations.

## Item 1 — Hierarchical back button

### The bug

The 2.19.0 back button used `wp_get_referer()` which has a fundamental flaw: every navigation sets itself as the next page's referer. So:

1. Dashboard → Players list (referer: dashboard)
2. Players list → Edit player 42 (referer: Players list)
3. Edit form → click "← Back" → returns to Players list ✓
4. Back on Players list → click "← Back" (if the list had one) → **returns to Edit form, not dashboard** ✗

This is the bug you reported. The only navigation `wp_get_referer()` can see is the immediately previous page, which is always the page you just came from — meaning repeated back clicks bounce between two adjacent pages instead of walking home to the dashboard.

### The fix

New `BackNavigator` class holds an explicit parent map — every admin page declares its parent, and back buttons always navigate to the parent (never the referer). Walking back repeatedly climbs the hierarchy one level at a time until you reach the dashboard (home). No ping-pong, no cycles.

The map looks like:

```
Dashboard                              (home, no back button)
├── Players                            → back: Dashboard
│   ├── Edit player                    → back: Players
│   └── View player                    → back: Players
├── Evaluations                        → back: Dashboard
│   ├── Edit evaluation                → back: Evaluations
│   └── View evaluation                → back: Evaluations
├── Teams, Sessions, Goals, People     (similar pattern)
├── Reports                            → back: Dashboard
│   └── Report detail (legacy/etc)     → back: Reports
├── Usage Statistics                   → back: Dashboard
│   └── Usage detail (drill-downs)     → back: Usage Statistics
└── ... (Configuration, Access Control, Help)
```

### Breadcrumb UI

Now that parent relationships are explicit, breadcrumbs become trivial. Every page renders a breadcrumb trail above the back link:

```
Dashboard › Players › Edit Player
```

Each segment except the current page is a clickable link. Tap "Dashboard" on any page to jump home; tap "Players" to jump to the list. The back button below the breadcrumb still walks one level (matches muscle memory) but now the user can also skip directly to any ancestor.

### Call site compatibility

All ~13 existing `BackButton::render()` call sites from 2.19 continue to work unchanged. The legacy `$fallback_url` parameter is preserved in the signature for backward compatibility but is now silently ignored — the parent map is authoritative. No callers needed updating.

## Item 2 — Help wiki

### Motivation

Since 2.19 I've promised and deferred a proper help system. The placeholder "? Help on this topic" links shipped in 2.20 were technically wired but pointed at a stub page with barely any content. This release delivers the real wiki.

### Design

**Markdown-sourced** — topic content lives in `docs/<slug>.md` files shipped with the plugin. Version-controlled, diffable, easy to write. No database storage, no WP-post-based content.

**Minimal bundled renderer** — new `Markdown` class handles headings (H1/H2/H3), paragraphs, bullet/numbered lists, bold/italic/inline code, code fences, blockquotes, and links. Tight scope that covers what the topic files actually use; no Composer dependency.

**Topic registry** — `HelpTopics::all()` declares all 18 topics in code (slug, title, group, summary). Content comes from the markdown file; metadata from the registry. This split lets the sidebar render even before the Markdown renderer processes the body.

**Two-pane wiki layout**:
- Left: sticky TOC sidebar with topics grouped (Basics / Performance / Analytics / Configuration / Frontend & access). Search box at top filters the list client-side as you type.
- Right: rendered markdown with breadcrumb "Help › Group › Topic" above.
- Mobile: collapses to single column, TOC stacks above content.

### 18 topics authored

**Basics:** Getting started · Teams & players · People (staff)

**Performance:** Evaluations · Evaluation categories & weights · Sessions · Goals

**Analytics:** Reports · Player rate cards · Player comparison · Usage statistics

**Configuration:** Configuration & branding · Custom fields · Bulk actions (archive & delete) · Printing & PDF export

**Frontend & access:** Player dashboard · Coach dashboard · Access control

Each topic runs ~100-200 words. Purpose-oriented ("what this feature is for") + concrete actions ("how to do X") + cross-references via markdown links. No filler.

### Contextual "? Help on this topic" links

Added via a new `HelpLink` helper to 13 admin pages:

- Players, Teams, Evaluations, Category Weights, Sessions, Goals, Configuration, Usage Statistics, Rate Cards, Custom Fields, Evaluation Categories, People
- Plus Reports and Player Comparison which already had placeholder links from 2.20 (these now resolve to real content)

The link sits next to each page's H1, small-size, non-intrusive. Clicking it navigates to the relevant topic in the wiki.

### Search

Client-side search across topic titles and summaries. Filters the TOC list live as you type. "No matching topics" shown when the query has no hits. Group labels auto-hide when all their topics are filtered out. Plenty for 18 topics; full-text search over topic bodies would be overkill.

### Release discipline commitment

Every future sprint that touches a feature area must also update the corresponding help topic(s) in the same ZIP. The CHANGES.md for each release will note which topics were updated.

If a new feature lands without a topic written, the sprint isn't done. If a topic description no longer matches the shipped feature after a change, that's a bug fix item for the next release.

The wiki is now the canonical source of user-facing documentation. The README is for developers; the wiki is for admins, coaches, and players.

## Files in this release

### New
- `src/Shared/Admin/BackNavigator.php` — hierarchical parent map + breadcrumb trail builder
- `src/Shared/Admin/HelpLink.php` — renders "? Help on this topic" contextual links
- `src/Modules/Documentation/Markdown.php` — minimal markdown renderer
- `src/Modules/Documentation/HelpTopics.php` — topic registry with groups and metadata
- `docs/getting-started.md`, `docs/teams-players.md`, `docs/people-staff.md`, `docs/evaluations.md`, `docs/eval-categories-weights.md`, `docs/sessions.md`, `docs/goals.md`, `docs/reports.md`, `docs/rate-cards.md`, `docs/player-comparison.md`, `docs/usage-statistics.md`, `docs/configuration-branding.md`, `docs/custom-fields.md`, `docs/bulk-actions.md`, `docs/printing-pdf.md`, `docs/player-dashboard.md`, `docs/coach-dashboard.md`, `docs/access-control.md` — 18 topic files

### Modified
- `talenttrack.php` — version 2.22.0
- `src/Shared/Admin/BackButton.php` — rewritten to use BackNavigator; renders breadcrumbs + back link
- `src/Modules/Documentation/Admin/DocumentationPage.php` — complete rewrite as wiki page with TOC + content + search
- `src/Modules/Players/Admin/PlayersPage.php`, `src/Modules/Teams/Admin/TeamsPage.php`, `src/Modules/Evaluations/Admin/EvaluationsPage.php`, `src/Modules/Evaluations/Admin/CategoryWeightsPage.php`, `src/Modules/Evaluations/Admin/EvalCategoriesPage.php`, `src/Modules/Sessions/Admin/SessionsPage.php`, `src/Modules/Goals/Admin/GoalsPage.php`, `src/Modules/Configuration/Admin/ConfigurationPage.php`, `src/Modules/Configuration/Admin/CustomFieldsPage.php`, `src/Modules/Stats/Admin/UsageStatsPage.php`, `src/Modules/Stats/Admin/PlayerRateCardsPage.php`, `src/Modules/People/Admin/PeoplePage.php` — added "? Help on this topic" link next to H1
- `languages/talenttrack-nl_NL.po` + `.mo` — ~35 new strings

### Deleted
(none)

## Install

Extract `talenttrack-v2_22_0.zip`. Move `talenttrack-v2.22.0/` contents into your `talenttrack/` folder. Deactivate + reactivate.

**No migrations.** Purely additive plus one rewrite (BackButton).

**The `docs/` folder is new** — make sure the extract copies it in. The DocumentationPage reads markdown files from `TT_PATH . 'docs/'` (resolves from the plugin root).

## Verify

### Back button
1. Dashboard → Players → Edit player 42. See the breadcrumb: `Dashboard › Players › Edit Player`.
2. Click "← Back" — you go to the Players list. Breadcrumb: `Dashboard › Players`.
3. Click "← Back" again — **you go to the dashboard**, not back to the edit form. ✓ Bug fixed.
4. Click any breadcrumb segment on any page — jumps directly to that ancestor.
5. Open any edit URL directly (paste into a new tab). Breadcrumb renders correctly; back button goes to parent list.

### Help wiki
6. Admin menu → Help & Docs. Wiki page loads with TOC on left, "Getting started" topic on the right.
7. Click a topic in the TOC — it renders immediately.
8. Use the search box. Type "eval" — only evaluation-related topics remain. Type "xxx" — "No matching topics" appears. Clear the search — full list returns.
9. Click on any admin page's "? Help on this topic" link (top of page, next to title). Jumps to the relevant topic.
10. Breadcrumb at top of each topic: `Help › Group › Topic`.
11. On mobile/narrow window — TOC stacks above content. Everything still readable.

### Regression checks
12. All v2.20 and v2.21 functionality unchanged. Player Comparison, Access Control tiles, Reports tiles still work.
13. Frontend tile grid from v2.21 unchanged. Frontend back button (different helper) unchanged.
14. All existing BackButton::render() call sites still function — no PHP errors, no blank back buttons.

## Known caveats

- **Wiki search is title+summary only, not full-text.** If you want to find every topic that mentions "radar chart", search won't help you — but 18 topics is small enough to skim visually, and the summary text covers the gist.
- **Topic content is English-only for now.** The topic files live in `docs/<slug>.md`; Dutch (or other language) versions would require a parallel `docs/nl/` folder + language detection. Future work.
- **Printing a wiki topic uses browser print.** Not print-optimized. For a dedicated printable help PDF you'd need a more ambitious layout. Not in scope here.
- **No "was this helpful?" feedback mechanism.** No rating, no analytics on which topics get read. Could be added; would need a small tracking table.
- **No edit capability for the admin.** Topics are file-based, so admins can't edit them from within WP. This is intentional — consistent docs across all installations — but if a club really wants to customize, they can edit the .md files directly (surviving plugin updates requires a copy to a safe path, which is a footgun).

## Design notes

- **Why markdown instead of WP posts.** Posts are editable but would require database seeding on activation, drift from the shipped version as WP updates the posts, and break when the plugin updates. File-based docs ship with the plugin, update with it, and can't be accidentally deleted via WP admin.
- **Why a minimal renderer instead of Parsedown or CommonMark.** Composer-free plugin, and we control the input. A 100-line renderer handles everything the topics actually use. Total cost: one file to maintain.
- **Why 18 topics and not fewer.** Each topic maps roughly to one admin page or one cross-cutting concept. Fewer would mean stuffing multiple features into one topic (bad — search and navigation suffer). More would fragment related content. 18 is the shape of the current feature surface.
- **Why no breadcrumbs on the frontend.** The frontend has a flat one-level hierarchy: tile landing + sub-view, with a fixed "← Back to dashboard" return path (v2.21 FrontendBackButton). Breadcrumbs would imply depth that doesn't exist. The admin has real hierarchy (Dashboard → List → Edit); breadcrumbs earn their place there.
- **Why the release discipline matters.** The 2.19/2.20 backlog grew because "let's update docs next time" always deferred to "next time." Making docs a first-class sprint deliverable — shipped in the same ZIP as code changes — closes that loop. Docs that track code are trustworthy; docs that lag code become harmful misinformation.

## v2.23.0 preview

- **Capability refactor** (the big one deferred across the last three sprints): split `tt_manage_*` and `tt_evaluate_*` caps into `tt_view_*` + `tt_edit_*` pairs. This enables proper read-only experiences across all sections, not just analytics. Every `current_user_can()` call site needs auditing — medium-large sprint on its own.
- Additional report tiles (attendance summary, goal progress by status, etc.)
- Whatever else accumulates
