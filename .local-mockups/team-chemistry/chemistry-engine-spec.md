# Chemistry Engine — target specification

**Locked target spec for the chemistry rework.** Pilot-supplied 2026-05-29.
Replaces the algorithm documented in chemistry-logic.md.

## 1. Objective

Calculate Player-to-Player, Unit, Lineup, and Team Chemistry on a 0-100 scale.

## 2. Data Model — 10 attribute groups

- A: Role (preferred role/position, playing profile, tactical role)
- B: Physical (speed, endurance, strength, agility)
- C: Technical (ball control, passing, dribbling, finishing)
- D: Tactical (positioning, game intelligence, defensive/spatial awareness)
- E: Mental (resilience, confidence, concentration, leadership)
- F: Behaviour (coachability, discipline, team orientation, professionalism)
- G: Development (potential, development forecast, ceiling estimate)
- H: Experience (tenure, minutes, appearances, shared matches)
- I: Demographic (DOB, age category, relative age)
- J: Footedness (left/right/both)

## 3. Pair Count

Pair Count = N x (N - 1) / 2. N=11 -> 55 pairs.

## 4. Five Components per Pair (each 0-100)

- 4.1 Compatibility (Groups A, B, C, D, E, J)
- 4.2 Familiarity (shared participation, minutes, appearances, tenure overlap)
- 4.3 Development (age, maturity, potential differences)
- 4.4 Behaviour (Group F + team orientation)
- 4.5 Performance (shared match outcomes, points/match, goal difference)

## 5. Pair Chemistry Formula

PairScore = W1*Compatibility + W2*Familiarity + W3*Development + W4*Behaviour + W5*Performance,
where W1+W2+W3+W4+W5 = 100%.

Default weights: 35 / 25 / 10 / 15 / 15.

## 6. Relationship Weighting — configurable Position Relationship Matrix

- Adjacent: 1.0
- Connected: 0.8
- Indirect: 0.5
- Minimal interaction: 0.2

## 7. Unit Chemistry

Goalkeeper / Defensive / Midfield / Attacking. Weighted average of pair scores within the unit. 0-100.

## 8. Lineup Chemistry

Weighted average of all pair scores using the Position Relationship Matrix. 0-100.

## 9. Team Chemistry

Average of Lineup Chemistry across a configurable time period (last 5, last 10, season to date). 0-100.

## 10. Categories

90+ Exceptional, 80-89 Strong, 70-79 Good, 60-69 Moderate, 50-59 Weak, <50 Poor.

## 11. Explainability

Engine must surface: overall score, component scores, pair scores, strongest/weakest relationships,
improvement recommendations.

## 12. Future-Proof

Architecture supports event data, tracking, GPS, video, passing network analysis, ML models without redesign.
