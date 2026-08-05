<!-- audience: user, admin -->

# Rapporten

De tegel **Rapporten** is een launcher voor verschillende manieren om naar je gegevens te kijken. De rapporten zijn gegroepeerd op doel zodat je het juiste rapport snel vindt: **Ontwikkeling & prestaties** (beoordelingen, voortgang, rate cards), **Speeltijd** (gespeelde minuten en selectiebelasting), **Aanwezigheid** (aanwezigheidsstatistieken per team en speler en de ranglijst), **Werving** (scouting, prospects, trial funnel), **Staf & kwaliteit** (coachactiviteit en beoordelingskwaliteit) en **Seizoensoverzicht** (de jaarlijkse review). Secties waartoe je geen toegang hebt — werving en seizoensbrede rapporten zijn alleen voor academy-beheerders — verschijnen gewoon niet. Alle standaardrapporten — waaronder team- en spelersaanwezigheid, de ranglijst en minuten-per-team — staan hier en tonen **Rapporten** in het kruimelpad; ze staan niet langer dubbel op het Analytics-dashboard.

Als **geen enkel** rapport voor je beschikbaar is — alle uitgeschakeld voor je academie, of geen ervan binnen je toegang — meldt de launcher dat duidelijk in plaats van een leeg raster te tonen, en verwijst je om een beheerder te vragen een rapport in te schakelen of je bereik te verruimen.

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

Alleen coaches binnen je eigen club worden meegeteld — het rapport is beperkt tot de huidige tenant en toont nooit activiteit van een andere academie. Een coach van wie het gebruikersaccount is verwijderd verschijnt nog steeds (de opgeslagen evaluaties blijven binnen het venster) maar krijgt het label **Onbekende coach** in plaats van een ruw accountnummer.

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

**Standaardperiode.** Wanneer je een rapport opent zonder een pil te kiezen of een Van/Tot-bereik te typen, staat het standaard op **het huidige seizoen** — van de startdatum van het seizoen tot vandaag. Dit komt overeen met de pil *Dit seizoen* en met hoe de academie over het jaar denkt, in plaats van een willekeurig meelopend venster. Als er geen seizoen is ingesteld, valt het rapport terug op de laatste **90 dagen**, zodat er altijd iets te zien is. Het minutenrapport per team volgt dezelfde standaard. Een pil kiezen of een handmatig Van/Tot typen overschrijft dit nog steeds. Omdat deze standaard *het* seizoensvenster is, tonen beide aanwezigheidsrapporten nu de pil ***Dit seizoen* gemarkeerd** bij het openen — de filterbalk weerspiegelt het venster dat je echt bekijkt, in plaats van "Aangepast bereik".

**Bereikmelding.** Als je maar enkele teams traint, tonen de aanwezigheidsrapporten alleen die teams. Levert je filter niets op, dan meldt de lege-status dat het rapport **beperkt is tot de teams die je traint**, zodat een leeg venster niet leest als "de academie heeft geen data".

Op een telefoon klappen de filters samen tot een **Filters**-knop die een bottom sheet opent; vanaf desktopbreedte staan ze inline. Elke besturing is met het toetsenbord te bedienen.

## Inzoomen op de spelers van een team (teamrapport)

In het teamrapport is elke teamrij **tikken-om-uit-te-klappen**: tik op de teamnaam om een inline subtabel met de spelers van dat team te openen (speler · aanwezig %, met risicospelers gemarkeerd), op aanvraag geladen voor de actieve periode en filters. Nogmaals tikken klapt hem in; er is één team tegelijk open. Zonder JavaScript opent een **Spelers bekijken**-link naast elk team het spelersrapport, vooraf gefilterd op dat team — het inzoomen is altijd bereikbaar.

## Gespeelde minuten — totalen en trace per wedstrijd

De minutenrapporten lezen alleen **vastgelegde** wedstrijdminuten: werkelijke,
niet-gast-aanwezigheid. Geplande (verwachte) selectierijen en gastoptredens
tellen nooit mee, en een wedstrijd zonder vastgelegde minuten draagt niets bij —
de rapporten schatten, berekenen of construeren nooit minuten, dus een nul is een
eerlijke "geen gegevens vastgelegd" in plaats van een gok. Minuten worden één
keer bepaald, wanneer een gespeelde wedstrijd wordt vastgelegd (de match-execution
wordt afgerond, of de minuten worden met de hand ingevoerd op het
aanwezigheidsscherm), en op de aanwezigheidsregel opgeslagen. Elk rapport leest
alleen die opgeslagen waarde; een wedstrijd die wel gespeeld maar nooit afgerond
is, toont daarom niets tot de minuten zijn vastgelegd, in plaats van te worden
herleid uit de geplande opstelling.

