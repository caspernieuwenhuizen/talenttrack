<!-- audience: user -->

# Spelerstatus — stoplicht

Elke speler krijgt een **stoplichtstatus** — groen, oranje, rood of grijs — die samenvat hoe het ervoor staat. Het is de kop van elk gesprek over een speler; de onderbouwing staat één klik verder.

## Wat de kleuren betekenen

- **Groen** — op koers. Sterke evaluaties, aanwezig op trainingen, gedrag op orde.
- **Oranje** — op de rand. Cijfers vragen om aandacht; geen besluit, wel een signaal.
- **Rood** — de data geeft aan dat deze speler een interventiegesprek nodig heeft. Hoort thuis in een POP-gesprek, niet op een Post-it.
- **Grijs** — eerste beeld nog niet klaar. Nieuwe spelers of weinig data; het systeem heeft nog te weinig signaal.

Het algoritme markeert. Mensen besluiten. De POP-eindbeoordeling aan het eind van de cyclus is de formele call; het stoplicht is het kijkje daartussen.

## Hoe de kleur wordt bepaald

Standaardmethodiek (per release configureerbaar) weegt vier ingrediënten:

| Input | Weging | Wat het is |
| --- | --- | --- |
| Evaluaties | 40% | Gemiddelde evaluatiescore in de laatste 90 dagen |
| Gedrag | 25% | Gemiddelde gedragsobservatie in de laatste 90 dagen |
| Aanwezigheid | 20% | Aanwezigheidsratio bij trainingen in de laatste 90 dagen |
| Potentieel | 15% | Verwachting van de trainer over hoever de speler kan reiken |

Een gedragsscore onder 3.0 plafonneert de kleur op oranje, ongeacht de overige scores.

## Waar zie je het

- **Mijn teams → teampagina** — een gekleurde stip naast elke speler. Sorteerbaar, filterbaar.
- **Spelerdetail (beheer)** — dezelfde stip in het spelerspaneel.
- **REST API** — `GET /players/{id}/status` en `GET /teams/{id}/player-statuses` voor eigen dashboards of integraties.

Coaches en hoofd opleidingen zien de volledige onderbouwing (de vier deelscores + de overschreden drempels). Ouders en spelers zien alleen het zachte label ("Op koers" / "Extra aandacht" / "Kan nu extra ondersteuning gebruiken") — nooit cijfers, nooit interne stafterminologie.

## Inputs vastleggen

- **Gedragsobservaties** — de **Gedrag vastleggen**-popover op de hero van het spelersprofiel (geleverd in v4.8.0), of `POST /players/{id}/behaviour-ratings` voor integraties. Een 1-5 score met optionele notitie en gerelateerde activiteit.
- **Potentieel** — `POST /players/{id}/potential` met een van `first_team` / `professional_elsewhere` / `semi_pro` / `top_amateur` / `recreational`. Standaard alleen voor hoofd opleidingen.
- **Aanwezigheid + evaluaties** — al vastgelegd via de bestaande flows; de calculator leest ze direct.

## Rechten

- `tt_view_player_status` — zie de kleur. Geldt voor elke rol die spelers mag bekijken.
- `tt_view_player_status_breakdown` — zie de deelscores + redenen. Coaches + HO; **niet** voor ouders.
- `tt_rate_player_behaviour` — leg een gedragsobservatie vast. Coaches + HO.
- `tt_set_player_potential` — bepaal het potentieelniveau. Standaard alleen HO.
