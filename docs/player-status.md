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
| Potential | 15% | Where the trainer places the player against their age group and the academy pathway |

A behaviour rating below the midpoint of your rating scale floors the colour at amber, regardless of the other scores.

**These are defaults, not fixed rules.** An academy admin sets its own weights, its own amber and red thresholds and its own behaviour floor under **Player status methodology**, either academy-wide or per age group. The weights must add up to 100; the screen says so and refuses to save a set that doesn't. The shipped defaults above apply until an override is saved, and **Reset** puts them back.

## Where you see it

- **My Teams → team page** — a coloured dot beside every player. Sortable, filterable.
- **Player detail (admin)** — same dot in the team-players panel.
- **REST API** — `GET /players/{id}/status` and `GET /teams/{id}/player-statuses` for any custom dashboard or integration.

Coaches and HoD see the full breakdown (the four input scores + the threshold reasons).

**Staff only.** Players and parents see neither the status nor the potential band, not even for their own record. Both are the academy's own judgement of a child: how they are doing, and where they stand against their age group. A parent reading "potential: needs time, revised down twice this season" without the conversation that should come with it is exactly what this rule prevents. The conversation is where that judgement belongs. The family's version of the player report leaves both out for the same reason. An academy that deliberately wants families to see the status can grant `player_status` to the parent or player persona in the Authorization matrix. The default does not.

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
| **Player profile → Behaviour & potential card** — where each stands, with **Log behaviour** and **Set potential** buttons. The same two buttons sit at the top of the page | yes | yes |
| **Behaviour & potential** screen — both forms with the history beneath them. Open it from the card's **History** link, or from *View all behaviour ratings* in the Log behaviour form | yes | yes |
| **Team page → Roster → Bulk-record behaviour** — the whole squad on one screen | yes | — |
| **New evaluation → Evaluate 1 player → Behaviour today** — an optional step after the performance rating | yes | — |

### The Behaviour & potential card

Staff opening a player's profile see one card for both inputs, next to Identity:

- **Behaviour** — the latest rating, when it was given and by whom, and the average over the last 90 days — the figure the traffic light reads.
- **Potential** — the current band, how many days ago it was set and by whom, and **Due a look.** once it is older than your academy's revisit window.

A half with nothing recorded says so — *No behaviour recorded yet.*, *No potential band set yet.* — and shows the button beside it if you may record it. A half your academy has switched off is left out, and a player or parent looking at their own profile never sees the card.

A behaviour rating is a score on your academy's own rating scale (**Configuration → Rating scale**), with optional notes and a related activity. A potential band says where the player stands against their age group and the academy pathway: Exceptional, Ahead of age group, On track, Needs time or Below academy level.

Attendance and evaluation ratings are captured by their own flows; the calculator reads them directly.

Integrations write the same records through `POST /players/{id}/behaviour-ratings` and `POST /players/{id}/potential` (band keys `exceptional` / `ahead` / `on_track` / `needs_time` / `below_level`).

Both forms now say what they are asking for, on the screen rather than in this document.

The behaviour form names the ends of your configured scale and says the rating is about the week you just watched, not the player as a whole — the trend across ratings is what the status reads, so a single low week is information rather than a verdict.

The potential form asks where the player stands against their age group and the academy pathway, and carries a *What the bands mean* section next to the picker: one line each for the five bands. Worth reading once as a staff group, because two coaches guessing at the bands is how the same player gets recorded differently.

| Band | What it means |
| --- | --- |
| Exceptional | Well beyond their age group; a candidate to play a year up. |
| Ahead of age group | Ahead of what the pathway expects at this age. |
| On track | Where the pathway expects a player of this age to be. |
| Needs time | Behind their age group for now, with good reason to give them time. |
| Below academy level | Below the level the academy works at for this age group. |

### Why the bands are relative to the age group

A youth academy decides whether to keep a player, push them up a year, give them time or let them go. It does not decide how far a child will go as an adult, and asking a coach for that about a nine-year-old gets a guess. So the bands place a player against their own age group, which a coach can answer honestly at any age, and potential can be set on every player.

