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

## Capturing the inputs

- **Behaviour ratings** — the **Log behaviour** popover on the player profile hero, or `POST /players/{id}/behaviour-ratings` for integrations. A 1-5 score with optional notes and a related activity.
- **Potential** — `POST /players/{id}/potential` with one of `first_team` / `professional_elsewhere` / `semi_pro` / `top_amateur` / `recreational`. HoD-only by default.
- **Attendance + ratings** — already captured by the existing flows; the calculator reads them directly.

## The potential trajectory

Potential is not a label, it is a judgement the academy revises. Every time somebody sets it, that becomes a new dated entry — nothing is overwritten — so the record shows how the club's view of a player has moved.

The **Behaviour & potential** screen now shows that sequence under the current band, newest first. Each entry gives the band, when it was set and by whom, any notes that came with it, and how it changed:

- **▲ revised up** — toward the first team.
- **▼ revised down** — away from it.
- **= reaffirmed** — the same band recorded again. That happens deliberately: re-stating a band *with notes* ("still first team, but the last six weeks have been flat") is a real act and is kept, while re-saving the same band with nothing to add records nothing.

The direction is written in words as well as shown with an arrow and a colour, so it reads the same to somebody who cannot distinguish the colours or is using a screen reader.

A player with one entry gets no history section — there is no trajectory yet, and the current band above already says everything there is to say.

The player profile shows the current band as a **Potential** row, with a **history** link to this screen when there is more than one entry. Like the status history link, it is shown to staff only — a player or parent on their own profile does not get a link to a screen they cannot open.

Two downward revisions in a season is the case this exists for. It is a strong development signal, it was always in the data, and until now nobody could see it without opening the PDP.

`GET /players/{id}/potential` returns the same series for an integration, with the current band alongside it.

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
