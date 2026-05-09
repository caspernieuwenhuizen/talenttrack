<!-- audience: user -->

# Team planning

De **Team planning** is de "wat doet mijn team deze week" kalender. Open hem via de tegel op het dashboard (groep Performance) of direct via `?tt_view=team-planner`.

## Wat zie je

Een raster met dagen, geordend per week. Elke dag is een kaart met de activiteiten voor die dag, met:

- De titel van de activiteit.
- Een gekleurd **statuslabel** — Gepland (geel), Voltooid (groen), Geannuleerd (rood). Hetzelfde label als in de activiteitenlijst. Geannuleerde activiteiten verdwijnen uit de planning — zodra je een activiteit annuleert, valt die weg uit het raster.
- De locatie (als die is ingevuld).
- Maximaal drie **principe-labels** als de activiteit aan coachingsprincipes is gekoppeld.

De kolom van vandaag heeft een gekleurde rand. Op dagen zonder activiteit verschijnt een knop `+ Toevoegen` (als je bewerkrechten hebt) die het activiteitenformulier opent met de datum en het team al ingevuld.

## Een venster kiezen

Gebruik de keuzelijst **Toon** in de werkbalk om te wisselen tussen:

- **Eén week** — zeven dagen, de standaardweergave. Het Ma–Zo raster dat overal in de plug-in standaard is.
- **Twee weken**, **Vier weken**, **Acht weken** — stapelt twee / vier / acht opeenvolgende weken onder elkaar. Elke week krijgt een kop *"Week van …"*.
- **Volledig seizoen** — elke week van begin tot eind van het **huidige seizoen** (de rij met `is_current` in de seizoenstabel). De weekgrenzen worden afgerond op hele weken zodat het raster netjes uitlijnt.

De keuzelijst gaat mee in de URL — sla `?tt_view=team-planner&team_id=12&range=4weeks&week_start=2026-05-04` op of deel hem en het zelfde venster opent voor de volgende persoon.

Bij week / 2 / 4 / 8 weken-vensters heeft de werkbalk **vorige / vandaag / volgende** knoppen die per gekozen vensterlengte verspringen — *Volgende 4 weken* gaat dus echt vier weken vooruit, niet zeven dagen. Bij Volledig seizoen worden de vorige/volgende knoppen vervangen door de seizoensnaam, want de seizoenskeuze is impliciet (altijd het huidige seizoen).

## Een team kiezen

De keuzelijst **Team** toont elk standaardteam waartoe je toegang hebt. Kies een team om de planning naar dat team te wisselen. Alleen standaardteams (zonder `team_kind`) verschijnen — stafgroepen en andere team-soorten worden eruit gefilterd.

## Een activiteit plannen

Twee paden:

- **De knop `+ Activiteit plannen` in de werkbalk** — opent het activiteitenformulier met het team alvast ingevuld en de activiteit op status `scheduled`.
- **De link `+ Toevoegen` op een lege dagkaart** — opent het activiteitenformulier met team én datum alvast ingevuld, ook op status `scheduled`.

In beide gevallen kom je in het activiteitenformulier en vul je dat verder in zoals altijd — titel, type, locatie, aanwezigheid, enzovoort.

## Status en de planning

De planning leest het veld **Status** uit het activiteitenformulier (Gepland / Voltooid / Geannuleerd). Wat je daar instelt, is wat op de planningskaart verschijnt. Er is geen aparte "planningsstatus" om in sync te houden — het activiteitenformulier is de enige bron van waarheid.

Een geplande activiteit blijft op *Gepland* (geel label) totdat je hem op het formulier op *Voltooid* (groen) zet, meestal zodra de aanwezigheid is geregistreerd. *Geannuleerde* activiteiten vallen helemaal weg uit de planning.

## Getrainde principes — laatste 8 weken

Onder het raster staat een klein paneel met de top tien getrainde coachingsprincipes van de afgelopen acht weken voor het gekozen team. De tellingen zijn gebaseerd op voltooide activiteiten — een geplande activiteit telt pas mee zodra je hem op Voltooid zet.

Dit paneel gebruikt het bestaande `tt_principles` raamwerk — er is geen aparte opslag. Tag je activiteiten met principes op het activiteitenformulier en ze verschijnen hier automatisch.

## Rechten

- **`tt_view_plan`** — vereist om de planning te openen. Standaard toegekend aan iedereen die activiteiten kan bekijken.
- **`tt_manage_plan`** — vereist om vanuit de planning nieuwe activiteiten te plannen. Standaard toegekend aan iedereen die activiteiten kan bewerken.

Wie activiteiten kan bekijken, kan de planning bekijken. Wie activiteiten kan bewerken, kan vanuit de planning plannen.

## Wat er nog niet in zit

- **Slepen-en-neerzetten herinplannen** — om een activiteit te verplaatsen, bewerk je hem op het activiteitenformulier en wijzig je de datum.
- **Inline aanmaak-modal** — klikken op `+ Toevoegen` brengt je naar het activiteitenformulier in plaats van een snelaanmaak-dialoog te openen. Het formulier is het canonieke aanmaak-oppervlak.
- **Meervoudige seizoenskiezer** — het Volledig seizoen-venster pakt altijd `is_current`. Wil je een toekomstig seizoen plannen, zet dan eerst de `is_current` vlag op dat seizoen onder PDP → Seizoenen.
