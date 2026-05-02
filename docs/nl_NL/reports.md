<!-- audience: user, admin -->

# Rapporten

De tegel **Rapporten** is een launcher voor verschillende manieren om naar je gegevens te kijken.

## Spelersvoortgang

Snelle visuele rapporten voor coaches:

- **Spelersvoortgang** — radarcharts van je topspelers.
- **Spelervergelijking** — kies twee of meer spelers en zie hun laatste evaluaties als overlappende radars.
- **Teamgemiddelden** — gemiddelden per team over de hoofdcategorieën.

Voor diepere weergaven per speler, zie [Rate cards](?page=tt-docs&topic=rate-cards) en [Spelervergelijking](?page=tt-docs&topic=player-comparison).

## Teambeoordeling gemiddelden

Een eenvoudige tabel — één rij per team, één kolom per hoofdcategorie, plus een evaluatietelling. Toont het levenslange gemiddelde over actieve evaluaties van elk team. Gearchiveerde spelers en evaluaties tellen niet mee.

Een snelle manier om te zien welk team dit seizoen het sterkst is.

## Coachactiviteit

Hoeveel evaluaties elke coach heeft opgeslagen in het gekozen venster (laatste 7, 30, 90, 180 of 365 dagen). Handig om coaches te signaleren die achterlopen, of om te bevestigen dat een geplande beoordelingsperiode echt heeft plaatsgevonden.

## Frontendrapporten + Afdrukken/Opslaan als PDF (v3.79.0)

Team-gemiddelden en Coach-activiteit renderen nu rechtstreeks op het publieke dashboard via `?tt_view=reports&type=team_ratings` en `?type=coach_activity` — geen sprong meer naar wp-admin. Elk rapport heeft bovenaan een knop **Afdrukken / Opslaan als PDF**: bij klikken opent het printvenster van de browser met een stijlblad dat dashboard-elementen verbergt, zodat "Opslaan als PDF" een schone PDF oplevert.

Het oude rapport "Spelersontwikkeling & Radar" opent nog in wp-admin omdat het leunt op formulier-submit en Chart.js-infrastructuur die te omvangrijk is om mee te porten. Een toekomstige spec migreert dat.
