<!-- type: feat -->

# #0082 — Player file UX redesign: hero card, empty-state CTAs, scannable tabs

Pilot operator on the player file: *"need to make this much more visually appealing and we want clear CTAs to set up the player files (e.g. when no goals exist, no PDP exists, no evaluations exist or trials)."* The v3.92.0 polish landed surgical fixes (name no longer rendered three times, page title became *"Player file of {name}"*, profile fields reordered) but the underlying surface still reads as a thin tabbed dictionary, not as a player record an operator wants to spend time on. Empty tabs render as a one-line italic *"No goals recorded yet."* with no path forward; the hero is a 96px photo + a team line and nothing else.

This spec turns the player file into the centerpiece it should be — a visually-anchored hero card that summarises *who and where the player is right now*, and per-tab empty states that hand the operator a *Create your first …* button instead of a dead end.

## Problem

### Problem 1 — the hero is too quiet

The hero strip today is ~104px tall: round photo on the left, team name + age group muted on the right, that's it. There is no quick read of the player's status, no signal of where they are in their journey, no indication of how long they've been in the academy, and no shortcut to their most-recent activity / evaluation / goal. An operator opening the file to answer *"how is Player X doing this month?"* has to click into the tabs to see anything substantive. The hero takes up screen real estate without justifying it.