**Wedstrijden, games en toernooien tellen allemaal mee.** De minutenrapporten
behandelen wedstrijden, games en toernooien op dezelfde manier — elk is een
activiteit die minuten oplevert. Een toernooi met één wedstrijd legt minuten
precies als een wedstrijd vast (opstelling plannen, het live-wedstrijdscherm
draaien, afronden); een toernooidag met meerdere wedstrijden legt minuten vast
met de handmatige invoer per speler op het aanwezigheidsscherm (hoeveel minuten
elke speler die dag daadwerkelijk speelde). Hoe dan ook belanden de minuten op de
aanwezigheidsregel en leest elk rapport ze. Een toernooi zonder vastgelegde
minuten toont nog steeds niets — dezelfde eerlijke nul als een wedstrijd.

**Basisplaatsen tellen alleen vastgelegde wedstrijden.** De *basisplaatsen* van
een speler en het *% beschikbaar* tellen alleen wedstrijden die daadwerkelijk zijn
vastgelegd (die minuten hebben opgeleverd) — een wedstrijd die wel gepland was,
met een opstelling, maar nooit gespeeld of vastgelegd werd, telt voor geen van
beide mee. Basisplaatsen kunnen daarom nooit meer zijn dan wedstrijden. Voor een
toernooidag met meerdere wedstrijden zijn de uit de opstelling afgeleide
"basisplaatsen" bij benadering (één opstelling dekt meerdere wedstrijden), dus
zijn daar de vastgelegde *minuten* de betekenisvolle waarde, niet het aantal
basisplaatsen.

Het minutentotaal van elke speler is een **drill-down**: open het om de rijen per
wedstrijd te zien die optellen tot het totaal — datum, wedstrijd, type, bron
(`werkelijk` vastgelegde minuten) en minuten. De uitsplitsing sluit exact aan op
het totaal, zodat je een gerapporteerd
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

De uitsplitsingstabel per wedstrijd is nu één **gedeelde component** voor zowel
het rapport Team · Minutenverdeling als het Analytics-minutenrapport, zodat de
twee nooit uit elkaar lopen en beide op dezelfde manier aansluiten op het
totaal van de speler.

## Minuten-audit — wedstrijden × spelers auditmatrix

Het rapport **Minuten-audit** (bereikbaar vanuit de rapportenlauncher onder
*Speeltijd*, of rechtstreeks via `?tt_view=minutes-audit`) is de audit-tegenhanger
van het minutenrapport. Het beantwoordt een andere vraag: *welke spelers uit de
selectie hebben per wedstrijd wél en welke géén geregistreerde minuten?* — zodat
een beheerder of hoofdcoach de gaten kan opsporen en aanpakken voordat de
minutengegevens van een seizoen verouderen.

Het is **alleen-lezen**. De link *Bewerk* / *Registreer* per rij opent het
activiteitendetail van de wedstrijd, waar de minuten daadwerkelijk worden
geregistreerd; het direct bewerkbare raster is een aparte, latere functie.

Het scherm is een matrix in spreadsheet-stijl:

- **Rijen** zijn de wedstrijd-, match- en toernooiactiviteiten van het team in de
  periode (dezelfde set die het minutenrapport telt).
- **Kolommen** zijn de selectie — elke speler die voorkomt op de **aanwezigheid**
  van die wedstrijden. De selectie wordt bepaald op basis van aanwezigheid, niet
  op basis van de teamindeling van een speler, zodat een speler die voor één
  wedstrijd werd geleend toch verschijnt en een speler die het team verliet maar
  eerder in de periode speelde niet stil wordt weggelaten.
- **Cellen** tonen de geregistreerde minuten van die speler in die wedstrijd. Een
  groene cel is geregistreerde minuten; een rode **0** is een speler die in de
  selectie zat maar geen geregistreerde minuten heeft (een gat om aan te pakken);
  een gearceerd streepje is een speler die niet in de selectie van die wedstrijd
  zat.
- Elke rij heeft een **rijtotaal**, een **statuschip** voor volledigheid —
  *Volledig* (elke speler uit de selectie heeft minuten), *Onvolledig* (sommigen
  wel, sommigen niet) of *Niet geregistreerd* (niets geregistreerd voor de
  wedstrijd) — en de onderste **kolomtotaal**-rij telt de minuten van elke speler
  over de zichtbare wedstrijden op.

