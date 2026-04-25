<!-- type: feat -->

# Guest-player attendance — record players who train but aren't on the roster

Origin: post-#0019 v3.12.0 idea capture. Coaches frequently train sessions where one or two players from another team attend (call-up, trial, replacement for an injured regular). Today the only way to capture them in the attendance roster is to permanently add them to the team — which pollutes the roster, the podium, and the team-level statistics.

## Why this matters

- **Real-world flow today**: coach runs a training, two extra players arrive. Coach either (a) silently ignores them in the attendance form, losing the data, or (b) temporarily adds them to the team, then forgets to remove them. Both outcomes degrade the data.
- **Data integrity**: team podium, team-level stats, and team chemistry calculations are all distorted by ad-hoc roster additions.
- **Coaching reality**: friendly games, talent-day trials, and U-team players being trialed up to the U+1 are normal. The plugin should make this a first-class concept.

## Proposal

Add a "guest" attendance row to the session attendance flow. A guest:

- Is identified by either an existing `tt_players` record (cross-team pick) OR a free-text name + age + position (anonymous trial).
- Their attendance row is tagged `is_guest = 1` on `tt_attendance`.
- They do NOT appear in team roster, team podium, or team-level rolling stats.
- They DO appear in their own player profile if linked to a `tt_players` record (so the host coach's eval shows up in the away coach's view).

## Open questions to resolve before shaping

1. **Guest evaluations.** Can the host coach evaluate the guest? Spec direction: yes, but the eval is owned by the host coach and visible to both clubs (host's team page, guest's profile). What happens if guest belongs to no team yet (anonymous trial)?
2. **Anonymous-trial cleanup.** A trialled player who's never linked to a `tt_players` record creates a dangling free-text guest entry. Allow promotion to a real player after the fact? Auto-archive after N days?
3. **Cap interactions.** When monetization (#0011) ships, do guest players count against the player-cap of the host club? The guest's home club? Both? The cleanest answer is "neither" — guests are not first-class players for caps; capacity is a cross-club concept TalentTrack doesn't model.
4. **Filter UX.** Attendance roster needs a "+ add guest" button alongside the existing roster rows. Where does it sit visually? How does it behave on the new `FrontendListTable` component?
5. **Reporting.** Should guest entries surface in any report? "Players we trialed in this period" is genuinely useful for HoD review. Add to the player-stats service when shaping.

## Touches (when shaped)

- Schema: `is_guest BOOLEAN` + `guest_name`, `guest_age`, `guest_position` columns on `tt_attendance`.
- Migration: nullable, default 0; existing rows unaffected.
- Frontend: attendance roster gets a "+ add guest" affordance. Guest rows render distinctively (italic? badge?).
- Eval form: when entering an eval against a guest, gate by host-coach + show clear "this is a guest" callout.
- Player profile: if guest is linked to a real `tt_players`, show host-coach evals on that player's profile.
- `.po` strings — small (UI labels only).
- Documentation update.

## Sequence position (proposed)

Post-#0019. Independent of the rest of the backlog except #0017 (trial player module), which has overlapping conceptual territory — a "trialist" is essentially a long-running guest with a decision flow attached. Worth checking during shaping whether #0026 should ship inside #0017 rather than as a separate idea.
