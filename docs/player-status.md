---
title: Player status
group: performance
summary: 'Traffic-light status calculation: weights, thresholds, behaviour floor, behaviour + potential capture.'
audience: [user]
views: [player-status-capture, player-status-methodology, team-behaviour-capture]
module: TT\Modules\Players\PlayerStatusModule
capability: tt_view_player_status
order: 110
---

# Player status — traffic light

Each player carries a **traffic-light status** — green, amber, red, or grey — that summarises how things are going. It's the headline of every player conversation; the breakdown lives one click away.

## What the colours mean

- **Green** — on track. Solid evaluations, present at sessions, behaviour where you'd want it.
- **Amber** — on the edge. Numbers say it's worth paying attention; not a decision yet.
- **Red** — the data signals this player needs an intervention conversation. It belongs in a PDP meeting, not a sticky note.
- **Grey** — building first picture. New players or sparse data; the system doesn't yet have enough signal.

The algorithm flags. Humans decide. The PDP verdict at the end of the cycle is the formal call; the traffic light is the read between cycles.

## What goes into the colour

The shipped methodology weighs four inputs:

| Input | Weight | What it is |
| --- | --- | --- |
| Ratings | 40% | Average evaluation rating in the last 90 days |
| Behaviour | 25% | Average behaviour observation in the last 90 days |
| Attendance | 20% | Present-rate at sessions in the last 90 days |
| Potential | 15% | Trainer's stated belief about how high the player can reach |

A behaviour rating below the midpoint of your rating scale floors the colour at amber, regardless of the other scores.

**These are defaults, not fixed rules.** An academy admin sets its own weights, its own amber and red thresholds and its own behaviour floor under **Player status methodology**, either academy-wide or per age group. The weights must add up to 100; the screen says so and refuses to save a set that doesn't. The shipped defaults above apply until an override is saved, and **Reset** puts them back.

## Where you see it

- **My Teams → team page** — a coloured dot beside every player. Sortable, filterable.
- **Player detail (admin)** — same dot in the team-players panel.
- **REST API** — `GET /players/{id}/status` and `GET /teams/{id}/player-statuses` for any custom dashboard or integration.

Coaches and HoD see the full breakdown (the four input scores + the threshold reasons). Parents and players see only the soft label ("On track" / "Extra attention" / "Could use extra support right now") — never the numerics, never internal staff framing.

## A hollow dot means the colour was computed on less

The status is a weighted average of the inputs that actually have a value. If a player has no potential band recorded, potential is left out and the remaining weights are shared out between them — which is the right sum, but it means two players showing the same amber may have been judged on different evidence.

The dot now says so. **A dot with a hollow centre was computed without at least one of the weighted inputs**, and hovering it (or reading it with a screen reader) names which: *"Extra attention — Computed without potential."* A solid dot means every input the methodology asks for was there. The same sentence appears in the breakdown's reasons, so you see it on the player's file as well as on the squad table.

The signal is deliberately not a fifth colour. The colour still says where the player is; the ring says how much the academy actually knows before it says it.

Grey — **Building first picture** — is unchanged and still means *every* input is missing, not just one.

Integrations get the same thing as data rather than as a shape: the status payload carries `coverage` (0–1, the share of the weighted inputs that contributed), `missing_inputs` (the ones that did not) and `coverage_note` (the sentence). Sorting a squad by `coverage` is how you find the players nobody has assessed yet.

## Capturing the inputs

Behaviour and potential are recorded in these places:

| Where | Behaviour | Potential |
| --- | --- | --- |
| **Player profile** — the **+ Log behaviour** and **Set potential** buttons at the top of the page | yes | yes |
| **Behaviour & potential** screen — both forms with the history beneath them. Open it from the **history** link on the profile's **Potential** row, or from *View all behaviour ratings* in the Log behaviour form | yes | yes |
| **Team page → Roster → Bulk-record behaviour** — the whole squad on one screen | yes | — |
| **New evaluation → Evaluate 1 player → Behaviour today** — an optional step after the performance rating | yes | — |

A behaviour rating is a score on your academy's own rating scale (**Configuration → Rating scale**), with optional notes and a related activity. A potential band is one of First team, Professional elsewhere, Semi-pro, Top amateur or Foundation.

Attendance and evaluation ratings are captured by their own flows; the calculator reads them directly.

Integrations write the same records through `POST /players/{id}/behaviour-ratings` and `POST /players/{id}/potential` (band keys `first_team` / `professional_elsewhere` / `semi_pro` / `top_amateur` / `recreational`).

Both forms now say what they are asking for, on the screen rather than in this document.

The behaviour form names the ends of your configured scale and says the rating is about the week you just watched, not the player as a whole — the trend across ratings is what the status reads, so a single low week is information rather than a verdict.

The potential form asks how high you believe the player can reach **at their peak**, not where they are now, and carries a *What the bands mean* section next to the picker: one line each for First team, Professional elsewhere, Semi-pro, Top amateur and Foundation. Worth reading once as a staff group, because two coaches guessing at the bands is how the same player gets recorded differently.

### Potential is not asked below 13

The bands describe how far a player might go **as a professional**. That is a fair question to put to a coach about a teenager and a guess about a child, so TalentTrack does not ask it below age 13.

On a younger player the **Set potential** card says so instead of offering the bands, and the API refuses a write with the same reason. Behaviour ratings are unaffected at every age — how a child trains, listens and treats their teammates is a fair thing to record at seven.

