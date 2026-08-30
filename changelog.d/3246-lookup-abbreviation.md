# Positions carry their own abbreviation, per language (#3246)

Bump: patch

The short code next to a position — `GK`, `CB`, `CDM` — was never a field. It was the internal key, printed raw. The eleven positions TalentTrack seeds got away with that because their keys happen to be football codes; a position an academy adds itself did not, and showed up on the player form as `linker_middenvelder`.

Positions now have an **Abbreviation** field in Configuration → Lookups, with a slot per language: `GK` in English, `K` in Dutch, without either of them touching the identifier the rest of the system joins on. Where a position has no abbreviation the full translated label is shown — never the key again — so a newly added position reads correctly whether or not anyone fills in a code. The position filter on the players list, which had the same defect, now shows labels too.

The seeded positions keep their English codes, so nothing changes on an existing install. Dutch codes are deliberately not seeded: the obvious ones collide (*linksback* and *linksbuiten* both want `LB`), and an English code an operator can see needs replacing beats a wrong Dutch one.

The abbreviation is display only. Chemistry, formation slots and squad selection all still key on the internal key, and a test pins that.
