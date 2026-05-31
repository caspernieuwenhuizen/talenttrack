# VCT PHV flag — design notes (VCT-14)

PHV = "Physical / Health / Vitality" — the catch-all flag for
players who need workload adjustment (injury recovery, asthma,
heart condition, temporary fatigue).

## Where it lives

- **Player profile → Profile tab**: dedicated PHV panel with
  checkbox + reason picker + intensity-ceiling picker + free-text
  toelichting.
- **Player hero**: orange `PHV` pill next to the name when flagged.
- **VCT session wizard step 4** (workload check): flagged players
  appear in the exclusion list automatically when a block's
  intensity exceeds the player's ceiling.
- **VCT coach-view sideline**: bottom-banner repeats the active
  session's PHV exclusions so the coach doesn't forget at the pitch.
- **Match prep "Doen per speler"**: if the coach attaches an
  attention-point, the PHV pill shows alongside it.

## Visibility

- Staff (coach, HoD, admin) — full visibility (pill + reason + ceiling).
- Other parents — NO visibility (medical information is per-player
  + gated to the parent of that specific player + staff).
- Pilot AC scope (#1060) — AC has `players` matrix entity at team
  scope but loses `pdp_*` / `evaluations`. PHV is a player-attribute,
  not a development artifact, so AC sees the pill but cannot edit
  the reason (which is more clinical).

## Friction points

| # | Friction | Mockup response |
|---|---|---|
| 1 | Coach forgets the PHV reason between sessions | Pill is permanently on hero — never out of sight |
| 2 | Reason field can leak medical detail to wrong audience | Free-text hint clarifies who sees this; reason picker is enum to discourage long text |
| 3 | Wizard exclusions feel arbitrary | Intensity-ceiling makes the rule explicit per-player |

## Open questions

- Should the PHV ceiling drift over time (e.g. "raise to 4 from 1 jul")?
  Mockup doesn't model recovery dates — pilot can request if needed.
- Should setting PHV trigger a notification to the team's HC?
  Recommend yes, mirrors the eval-shared notification pattern.
