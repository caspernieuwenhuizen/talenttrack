<!-- audience: user, admin -->

# Rapporten

De tegel **Rapporten** is een launcher voor verschillende manieren om naar je gegevens te kijken. De rapporten zijn gegroepeerd op doel zodat je het juiste rapport snel vindt: **Ontwikkeling & prestaties** (beoordelingen, voortgang, rate cards), **Speeltijd** (gespeelde minuten en selectiebelasting), **Werving** (scouting, prospects, trial funnel), **Staf & kwaliteit** (coachactiviteit en beoordelingskwaliteit) en **Seizoensoverzicht** (de jaarlijkse review). Secties waartoe je geen toegang hebt — werving en seizoensbrede rapporten zijn alleen voor academy-beheerders — verschijnen gewoon niet.

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

## Coach · Evaluatiekwaliteit (v4.20.123)

De evaluatie-steekproef van het hoofd opleiding als rapport: één rij per coach met het aantal evaluaties, het aantal beoordelingen, de gemiddelde score, de standaarddeviatie, de meest gegeven score (en welk aandeel van alle beoordelingen daarop zit) en de datum van de laatste evaluatie. Filterbaar op team en datumbereik.

Rijen waar de standaarddeviatie onder **0,5** ligt over **10 of meer beoordelingen** krijgen de vlag *lage variatie* — het statistische kenmerk van een coach die iedereen hetzelfde cijfer geeft. Een coach met maar een handvol beoordelingen wordt nooit gevlagd; er valt dan nog geen zinvolle variatie te meten.

Beperkt tot academiebrede rollen (hoofd opleiding / beheerder): coaches kunnen elkaars statistieken niet inzien. De knop **Exporteren (CSV)** downloadt dezelfde rijen; integraties kunnen ze lezen via `GET /wp-json/talenttrack/v1/reports/coach-evaluation-quality` met dezelfde rechtencontrole.

## Frontendrapporten + Afdrukken/Opslaan als PDF (v3.79.0)

Team-gemiddelden en Coach-activiteit renderen nu rechtstreeks op het publieke dashboard via `?tt_view=reports&type=team_ratings` en `?type=coach_activity` — geen sprong meer naar wp-admin. Elk rapport heeft bovenaan een knop **Afdrukken / Opslaan als PDF**: bij klikken opent het printvenster van de browser met een stijlblad dat dashboard-elementen verbergt, zodat "Opslaan als PDF" een schone PDF oplevert.

## Speler · Voortgang & radar (v4.20.124)

Het oude wp-admin-rapport "Spelersontwikkeling & Radar" rendert nu rechtstreeks op het dashboard als standaardrapport (Rapporten → *Speler · Voortgang & radar*). Dezelfde drie modi met dezelfde data: **Spelersvoortgang** (de laatste vijf evaluaties van elke geselecteerde speler als gestapelde radarseries — laat de selectie leeg voor de top-10 actieve spelers), **Spelersvergelijking** (de meest recente evaluatie van elke speler over elkaar op één radar; kies er minstens twee) en **Teamgemiddelden** (één radarserie per team, gemiddeld per categorie).

Coaches zien alleen spelers en teams van hun eigen teams; academiebrede rollen zien alles. De oude wp-admin-route stuurt door naar dit rapport, dus bladwijzers blijven werken. Integraties kunnen dezelfde datasets lezen via `GET /wp-json/talenttrack/v1/reports/player-radar?mode=…&player_ids=…`.