Boven de matrix vatten vier **gat-KPI's** — *Wedstrijden*, *Volledig
geregistreerd*, *Onvolledig*, *Niet geregistreerd* — de periode samen. Elke KPI is
klikbaar en filtert de matrix op die volledigheidscategorie, zodat *Niet
geregistreerd* meteen naar de wedstrijden springt die nog minuten missen.

Omdat de audit **dezelfde** geregistreerde, werkelijke, niet-gast-minuten leest
als het minutenrapport, sluiten de cijfers exact aan op dat rapport. De
eerlijke-nul-regels gelden hier ook: een team met wedstrijden maar zonder
geregistreerde minuten toont elke wedstrijd, eerlijke *Niet geregistreerd*-chips
en een duidelijke vervolgstap — nooit een misleidende "0 spelers"-lege staat. Een
lege periode (helemaal geen wedstrijden) meldt dat apart.

Coaches zien alleen de teams die ze coachen; academiebrede rollen zien de hele
club. De filterbalk heeft de gedeelde besturing voor team / periode /
wedstrijdtype / datumbereik en staat standaard op het venster van het huidige
seizoen.

## Standaardrapporten — eerlijke cijfers

Elk standaardrapport benoemt nu de periode en de bron waaruit het put, zodat een
cijfer nooit een stille gok is:

- **Eerlijke lege toestanden.** Als een rapport niets te tonen heeft, zegt het
  in gewone taal *waarom* — "Geen wedstrijden geregistreerd in deze periode",
  "Geen beoordelingen geregistreerd voor dit team in deze periode", "Geen
  scoutingskandidaten geregistreerd in deze periode" — in plaats van de oude
  algemene "pas een filter aan"-tekst (de meeste van deze rapporten hebben geen
  filter om aan te passen). Het Seizoensoverzicht toont geen lege pagina meer
  onder de kop-tegels wanneer er geen teams zijn.
- **Speler · Gespeelde minuten** beslaat de **laatste 12 maanden** (vermeld in
  de subregel van de pagina, gelijk aan de Explorer-drill), en wanneer een
  speler meer dan 50 wedstrijden in die periode heeft, staat er *"De 50 meest
  recente wedstrijden worden getoond"* zodat een langere historie nooit
  ongemerkt wegvalt.
- **Team · Teambeoordelingsoverzicht** toont per speler een datum **Laatst
  beoordeeld**, zodat een verouderde rij in één oogopslag zichtbaar is.
- **Seizoensoverzicht** telt gearchiveerde activiteiten niet meer mee in de
  wedstrijdaantallen per team (op de join zelf, niet alleen in de telling),
  waardoor een bron van opgeblazen joins verdwijnt.

### Aansluiting van de trial-trechter

De Seizoen · Trial-trechter **sluit nu aan**. De tabel Per beslissing toont de
uitkomsten van cases die *in de periode geopend* zijn, plus een rij **In
afwachting (nog niet beslist)** en een **Totaal**-rij die optelt tot *Geopende
trial-cases*. De tegel **Beslissingspercentage** draagt een korte toelichting
dat de teller (besliste cases, op beslisdatum) en de noemer (geopende cases, op
openingsdatum) verschillende periodes gebruiken, zodat het percentage niet als
één-cohortpercentage wordt gelezen. Elke scoutnaam in de tabel Per scout linkt
naar de **Scoutrapportkaart** van die scout (afgeschermd op `tt_view_reports`,
dezelfde rechten die de kaart afdwingt).

### Gespeelde minuten (team) — gedeelde filter- en KPI-chrome

Het rapport Gespeelde minuten (team) gebruikt nu de **gedeelde filterbalk**
(team, retrospectieve periodepillen — Vorige week / Deze maand / Dit seizoen —
een wedstrijdtype-keuze en een handmatig Van/Tot-bereik) en de **gedeelde
KPI-strip**, gelijk aan de aanwezigheidsrapporten. De standaardperiode is het
huidige seizoen. Op een telefoon klappen de filters in het gebruikelijke
bottom-sheet; elke bediening houdt een aanraakdoel van 48px.

### Standaardrapporten — gedeelde filterbalk, selectiefix, drill-downs