The visual weight is also wrong on mobile (which is the operator's primary viewing surface per the mobile-first principle). At 360px the photo dominates the viewport without surfacing the facts a coach pulls up the file to check during a session.

### Problem 2 — empty tabs are dead ends

Every tab renders one of these when the player has no records of that type:

- *"No goals recorded yet."* (Goals)
- *"No evaluations recorded yet."* (Evaluations)
- *"No attended activities yet."* (Activities — actually informational rather than empty-state, the player may simply not have attended yet)
- *"No PDP cycle yet."* (PDP)
- *"No trial history."* (Trials)

These are accurate but useless. A coach landing on a player file for the first time hits five empty tabs in a row, gets no guidance about what to do next, and bounces. The operator's exact request — *"clear CTAs to set up the player files (e.g. when no goals exist, no PDP exists, no evaluations exist or trials)"* — names the surface and the gap.

The empty-state copy also doesn't differentiate between "this is a brand-new player who has nothing yet" and "this is a player who has had things archived". A senior player who graduated three months ago and shows *"No goals recorded yet"* on the active query reads as a system bug, not as filtered data.

### Problem 3 — the tab list is fixed and uninformative

The tab navigation always shows six tabs (Profile / Goals / Evaluations / Activities / PDP cycle / Trials) with no count badges. A coach scanning the file cannot see *"this player has 12 goals"* or *"this player has 0 trials"* without clicking. The operator has six clicks to make to know whether each section has content.

This compounds Problem 2: the operator clicks Trials, sees the empty state, backs out, clicks PDP cycle, sees the empty state, backs out, etc. Tab count badges + a subtle empty-tab visual treatment would let the operator skip the empty ones entirely.

## Proposal

**Redesigned hero card** anchored on the player photo with a structured information block: name (already in the page title above the article, no duplicate), team + age group, status pill, age tier badge, days-in-academy counter, and three "latest" chips (most recent activity / evaluation / goal) that link to the row. Mobile-first: the card stacks under 480px with the photo full-width-rounded, info block below; desktop the photo sits left and the info block fills the rest of the width.

**Empty-state CTAs per tab** that are first-class UI, not italic muted text. A centred icon + headline + one-sentence explainer + primary action button that creates the corresponding record. Permission-aware — coaches see *"Record first evaluation"*, scouts see only the headline (no CTA, no write access). Wizard-aware: the CTA targets the wizard variant where one exists (`?tt_view=wizard&slug=new-goal-for-player&player_id=N`) and the flat form otherwise.

**Tab count badges** rendered next to each label. Counts come from a single repository call (`PlayerFileCounts::for($player_id)`) so the page makes one query instead of six. Empty tabs render with a muted treatment (lighter colour, no underline on hover) so they read as "nothing here" without removing them from the nav. Active tab keeps the existing accent.

**Mobile-first first.** Every layout decision is authored at 360px; desktop scales up via `min-width: 480px`/`768px` breakpoints. The hero card uses CSS Grid so the same markup reflows. CTAs are full-width on mobile, inline on desktop. Touch targets ≥ 48px throughout (existing tab nav already complies; CTAs need explicit min-height).

End state: an operator opening a fresh player file sees a hero card that immediately communicates *who this player is*, six tabs with badges that show *what's already on record*, and any empty tab gives a one-tap path to fix it. A senior player file with content shows the same hero, the same badges, full content per tab. The visual quality matches what an operator would expect from a paid product, not from a plugin's first cut.

## Scope

### 1. Hero card redesign

Replace the current `.tt-player-detail-hero` markup in [src/Shared/Frontend/FrontendPlayerDetailView.php:88-105](../src/Shared/Frontend/FrontendPlayerDetailView.php#L88-L105). New structure:

```
<header class="tt-player-hero">
  <figure class="tt-player-hero__photo-frame">
    <img class="tt-player-hero__photo" .../>  // empty placeholder when no photo
  </figure>
  <div class="tt-player-hero__body">
    <div class="tt-player-hero__primary">
      <span class="tt-player-hero__team">[team] · [age group]</span>
      <span class="tt-player-hero__status-pill">[status label]</span>
      <span class="tt-player-hero__age-tier">[age tier]</span>
    </div>
    <div class="tt-player-hero__journey">
      <span class="tt-player-hero__days">[N days in academy]</span>
      <span class="tt-player-hero__joined">[joined YYYY-MM-DD]</span>
    </div>
    <div class="tt-player-hero__latest">
      <a class="tt-player-hero__chip">Latest activity: [title] · [date]</a>
      <a class="tt-player-hero__chip">Latest evaluation: [date]</a>
      <a class="tt-player-hero__chip">Latest goal: [title]</a>
    </div>
  </div>
</header>
```

Latest chips are dropped (not rendered) when the corresponding record doesn't exist — the empty-state CTA inside the tab handles that case. Days-in-academy is computed from `tt_players.date_joined` (falls back to `created_at` when join date is null); when join date is null and `created_at` is also recent (< 7 days) the journey block reads "Joined recently" instead of a misleadingly small day count. Status pill reuses `LookupPill::render('player_status', ...)` for visual consistency with the rest of the dashboard.

Photo frame includes a placeholder block (initials, `aria-hidden="true"`, same dimensions as the real photo) when `photo_url` is empty so the hero never collapses. Initials are derived from the player's display name (first letter of first + last name).

Styles authored mobile-first in `assets/css/frontend-player-detail.css` (new file, enqueued by `FrontendViewBase::enqueueAssets()` for this view only). At < 480px the photo stacks above the body block, full-width-rounded; at ≥ 480px the photo sits left at fixed 96×96px and the body fills remaining width with CSS Grid (3 rows: primary, journey, latest). Latest chips wrap to multiple lines on narrow viewports.

### 2. Empty-state CTA component

New shared component `src/Shared/Frontend/Components/EmptyStateCard.php`:

```php
EmptyStateCard::render( [
    'icon'       => 'goals',          // svg key from existing icon set
    'headline'   => __( 'No goals yet for this player', 'talenttrack' ),
    'explainer'  => __( 'Goals capture what the player is working on this season — start with one.', 'talenttrack' ),
    'cta_label'  => __( 'Add first goal', 'talenttrack' ),
    'cta_url'    => $wizard_or_form_url,
    'cta_cap'    => 'tt_edit_goals',  // hide CTA when current_user_can() returns false
] );
```

The component decides on render whether to surface the CTA based on `current_user_can( $cta_cap )`. When the user lacks the cap, the CTA is omitted but the headline + explainer still render (so a scout viewing the file sees *"No goals yet for this player — coaches will record them as they're set."* rather than nothing).

Visual: vertically-centred block, ~280px tall on desktop (auto-height on mobile), light-grey rounded background (`var(--tt-bg-soft)`), 1px dashed border (`var(--tt-line)` muted), icon in `var(--tt-muted)`, primary CTA button in `var(--tt-primary)`. One component instance per empty tab.

Replaces every `<p><em>No … yet.</em></p>` line at:

- [FrontendPlayerDetailView.php:206](../src/Shared/Frontend/FrontendPlayerDetailView.php#L206) (Goals)
- [FrontendPlayerDetailView.php:237](../src/Shared/Frontend/FrontendPlayerDetailView.php#L237) (Evaluations)
- [FrontendPlayerDetailView.php:265](../src/Shared/Frontend/FrontendPlayerDetailView.php#L265) (Activities)
- [FrontendPlayerDetailView.php:293](../src/Shared/Frontend/FrontendPlayerDetailView.php#L293) (PDP)
- [FrontendPlayerDetailView.php:314](../src/Shared/Frontend/FrontendPlayerDetailView.php#L314) (Trials)

CTA URLs per tab (subject to wizard-availability check at implementation time — flat form fallback otherwise):

- Goals → `?tt_view=goals-manage&action=new&player_id=N` (flat form; goals wizard if it exists)
- Evaluations → `?tt_view=evaluations&action=new&player_id=N`
- Activities → empty state explains that activities are recorded at the team level; CTA links to `?tt_view=activities&action=new&team_id=<player's team>` when the player has a team; CTA suppressed when not (with explainer "Assign this player to a team first").
- PDP → `?tt_view=pdp-manage&action=new&player_id=N`
- Trials → `?tt_view=trial-cases&action=new&player_id=N`

### 3. Tab count badges

New helper `PlayerFileCounts::for( int $player_id ): array` in `src/Infrastructure/Query/PlayerFileCounts.php` — one query per tab type (5 queries, none of them expensive given the existing indexes), returns:

```php
[ 'goals' => 12, 'evaluations' => 4, 'activities' => 38, 'pdp' => 1, 'trials' => 0 ]
```

Profile tab has no count (it's always present). The render method calls `PlayerFileCounts::for()` once and passes the result into the tab nav. Each tab label becomes:

```html
<a class="tt-player-tab" href="...">
    <?php echo esc_html( $label ); ?>
    <?php if ( $count > 0 ) : ?>
        <span class="tt-tab-badge"><?php echo (int) $count; ?></span>
    <?php endif; ?>
</a>
```

When count is zero, no badge renders and the tab gets the modifier class `tt-player-tab--empty` (muted colour, no underline on hover). Active tab keeps its accent regardless of count.

### 4. Profile tab — visual restructure

Less critical than the hero or the empty states, but the profile tab today is a flat `<dl>` that doesn't scan well. Restructure into two columns on desktop (≥ 768px): left column "Identity" (DOB, position, foot, jersey, status), right column "Academy" (team, age tier, date joined, time at club). Stack to one column on mobile.

The behaviour-and-potential capture button stays where it is at the bottom of the profile tab.

### 5. Permission edge cases

- **Read-only observer / scout.** Sees the redesigned hero with all chips. Empty tabs show headline + explainer with no CTA. Tab badges still render counts (read access on goals/evaluations/activities/etc. is what the scout has).
- **Player viewing own file.** Should see the redesigned hero (all of it is data they own) and per-tab content. CTAs in empty states should be suppressed for the player's own view — players don't create their own goals / evaluations / etc. through this surface; that is a coach action. Treat player-viewing-own-file as a read-only observer for empty-state CTA purposes.
- **Parent viewing their child's file.** Same as player-viewing-own-file — read-only on this surface.
- **Coach with team-scope.** Sees full CTAs on every tab where the cap matches. The CTA URLs pre-fill `player_id` so the wizard / form lands with the player already selected.

### 6. CSS

New file `assets/css/frontend-player-detail.css` enqueued at view level. Overrides the existing hero block in the inline `<style>` at [FrontendPlayerDetailView.php:136-147](../src/Shared/Frontend/FrontendPlayerDetailView.php#L136-L147), which gets removed in this PR (inline `<style>` was a v3.77.0 shortcut; per the mobile-first migration recipe in `docs/architecture-mobile-first.md` view-level CSS goes in its own file).

Mobile-first authored. Touch-target compliance: hero chips ≥ 48×48px tap area (achieved with padding + line-height), tab badges have no separate tap target (the parent `<a>` already covers ≥ 48px), CTA buttons explicit `min-height: 48px`.

Color tokens consume the existing `var(--tt-...)` palette so theme operators picking up the colour customizer get the redesign for free.

### 7. Empty-state across the rest of the dashboard

This spec touches the player file only. The same empty-state pattern (component + permission-aware CTAs) is the right shape for every list view in the dashboard (Players list when zero players, Teams list when zero teams, Activities list, etc.) but a sweep across those is out of scope here; the component lands first and the operator can ask for a sweep when they see it work.

## Wizard plan

**Exemption** — this spec adds a new view-level component (`EmptyStateCard`) and refactors an existing detail view. No new record-creation flow ships in this PR. Empty-state CTAs link to existing creation flows (wizard if available, flat form otherwise).

## Out of scope

- **Mobile native app preview.** The hero is sized for the existing browser frontend; a future mobile native app reads the same data via REST and renders its own hero.
- **Avatar upload UX.** The photo placeholder when `photo_url` is empty is read-only — uploading a photo is done elsewhere (Players manage view). Adding an inline upload here is a follow-up.
- **Customisable hero blocks per persona.** Every persona sees the same hero. A future per-persona hero variant (e.g. coach sees "minutes played this month", scout sees "trial verdict") is shaped separately.
- **Activity feed / journey timeline at the top of the file.** The existing Journey view (`?tt_view=journey&player_id=N`) renders the chronological timeline. Linking to it from the hero is in scope (one of the latest chips); embedding it inline is not.
- **Empty-state CTAs across other dashboard views** (Players list, Teams list, Activities list, Goals list). Same shape, separate sweep — flag if the operator asks after this lands.
- **Skeleton loading state** while the page renders. The page is server-rendered; loading state is a follow-up if AJAX-loaded sections appear.
- **Animated illustrations on empty states.** The icon set covers static glyphs; animation is out of scope.

## Acceptance criteria

- [ ] Hero card on the player detail view renders the photo (or initials placeholder), team + age group, status pill, age tier badge, days-in-academy counter, and up to three "latest" chips (activity / evaluation / goal) that each link to the corresponding record.
- [ ] Hero card stacks correctly at 360px viewport (photo above body, no horizontal scroll) and reflows to side-by-side at ≥ 480px.
- [ ] Latest chip is omitted when the corresponding record doesn't exist; the chip block hides entirely when all three are empty (no orphan whitespace).
- [ ] Days-in-academy reads "Joined recently" when join date is null and `created_at` < 7 days; reads N days otherwise.
- [ ] Initials placeholder renders correctly (same dimensions as a photo) when `photo_url` is empty.
- [ ] Each of the five non-Profile tabs renders an `EmptyStateCard` (icon + headline + explainer + CTA) when the tab has zero records.
- [ ] CTA on an empty tab is suppressed when `current_user_can( $cta_cap )` returns false; headline + explainer still render.
- [ ] Player-viewing-own-file and parent-viewing-child see headline + explainer but no CTA on empty tabs.
- [ ] CTA URL on an empty tab pre-fills `player_id=N` and routes to the wizard variant where one exists, flat form otherwise.
- [ ] Activities tab empty state CTA is suppressed (with adjusted explainer) when the player has no team assigned.
- [ ] Tab navigation renders count badges next to each non-Profile tab when count > 0.
- [ ] Tab with zero records gets `tt-player-tab--empty` class (muted colour, no hover underline) but stays clickable and shows its empty-state CTA on click.
- [ ] `PlayerFileCounts::for()` makes 5 queries (one per tab type) and is the single source for the badge counts.
- [ ] Profile tab restructured into Identity / Academy two-column layout at ≥ 768px; single column at < 768px.
- [ ] Inline `<style>` block removed from `FrontendPlayerDetailView::render()`; styles live in `assets/css/frontend-player-detail.css`.
- [ ] CSS authored mobile-first (base styles for ≤ 480px, scale up via `min-width` queries). All interactive targets ≥ 48px.
- [ ] `languages/talenttrack-nl_NL.po` updated with Dutch translations for every new translatable string introduced (5 empty-state headlines, 5 explainers, 5 CTA labels, days-in-academy phrasing, "Joined recently", "Latest activity:" / "Latest evaluation:" / "Latest goal:" prefixes).
- [ ] `docs/players.md` + `docs/nl_NL/players.md` get a one-section update describing the new player-file UX.
- [ ] `EmptyStateCard` component is reusable — it has no player-file-specific logic baked in and lives in `src/Shared/Frontend/Components/`.

## Notes

### Why a hero, not a header

A "header" in HTML semantics is correct (and the markup uses `<header>`); "hero card" is the visual / product term. The point is that this is the player's anchor on the page, not a thin caption. Calling it a hero in the spec keeps everyone on the same page about the visual weight intended.

### Why empty-state component lives in `Components/`

Future use cases: every list view (Players list, Teams list, Activities list, Goals list, etc.) has the same shape — an empty result set with a "Create your first …" CTA. Putting the component in `src/Shared/Frontend/Components/` next to `FrontendBreadcrumbs`, `FrontendListTable`, etc. signals it's a reusable widget. The first consumer is the player file; the dashboard sweep follows when the operator asks.

### Why no skeleton loader

The page is fully server-rendered today and stays that way in this PR. A skeleton loader is meaningful when AJAX-loaded sections cause visible layout shift; it's not meaningful for a `[talenttrack_dashboard]` shortcode that emits the entire DOM in one response. If a future iteration moves any tab content to AJAX (e.g. the activities tab paginated client-side) the skeleton lands then.

### Why count badges aren't on the Profile tab

Profile is always present and always populated (the player's own row is the data source). A badge on it would always read 1 and add no information. Active-tab styling alone is enough.

### Why "days in academy" instead of "joined on YYYY-MM-DD"

Both render. The day-count is the scannable headline for an operator pulling up the file ("how long has this player been with us") and the date is the precise reference. The spec keeps both because the cost is one extra `<span>` and the operators consume each at different moments — coach doing a session reads days; admin checking a contract reads the date.

### Estimate

~6-8h. Hero markup + responsive CSS 2-3h, EmptyStateCard component 1-2h, wiring through the five tabs 1h, tab badge helper + query 1h, profile tab restructure 30min, NL translations + docs 1h. Single PR. Ships as v3.93.x (or whatever the next-available patch slot is at merge time).

### Sequence position relative to v3.93.0 (team chemistry rebuild) and #0081 (onboarding pipeline)

Independent. Touches `src/Shared/Frontend/FrontendPlayerDetailView.php` + adds two new files (`EmptyStateCard`, `PlayerFileCounts`) + a new CSS file. No overlap with team chemistry (separate module) or onboarding pipeline (separate views). Can ship in any order.