You can rename a band in **Configuration → Potential bands**, per language. The new name shows everywhere the band appears: the profile card, the popover, this screen, the trajectory, the potential overview and the exports. The band's order and the score it gives the traffic light stay the same, so renaming never moves a player's status.

### How often potential is expected

Quarterly. The form says so, and it tells you where this player stands: when the band was last set, by whom, how many days ago, and whether that is now overdue. The threshold is your academy's own `alerts_potential_stale_days` setting — the same number the *Potential not revisited* alert uses, so the screen and the reminder can never disagree about what late means.

A player who has never had a band set says exactly that.

## The potential trajectory

Potential is not a label, it is a judgement the academy revises. Every time somebody sets it, that becomes a new dated entry — nothing is overwritten — so the record shows how the club's view of a player has moved.

The **Behaviour & potential** screen now shows that sequence under the current band, newest first. Each entry gives the band, when it was set and by whom, any notes that came with it, and how it changed:

- **▲ revised up** — further ahead of the age group.
- **▼ revised down** — further behind it.
- **= reaffirmed** — the same band recorded again. That happens deliberately: re-stating a band *with notes* ("still ahead, but the last six weeks have been flat") is a real act and is kept, while re-saving the same band with nothing to add records nothing.

The direction is written in words as well as shown with an arrow and a colour, so it reads the same to somebody who cannot distinguish the colours or is using a screen reader.

A player with one entry gets no history section — there is no trajectory yet, and the current band above already says everything there is to say.

The profile's Behaviour & potential card shows the current band, with a **History** link to this screen when there is more than one entry. The card is shown to staff only — a player or parent on their own profile does not get a link to a screen they cannot open.

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

- **The screen becomes a history.** When nothing may be captured — the academy switched both halves off, or you personally hold neither capability — the **Behaviour & potential** screen drops the forms and shows what is on record instead: the recent behaviour ratings, the current band, and the trajectory behind it. It says in one line that nothing is being recorded here, and then shows what was. This is where the profile card's **History** links land, so a coach who may read a player's file but not record against it follows them to the record rather than to an empty page. A viewer who may not read the player's file at all still gets the line and nothing else.
- **Existing records are always kept.** Switching capture off does not delete or hide anything: the band on a profile, the potential trajectory and every behaviour rating stay readable exactly as they were, and reappear in the forms if you switch it back on. Off means *stop asking us for this*, not *hide what we already decided*.
- **Switching off capture also silences the potential reminder**, so you do not have to find the alert screen as well. It does **not** remove the input from the traffic light — that is a separate decision, because an academy might stop recording new bands while still wanting the last one to count.

## Capabilities

- `tt_view_player_status` — see the colour and the potential trajectory. Granted to the staff roles that can view players; **not** to players or parents.
- `tt_view_player_status_breakdown` — see the input scores + reasons. Coaches + HoD; **not** parents.
- `tt_rate_player_behaviour` — log a behaviour observation. Coaches + HoD.
- `tt_set_player_potential` — set a potential band. Head coaches (for their own squads) + HoD.

### …and the capability is only half the answer

Each of those says what kind of thing you may do. **Which** players you may do
it to is your team scope, and the status routes now ask both.

- Reading one player's status asks the same question the player's profile
  asks, so a coach reads their own squads and nobody else's. A parent or a
  player is refused, because families hold no status read at all.
- Reading a whole team's statuses asks whether you may read that team's player
  statuses — scoped on player status, not on teams, so a Head of Development
  granted academy-wide status read still gets every board.
- Logging a behaviour observation asks whether you may edit that player. The
  roles that hold `tt_rate_player_behaviour` already could, for their own
  players; what changes is that the write can no longer land on a child
  outside the coach's squads.
- Setting a potential band asks the same question. Head coaches set bands for
  the squads they coach and are refused on anyone else's player; Head of
  Development, Club Admin and administrator hold it academy-wide. Assistant
  coaches do not set potential.