Vier standaardrapporten dragen nu **dezelfde gedeelde filterbalk** die de
aanwezigheids- en minutenrapporten gebruiken: retrospectieve **periodepillen**
(Vorige week / Deze maand / Dit seizoen) plus een handmatig **Van/Tot**-bereik.
De standaardperiode is het **huidige seizoen** (seizoensstart → vandaag; een
voortschrijdend venster van 90 dagen als er geen seizoen is ingesteld). Een
periodepil kiezen laat een handmatig bereik vallen; een Van/Tot typen overschrijft
de pil. Het betreft **Team · Teambeoordelingssamenvatting**, **Seizoensoverzicht**,
**Seizoen · Trial-trechter** en de **Scoutrapportkaart**. Op een telefoon klappen
de filters in het gebruikelijke bottom-sheet; elke bediening houdt een aanraakdoel
van 48px. De subregel van elk rapport en de Verkenner-drill noemen nu hetzelfde
venster, zodat het cijfer en de drill overeenkomen.

Het rapport **Team · Minutenverdeling** had een selectiefout: het telde
wedstrijden op basis van de activiteiten van het team, maar bouwde de spelerslijst
op uit `tt_players.team_id` — dus een team waarvan de spelers geen `team_id`
hadden, toonde "18 wedstrijden, 0 spelers". De selectie wordt nu op **dezelfde
manier bepaald als in de rest van de analytics** — spelers met geregistreerde
aanwezigheid op de wedstrijd-/toernooiactiviteiten van het team — zodat de
spelerslijst en het wedstrijdaantal één definitie delen, en een speler
verschijnt ook met **0 geregistreerde minuten**. Minuten komen nog steeds
uitsluitend uit vastgelegde `record_type='actual'`-aanwezigheidsrijen (ze worden
nooit geschat), dus een wedstrijd zonder geregistreerde minuten telt 0.

**KPI's van standaardrapporten zijn nu drill-downs** waar een gefilterde lijst
aansluit op het cijfer: de tegel *Spelers* van Team · Minutenverdeling opent de
teamselectie en de tegel *Wedstrijden* de activiteitenlijst gefilterd op de
wedstrijden van dat team; de tegels *Actieve spelers / Actieve teams /
Wedstrijden* van het Seizoensoverzicht openen hun lijsten; de tegel *Prospects
vastgelegd* van de Trial-trechter opent de prospectslijst. Elke drill draagt een
**← Terug naar …**-hint en is verborgen wanneer de gebruiker de capability van
de bestemming mist (§7 verbergen-niet-plagen).

## Spelersaanwezigheid — ranglijst + risicomarkering (v4.21.36)

Het aanwezigheidsrapport per speler staat standaard op **laagste aanwezigheid eerst** (laagste aanwezig-%), zodat de spelers die aandacht nodig hebben bovenaan staan. Het toont **elke speler** met geregistreerde aanwezigheid in de periode — geen top-N-limiet — en elke kolom blijft sorteerbaar (klik op een kop om opnieuw te sorteren).

Spelers die een instelbaar aantal activiteiten hebben **gemist** in de periode (afwezig / afgemeld / geblesseerd) worden **gemarkeerd**: een ⚠-badge met het aantal gemiste activiteiten, een licht gekleurde rij, en een paneel **Risicospelers** boven de tabel. De drempel (standaard **3**) is de *enige bron van waarheid* die gedeeld wordt met de dagelijkse aanwezigheidsmelding, zodat het rapport en de melding altijd overeenkomen.

De ⚠-badge (en elke naam in het paneel **Risicospelers**) is een **link** — tik erop om de markering te herleiden tot de onderliggende sessies. Hij opent dezelfde spelergerichte activiteitenlijst als het *Activiteiten*-aantal (deze speler, het team van het rapport, de periode van het rapport), zodat je de datumsessies ziet die de speler bijwoonde en het aantal gemiste activiteiten kunt verifiëren. Een **← Terug**-link keert terug naar het rapport.

### Het activiteitenaantal natrekken (drill-down)

Het **Activiteiten**-aantal van elke speler is een link. Open het om de daadwerkelijke sessies achter het getal te zien: de activiteitenlijst opent gefilterd op die speler, het team van het rapport en de periode van het rapport, en toont alleen activiteiten waarvoor de speler een geregistreerde aanwezigheidsregel heeft. Vanaf daar opent elke activiteit het detail met de geregistreerde aanwezigheidsstatus, zodat een coach het aantal kan verifiëren tegen de bronregels — dezelfde tracering die het minutenrapport biedt. Een **← Terug**-link keert terug naar het rapport.