Three things follow from this that are worth knowing:

- **The *Potential not revisited* alert skips them too.** Without that it would flag every player in a U7 squad the moment they had been on the books long enough, forever, with no way to clear it except recording exactly the judgement the rule exists to avoid.
- **Bands already recorded stay visible.** If your academy set potential on younger players before this rule existed, those entries still show on the profile and still draw the trajectory. What stops is being asked again.
- **A player with no date of birth on record is still asked.** A missing field is not evidence of being too young, and treating it as such would make a data gap look like a broken screen. Fill the date in and the rule applies.

The age is fixed at 13 rather than being a setting. It is where age-group football starts treating trajectory as a real question, and a configurable minimum is the kind of thing that gets set once and then quietly explains a gap nobody can find.

### How often potential is expected

Quarterly. The form says so, and it tells you where this player stands: when the band was last set, by whom, how many days ago, and whether that is now overdue. The threshold is your academy's own `alerts_potential_stale_days` setting — the same number the *Potential not revisited* alert uses, so the screen and the reminder can never disagree about what late means.

A player who has never had a band set says exactly that.

## The potential trajectory

Potential is not a label, it is a judgement the academy revises. Every time somebody sets it, that becomes a new dated entry — nothing is overwritten — so the record shows how the club's view of a player has moved.

The **Behaviour & potential** screen now shows that sequence under the current band, newest first. Each entry gives the band, when it was set and by whom, any notes that came with it, and how it changed:

- **▲ revised up** — toward the first team.
- **▼ revised down** — away from it.
- **= reaffirmed** — the same band recorded again. That happens deliberately: re-stating a band *with notes* ("still first team, but the last six weeks have been flat") is a real act and is kept, while re-saving the same band with nothing to add records nothing.

The direction is written in words as well as shown with an arrow and a colour, so it reads the same to somebody who cannot distinguish the colours or is using a screen reader.

A player with one entry gets no history section — there is no trajectory yet, and the current band above already says everything there is to say.

The player profile shows the current band as a **Potential** row, with a **history** link to this screen when there is more than one entry. It is shown to staff only — a player or parent on their own profile does not get a link to a screen they cannot open.

Two downward revisions in a season is the case this exists for. It is a strong development signal, it was always in the data, and until now nobody could see it without opening the PDP.

`GET /players/{id}/potential` returns the same series for an integration, with the current band alongside it.

## Turning behaviour or potential off

Not every academy works this way, and neither has to be used. There are **three** switches, and they answer three different questions — an academy that wants to stop entirely usually wants all three.

| Question | Where | What it does |
| --- | --- | --- |
| Do we record this at all? | **Modules & features** → *Behaviour rating* / *Potential rating* | Stops new capture and hides the entry points — the profile affordance, the relevant half of the capture screen, and (for behaviour) the evaluation wizard step and the team bulk grid. |
| Does it count toward the traffic light? | **Player-status methodology** → the *enabled* box on that input | Drops the input from the calculation. The remaining inputs are re-weighted so the status is not dragged down by a missing one. |
| Do we get reminded about it? | **Alert policy** → *Potential not revisited* → *force off* | Silences the reminder for the whole club. |

Three things worth knowing before you flip anything:

- **The screen becomes a history.** When nothing may be captured — the academy switched both halves off, or you personally hold neither capability — the **Behaviour & potential** screen drops the forms and shows what is on record instead: the recent behaviour ratings, the current band, and the trajectory behind it. It says in one line that nothing is being recorded here, and then shows what was. This is where the profile's **history** link lands, so a coach who may read a player's file but not record against it follows them to the record rather than to an empty page. A viewer who may not read the player's file at all still gets the line and nothing else.
- **Existing records are always kept.** Switching capture off does not delete or hide anything: the band on a profile, the potential trajectory and every behaviour rating stay readable exactly as they were, and reappear in the forms if you switch it back on. Off means *stop asking us for this*, not *hide what we already decided*.
- **Switching off capture also silences the potential reminder**, so you do not have to find the alert screen as well. It does **not** remove the input from the traffic light — that is a separate decision, because an academy might stop recording new bands while still wanting the last one to count.

## Capabilities

- `tt_view_player_status` — see the colour. Granted to every role that can view players.
- `tt_view_player_status_breakdown` — see the input scores + reasons. Coaches + HoD; **not** parents.
- `tt_rate_player_behaviour` — log a behaviour observation. Coaches + HoD.
- `tt_set_player_potential` — set a potential band. HoD-only by default.

### …and the capability is only half the answer

Each of those says what kind of thing you may do. **Which** players you may do
it to is your team scope, and the status routes now ask both.

- Reading one player's status asks the same question the player's profile
  asks, so a parent reads their own child and nobody else's, and a coach reads
  their own squads.
- Reading a whole team's statuses asks whether you may read that team's player
  statuses — scoped on player status, not on teams, so a Head of Development
  granted academy-wide status read still gets every board.
- Logging a behaviour observation asks whether you may edit that player. The
  roles that hold `tt_rate_player_behaviour` already could, for their own
  players; what changes is that the write can no longer land on a child
  outside the coach's squads.

Setting a potential band is unchanged: its capability goes only to Head of
Development, Club Admin and administrator — academy-wide roles by design, for
whom "any player" is the correct scope.
