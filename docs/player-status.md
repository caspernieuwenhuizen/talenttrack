<!-- audience: user -->

# Player status — traffic light

Each player carries a **traffic-light status** — green, amber, red, or grey — that summarises how things are going. It's the headline of every player conversation; the breakdown lives one click away.

## What the colours mean

- **Green** — on track. Solid evaluations, present at sessions, behaviour where you'd want it.
- **Amber** — on the edge. Numbers say it's worth paying attention; not a decision yet.
- **Red** — the data signals this player needs an intervention conversation. It belongs in a PDP meeting, not a sticky note.
- **Grey** — building first picture. New players or sparse data; the system doesn't yet have enough signal.

The algorithm flags. Humans decide. The PDP verdict at the end of the cycle is the formal call; the traffic light is the read between cycles.

## What goes into the colour

Default methodology (admin-configurable in a future release) weighs four inputs:

| Input | Weight | What it is |
| --- | --- | --- |
| Ratings | 40% | Average evaluation rating in the last 90 days |
| Behaviour | 25% | Average behaviour observation in the last 90 days |
| Attendance | 20% | Present-rate at sessions in the last 90 days |
| Potential | 15% | Trainer's stated belief about how high the player can reach |

A behaviour rating below 3.0 floors the colour at amber, regardless of the other scores.

## Where you see it

- **My Teams → team page** — a coloured dot beside every player. Sortable, filterable.
- **Player detail (admin)** — same dot in the team-players panel.
- **REST API** — `GET /players/{id}/status` and `GET /teams/{id}/player-statuses` for any custom dashboard or integration.

Coaches and HoD see the full breakdown (the four input scores + the threshold reasons). Parents and players see only the soft label ("On track" / "Extra attention" / "Could use extra support right now") — never the numerics, never internal staff framing.

## Capturing the inputs

- **Behaviour ratings** — the **Log behaviour** popover on the player profile hero (shipped v4.8.0), or `POST /players/{id}/behaviour-ratings` for integrations. A 1-5 score with optional notes and a related activity.
- **Potential** — `POST /players/{id}/potential` with one of `first_team` / `professional_elsewhere` / `semi_pro` / `top_amateur` / `recreational`. HoD-only by default.
- **Attendance + ratings** — already captured by the existing flows; the calculator reads them directly.

## Capabilities

- `tt_view_player_status` — see the colour. Granted to every role that can view players.
- `tt_view_player_status_breakdown` — see the input scores + reasons. Coaches + HoD; **not** parents.
- `tt_rate_player_behaviour` — log a behaviour observation. Coaches + HoD.
- `tt_set_player_potential` — set a potential band. HoD-only by default.