### De risicodrempel instellen

De drempel staat onder **Configuratie → Algemeen → Risicodrempel aanwezigheid** (een instelling voor de academy-beheerder). Eén getal, tussen 1 en 50, bepaalt elke risicomarkering: het aanwezigheidsrapport per speler, de aanwezigheidsranglijst en de dagelijkse aanwezigheidsmelding lezen het allemaal. Zet hem lager om verzuim eerder op te merken, of hoger als jullie academy alleen op aanhoudend verzuim wil reageren.

## Aanwezigheidsranglijst (v4.27.0)

Een aparte ranglijst die je opent vanuit de Rapporten-startpagina (*Aanwezigheidsranglijst*). Hij rangschikt spelers over de gekozen periode in twee tabellen naast elkaar: **Aandacht nodig** (de laagste aanwezigheid-%, waar risicospelers hun ⚠-badge houden) en **Meest betrouwbaar** (de hoogste aanwezigheid-%). Standaard toont hij **alle** spelers in de periode; typ een aantal in *Hoeveel* om elke tabel tot dat aantal rijen te beperken. Beperk eventueel tot één team. Coaches zien alleen hun eigen teams; academy-brede rollen zien de hele club.

Hij deelt dezelfde filterbalk en chrome als het spelersaanwezigheidsrapport: een **team**-keuze, retrospectieve **periode**-pillen (afgelopen week / maand / seizoen enzovoort), een **activiteittype**-filter en een handmatig **datumbereik** dat de actieve periode overschrijft, plus de ranglijst-specifieke *Hoeveel*-limiet. Open je hem zonder filters, dan valt hij terug op het huidige **seizoen**. Boven de tabellen vat een KPI-strip de gerangschikte spelers samen — aantal spelers, gemiddelde aanwezigheid en hoeveel er risico lopen — berekend uit dezelfde gegevens, dus zonder extra query.

Op een telefoon stapelen de twee tabellen tot één kolom zonder horizontaal scrollen; vanaf tabletbreedte staan ze naast elkaar. Bovenop de standaardrangschikking is elke kolom sorteerbaar.

Integraties kunnen dezelfde gegevens lezen — met dezelfde `tt_view_analytics`-toegang en team-afbakening — via:

- `GET /wp-json/talenttrack/v1/reports/attendance-leaderboard?from=…&to=…&n=…&team_id=…&activity_type_key=…` — `{ top, bottom, total }`.
- `GET /wp-json/talenttrack/v1/reports/attendance-at-risk?from=…&to=…&team_id=…&activity_type_key=…` — gemarkeerde spelers met de slechtste eerst, elk met een `declining`-trendindicator, plus de actieve `threshold`.
- `GET /wp-json/talenttrack/v1/reports/attendance?from=…&to=…&team_id=…&activity_type_key=…` — de aanwezigheidsrijen per speler voor één periode (voedt het inzoomen in het teamrapport): `{ players, threshold }`.

De optionele `activity_type_key` op elk aanwezigheids-endpoint beperkt tot één activiteittype, gelijk aan het Type-filter in de rapport-UI.

## Dimensieverkenner — rijlimiet en filtervalidatie

De dimensieverkenner (de *Verken*-actie bij een KPI) laat je de onderliggende feitrijen van een metriek filteren en erop inzoomen. Twee waarborgen houden het inzoomen betrouwbaar:

- **Limiet van 5000 rijen, nu zichtbaar.** De verkenner leest per inzoomactie maximaal **5000** feitrijen. Wanneer een gefilterde set die grens raakt, toont de tabel onder de paginering de melding **"Beperkt tot 5000 rijen — gebruik groeperen om grotere sets samen te vatten."**, zodat het zichtbare aantal pagina's nooit voor de volledige dataset wordt aangezien. Groepeer op een dimensie om grotere sets samen te vatten in plaats van door ruwe rijen te bladeren.
- **Filters gevalideerd tegen de dimensies van de KPI.** Alleen de dimensies die een KPI daadwerkelijk aanbiedt om te verkennen, worden als filter geaccepteerd. Een `filter_<key>` voor een dimensie die de KPI niet aanbiedt, wordt stilzwijgend genegeerd — het bereikt de query of de CSV-/PDF-export nooit, zodat de filters die je op het scherm ziet altijd overeenkomen met de filters die op het geëxporteerde bestand zijn toegepast.
