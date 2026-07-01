<!-- audience: user, admin -->

# Rapporten

De tegel **Rapporten** is een launcher voor verschillende manieren om naar je gegevens te kijken. De rapporten zijn gegroepeerd op doel zodat je het juiste rapport snel vindt: **Ontwikkeling & prestaties** (beoordelingen, voortgang, rate cards), **Speeltijd** (gespeelde minuten en selectiebelasting), **Aanwezigheid** (aanwezigheidsstatistieken per team en speler en de ranglijst), **Werving** (scouting, prospects, trial funnel), **Staf & kwaliteit** (coachactiviteit en beoordelingskwaliteit) en **Seizoensoverzicht** (de jaarlijkse review). Secties waartoe je geen toegang hebt — werving en seizoensbrede rapporten zijn alleen voor academy-beheerders — verschijnen gewoon niet. Alle standaardrapporten — waaronder team- en spelersaanwezigheid, de ranglijst en minuten-per-team — staan hier en tonen **Rapporten** in het kruimelpad; ze staan niet langer dubbel op het Analytics-dashboard.

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

## Alleen activiteiten uit het verleden tellen mee

Beide aanwezigheidsrapporten — en de ranglijst en het risicopaneel die dezelfde query gebruiken — tellen alleen activiteiten mee die **echt zijn gehouden**: afgerond en in het verleden (sessiedatum vandaag of eerder). Een activiteit met een datum in de toekomst telt nooit mee voor een aanwezigheidsstatistiek, zelfs als de aanwezigheid vooraf is ingevuld. Een activiteit met de datum van **vandaag** telt wel mee. Zo blijft het aanwezigheidscijfer van elke speler kloppen — een coach ziet alleen sessies die de speler echt had kunnen bijwonen.

## De aanwezigheidsrapporten filteren — periodepillen + activiteittype

Zowel het teamrapport als het spelersrapport gebruiken dezelfde filters als de activiteitenlijst:

- **Periodepillen** — *Vorige week*, *Deze maand* (tot vandaag), *Dit seizoen*. Deze kijken terug (de rapporten zijn retrospectief). Een pil kiezen zet het Van/Tot-bereik voor je. Het expliciete **Van / Tot**-bereik is altijd de handmatige override — typ daar een datum en die wint van de pil.
- **Activiteittype** — beperk tot één type (training / wedstrijd / toernooi, afhankelijk van wat jullie academy heeft ingesteld). Het typefilter beperkt elk cijfer consistent: de KPI-tegels, de tabel, de ranglijst en het risicopaneel.

Op een telefoon klappen de filters samen tot een **Filters**-knop die een bottom sheet opent; vanaf desktopbreedte staan ze inline. Elke besturing is met het toetsenbord te bedienen.

## Inzoomen op de spelers van een team (teamrapport)

In het teamrapport is elke teamrij **tikken-om-uit-te-klappen**: tik op de teamnaam om een inline subtabel met de spelers van dat team te openen (speler · aanwezig %, met risicospelers gemarkeerd), op aanvraag geladen voor de actieve periode en filters. Nogmaals tikken klapt hem in; er is één team tegelijk open. Zonder JavaScript opent een **Spelers bekijken**-link naast elk team het spelersrapport, vooraf gefilterd op dat team — het inzoomen is altijd bereikbaar.

## Gespeelde minuten — totalen en trace per wedstrijd

De minutenrapporten tellen alleen **vastgelegde** wedstrijdminuten: werkelijke,
niet-gast-aanwezigheid. Geplande (verwachte) selectierijen en gastoptredens
tellen nooit mee, en een wedstrijd zonder vastgelegde minuten draagt niets bij —
de rapporten schatten of verzinnen nooit minuten, dus een nul is een eerlijke
"geen gegevens vastgelegd" in plaats van een gok.

Het minutentotaal van elke speler is een **drill-down**: open het om de rijen per
wedstrijd te zien die optellen tot het totaal — datum, wedstrijd, type, bron
(`werkelijk` vastgelegde minuten of een herberekening uit het match-execution-log)
en minuten. De uitsplitsing sluit exact aan op het totaal, zodat je een gerapporteerd
getal altijd kunt herleiden tot de bronrijen. In het rapport Team · Minutenverdeling
klapt elke spelersbalk uit; in het Analytics-minutenrapport opent elk Totaal de
tabel per wedstrijd onder de rij. Beide werken op een telefoon en met het
toetsenbord; zonder JavaScript blijven de rijen per wedstrijd inline zichtbaar.

