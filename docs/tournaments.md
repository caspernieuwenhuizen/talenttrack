<!-- audience: user -->

# Tournaments

A **tournament** in TalentTrack is a container for a set of matches you play in a single day or weekend, with shared squad and shared playing-time goals. The tournament planner is built to answer one question the coach asks every weekend:

> *Across these matches, who plays which position when, how does it compare to everyone else, and who haven't I started yet?*

Tournaments are admin-only in v1. Academy Admins create and run them; Coaches and Head of Development don't see the feature until a follow-up ship adds them.

## Creating a tournament

1. Open the **Tournaments** tile and tap **+ New tournament**.
2. The wizard walks you through five steps:
   - **Basics** — name, anchor team, start date, optional end date. The tournament's format (7v7 / 9v9 / 11v11) is derived automatically from the anchor team's age group; no manual Format dropdown.
   - **Formation** — pick a default formation (e.g. `1-3-4-3`) from a radio-card grid. Each card shows a tiny dot glyph of the formation shape. You can override per match later.
   - **Squad** — tick the players in the squad from the anchor team's roster, and for each one tap chips for the **specific positions** they can play: GK · CB · LB · RB · DM · CM · AM · LW · RW · ST. Defaults are seeded from each player's preferred positions. Trial players appear in the list with a `Trial` badge but are unchecked by default — tap their row to add them. A live count above the list reads "X in squad · Y not picked".
   - **Matches** — each match is its own card with a sequence circle and a live headline ("vs Den Helder JO13", "Final", "New match — fill in opponent below"). Fields: label, opponent, level, formation override, duration, substitution windows. Subs use a chip editor: type a minute and press Enter or comma to add a chip; Backspace from an empty input pops the last chip; click × to remove. The hint "Values must be 1–N" live-updates as you change the duration. Tap **+ Add another match** to append a blank card; **Remove** drops the card and re-numbers the rest.
   - **Review** — one card per upstream step with an **Edit** link in the top-right corner that jumps the wizard back to that step (preserving everything you've entered). Then tap **Create tournament**.
3. You land on the tournament detail page.

### Adding a match after creation

The detail page carries a **+ Add match** button next to **Edit**. It opens a standalone form (the same field grid + chip editor as the wizard's matches step) with one extra select: **Position in sequence**. Pick "Insert at end" or any "Insert before Match N" option to slot the new match into the running order; existing matches shift their `sequence` accordingly.

## Substitution windows — what they mean

The number of minutes after kickoff at which a swap happens. They determine how many **periods** the match has: `N windows → N+1 periods`.

- A 20-min match with `[10]` → two periods of 10 minutes each.
- A 60-min match with `[20, 40]` → three periods of 20 minutes each.
- A 30-min match with `[]` (empty) → one period; no subs allowed mid-match.

## The planner detail view

The detail view of a tournament shows:

- **Facts strip** — team, dates, default formation, squad size, match count.
- **Matches** — one card per match. Tap **Open planner grid** to expand the per-match lineup grid.
- **Minutes ticker** — sticky bottom strip on mobile, right sidebar on desktop. Always visible. One card per squad player showing:
  - A green/amber/red bar with played + scheduled minutes vs the equal-share target.
  - ⚡ start count.
  - 🏆 full-match count.
  - Sort dropdown: **Default / Fewest minutes / Fewest starts / No full matches** so under-served players bubble to the front.

## Per-match planner grid

The grid lays out one row per formation slot (`GK`, `RB`, `CB`, …), one column per period.

- Tap a player chip — it highlights yellow.
- Tap another chip or empty cell — the two slots swap.
- Tap the same chip again to deselect.

The bench row at the bottom collects players not on the pitch in each period. Drag a player from the bench to a slot the same way: tap chip, tap target.

**Eligibility warnings**: a player placed in a slot they're not eligible for shows an amber dot. It's a warning, not a block — coach judgment wins.

## Auto-balance

The **Auto-balance** button on each match card runs a greedy assignment that fills the grid based on:

- Eligibility (only players whose position types match the slot can fill it).
- Equal-share fairness (the player furthest from their target minutes gets first pick).
- Starts distribution (for period 0, players with the fewest starts get priority).
- No back-to-back bench (a player benched in the previous period drops in the ranking).

Auto-balance is a **starting point**, not a constraint solver. Drag, swap, and tweak after.

## Opponent level

Each match has an opponent level — by default **weaker / equal / stronger / much stronger**. The pill on the match card is colour-coded green → grey → amber → red so you see at a glance which matches need your strongest lineup.

The auto-balancer **does not** auto-weight by opponent level. That's coach judgment; the tool shows the data and you apply the judgment via manual swaps.

## Kicking off and completing a match

- **Kick off** — promotes the planned match to a real activity. The match shows up on the player journey, on the team's activity list, and on the existing match-day team sheet exporter.
- **Complete match** — sets the match's completion timestamp, syncs the period-0 starting lineup to **attendance**: every player who started is marked `start` with their period-0 position; benched players are marked `bench`. Played minutes flip from "expected" to "played" in the ticker.

You can **Complete** a match without explicit Kick off — the system will auto-kick-off first, so the common "the match just finished" flow is a single button tap.

## Editing a completed match

By default a completed match's lineup is locked. The system blocks PATCHes to its assignments unless you pass `force=1`. If you spot a mistake after marking complete, an admin can re-open via the REST API; UI surface for this is a planned follow-up.

## What v1 doesn't do

- **Cross-team squad picks from the wizard** — you can pick from the anchor team's roster only in v1. Add players from another team via the REST API for now.
- **Constraint solver** — no "Casper must play GK every match" or similar. Use manual overrides; the manual layer is the constraint layer in v1.
- **Auto-weight by opponent level** — the tool shows the level, the coach picks the lineup.
- **Uneven substitution-window splits** — periods are derived from `duration_min ÷ (windows + 1)` assuming even splits.
- **Per-player tournament tab on the player profile** — minutes/starts/full-matches are queryable from the tournament view in v1; a per-player rollup tab is tracked as a follow-up (idea #0094).

## Who can see this

In v1 the Tournaments tile, the planner, and every REST endpoint are gated to **Academy Admin** (WP `administrator` + `tt_club_admin`) only. Coach, Head of Development, Scout, Player, and Parent personas don't see the feature. The persona-expansion ship will open it to Coach + HoD.