Integraties kunnen dezelfde trace lezen — met de `tt_view_reports`-toegang en
dezelfde team-scope-beperking als het rapport:

- `GET /wp-json/talenttrack/v1/teams/{team_id}/players/{player_id}/minutes?from=…&to=…` — de minutenrijen per wedstrijd voor één speler plus het aansluitende `total_minutes`.

Om een totaal tegen de ruwe opgeslagen rijen te controleren, zijn de
`tt_attendance`-minutenrijen (`minutes_played`, `record_type`, `is_guest`,
`activity_id`) doorzoekbaar in de **Data Browser**.

## Spelersaanwezigheid — ranglijst + risicomarkering (v4.21.36)

Het aanwezigheidsrapport per speler staat standaard op **laagste aanwezigheid eerst** (laagste aanwezig-%), zodat de spelers die aandacht nodig hebben bovenaan staan. Het toont **elke speler** met geregistreerde aanwezigheid in de periode — geen top-N-limiet — en elke kolom blijft sorteerbaar (klik op een kop om opnieuw te sorteren).

Spelers die een instelbaar aantal activiteiten hebben **gemist** in de periode (afwezig / afgemeld / geblesseerd) worden **gemarkeerd**: een ⚠-badge met het aantal gemiste activiteiten, een licht gekleurde rij, en een paneel **Risicospelers** boven de tabel. De drempel (standaard **3**) is de *enige bron van waarheid* die gedeeld wordt met de dagelijkse aanwezigheidsmelding, zodat het rapport en de melding altijd overeenkomen.

### Het activiteitenaantal natrekken (drill-down)

Het **Activiteiten**-aantal van elke speler is een link. Open het om de daadwerkelijke sessies achter het getal te zien: de activiteitenlijst opent gefilterd op die speler, het team van het rapport en de periode van het rapport, en toont alleen activiteiten waarvoor de speler een geregistreerde aanwezigheidsregel heeft. Vanaf daar opent elke activiteit het detail met de geregistreerde aanwezigheidsstatus, zodat een coach het aantal kan verifiëren tegen de bronregels — dezelfde tracering die het minutenrapport biedt. Een **← Terug**-link keert terug naar het rapport.

### De risicodrempel instellen

De drempel staat onder **Configuratie → Algemeen → Risicodrempel aanwezigheid** (een instelling voor de academy-beheerder). Eén getal, tussen 1 en 50, bepaalt elke risicomarkering: het aanwezigheidsrapport per speler, de aanwezigheidsranglijst en de dagelijkse aanwezigheidsmelding lezen het allemaal. Zet hem lager om verzuim eerder op te merken, of hoger als jullie academy alleen op aanhoudend verzuim wil reageren.

## Aanwezigheidsranglijst (v4.27.0)

Een aparte ranglijst die je opent vanuit de Rapporten-startpagina (*Aanwezigheidsranglijst*). Hij rangschikt spelers over de gekozen periode in twee tabellen naast elkaar: **Aandacht nodig** (de laagste aanwezigheid-%, waar risicospelers hun ⚠-badge houden) en **Meest betrouwbaar** (de hoogste aanwezigheid-%). Standaard toont hij **alle** spelers in de periode; typ een aantal in *Hoeveel* om elke tabel tot dat aantal rijen te beperken. Beperk eventueel tot één team. Coaches zien alleen hun eigen teams; academy-brede rollen zien de hele club.

Op een telefoon stapelen de twee tabellen tot één kolom zonder horizontaal scrollen; vanaf tabletbreedte staan ze naast elkaar. Bovenop de standaardrangschikking is elke kolom sorteerbaar.

Integraties kunnen dezelfde gegevens lezen — met dezelfde `tt_view_analytics`-toegang en team-afbakening — via:

- `GET /wp-json/talenttrack/v1/reports/attendance-leaderboard?from=…&to=…&n=…&team_id=…&activity_type_key=…` — `{ top, bottom, total }`.
- `GET /wp-json/talenttrack/v1/reports/attendance-at-risk?from=…&to=…&team_id=…&activity_type_key=…` — gemarkeerde spelers met de slechtste eerst, elk met een `declining`-trendindicator, plus de actieve `threshold`.
- `GET /wp-json/talenttrack/v1/reports/attendance?from=…&to=…&team_id=…&activity_type_key=…` — de aanwezigheidsrijen per speler voor één periode (voedt het inzoomen in het teamrapport): `{ players, threshold }`.

De optionele `activity_type_key` op elk aanwezigheids-endpoint beperkt tot één activiteittype, gelijk aan het Type-filter in de rapport-UI.
