<!-- audience: user, admin -->

# Persona-dashboards

Iedere gebruiker van de academie landt op een dashboard dat is afgestemd op zijn persona — Speler, Ouder, Hoofdtrainer, Assistent-trainer, Teammanager, Head of Development, Scout, Academiebeheerder, of Read-only waarnemer. Het dashboard beantwoordt in het eerste scherm de vraag die voor die gebruiker telt: *Waar sta ik nu? Wat is er nieuw? Wat is het volgende?*

Deze pagina beschrijft wat je per persona kunt verwachten, hoe je wisselt als je meerdere rollen hebt, en (voor academiebeheerders) hoe de standaardlay-out per persona is opgebouwd.

## Wat zie je per persona

| Persona | Hero | Belangrijkste paneel | Wat blijft staan |
| - | - | - | - |
| **Speler** | FIFA-achtige ratingcard met je overall + positie | Notitie van je trainer (als die er is) | Mijn reis, Mijn kaart, Mijn team, Mijn evaluaties, Mijn activiteiten, Mijn doelen, Mijn POP, Mijn profiel |
| **Ouder** | Kind-schakelaar + "sinds je laatste bezoek"-overzicht | POP wachtend op jouw akkoord | De kaart van mijn kind, evaluaties, activiteiten, POP |
| **Hoofdtrainer / Assistent-trainer** | Vandaag / Eerstvolgende met aanwezigheids- en evaluatie-knoppen | Werkstroomtaken + recente evaluaties-rail | Activiteiten, evaluaties, doelen, spelers, teams, POP, methodiek, mijn taken |
| **Teammanager** | Vandaag / Eerstvolgende | (standaard geen) | Activiteiten, mijn teams, spelers, mijn taken |
| **Head of Development** | KPI-strip (actieve spelers, evaluaties deze maand, opkomstpercentage, open trials, openstaande POP-verdicten, doel-voltooiingspercentage) | Ploegoverzicht-grid (per ploeg rating + opkomst, uitklapbaar naar spelersuitsplitsing), Nieuwe trial snelactie, Aankomende activiteiten-tabel, Trials die een besluit nodig hebben (tabel) | Trials, POP, spelers, methodiek, takendashboard, evaluaties, ratingcards, vergelijken |
| **Scout** | Toegewezen-spelers-grid (je primaire werksurface) | Recente rapporten | Mijn rapporten, mijn toegewezen spelers |
| **Academiebeheerder** | Systeemstatus-strip (back-up, uitnodigingen, licentie, modules) | Recente audit-gebeurtenissen (tabel) | Configuratie, autorisatie, gebruiksstatistieken, audit log, uitnodigingen, migraties, hulp, methodiek |
| **Read-only waarnemer** | KPI-strip in alleen-lezen-modus | (geen) | (geen bewerk-acties; alleen methodiek + KPI's) |

## Wisselen tussen persona's

Heb je meerdere rollen op de academie — bijvoorbeeld hoofdtrainer én ouder van een speler — dan verschijnt boven het dashboard een kleine **Bekijken als**-pillenbalk. Tik op een andere pil om de landingstemplate te wisselen. De keuze blijft bewaard tussen sessies; via dezelfde balk kun je altijd terug.

De pillenbalk verschijnt alleen als er meer dan één persona voor jouw account herleidbaar is. De meeste gebruikers zien één landingspagina en hoeven niet te wisselen.

## Wat is een "widget"?

Elk blok op het dashboard is een widget. Er zijn 15 widget-types: navigatietegel, KPI-kaart, KPI-strip, actie-kaart, snelle-acties-paneel, info-kaart, takenlijst-paneel, datatabel (presets voor trials, scoutrapporten, audit log en aankomende activiteiten — alle vier sinds v3.78.0 op live data), mini-spelerslijst, ratingcard-hero, vandaag-eerstvolgende-hero, kind-schakelaar-met-overzicht, systeemstatus-strip, toegewezen-spelers-grid en **ploegoverzicht-grid**.

Widgets hebben vier formaten — Small, Medium, Large, Extra-large — en klikken vast op een 12-koloms grid op desktop, 6 koloms op tablet en één mobile-priority-gesorteerde kolom op mobiel.

## Een persona-dashboard aanpassen

Open *TalentTrack → Dashboard-lay-outs* in wp-admin. De pagina is afgeschermd met de capability `tt_edit_persona_templates` — standaard toegekend aan beheerders en academiebeheerders, optioneel aan Head of Development.

De editor heeft drie panelen:

- **Links — Palet.** Twee tabbladen: *Widgets* (de 14 widget-types) en *KPI's* (de 25 KPI's gegroepeerd per Academiebreed / Coach / Speler & ouder). Sleep een paletitem op het canvas, of focus het en druk op Enter.
- **Midden — Canvas.** Een 12-koloms bento-grid met een hero-band en een takenband erboven. Elke geplaatste widget toont label, databron, formaat-badge en een verwijder-knop (×). Klik een widget om hem te selecteren.
- **Rechts — Eigenschappen.** Wanneer een widget geselecteerd is kun je formaat (S/M/L/XL — alleen ondersteunde formaten zijn klikbaar), databron (KPI-keuzelijst voor KPI-kaarten, vrije tekst voor tegels), persona-label-overschrijving, mobiele prioriteit en mobiele zichtbaarheid aanpassen.

Werkbalk:

- **Persona-keuzelijst** — wisselt het canvas naar een andere persona-lay-out. Niet-opgeslagen wijzigingen vragen om bevestiging.
- **Ongedaan maken / Opnieuw** — tot 50 stappen. `Ctrl+Z` / `Ctrl+Shift+Z` werken ook.
- **Mobiele preview** — laat het canvas inklappen tot 360 px in prioriteitsvolgorde zodat je ziet hoe de lay-out stapelt op telefoon.
- **Terugzetten naar standaard** — vervangt de lay-out door de TalentTrack-standaard. Bevestiging vereist.
- **Concept opslaan** — bewaart je werk-in-uitvoering zonder live te gaan.
- **Publiceren** — zet de huidige lay-out live voor iedereen met deze persona, bij hun volgende paginalaad. Het bevestigingsvenster toont het aantal gebruikers dat geraakt wordt.

### Slepen & loslaten (v3.97.2)

Een paletitem of bestaande widget volgt de muis en klikt vast in het dichtstbijzijnde rastervakje. De editor laat nooit twee widgets overlappen:

- **Loslaten op een leeg vakje** — de widget komt daar te staan.
- **Loslaten op een bezet vakje** — de bestaande widget(s) worden naar beneden geduwd; daarna sluit de editor eventuele gaten erboven zodat de lay-out compact blijft (dezelfde automatische herrangschikking als bij Notion / Power BI).
- **Shift ingedrukt houden bij het loslaten** — de editor zoekt het dichtstbijzijnde vrije vakje in plaats van bestaande widgets weg te duwen. Handig bij het in bulk toevoegen van widgets, wanneer je ze tussen het bestaande in wilt schuiven.

Tijdens het slepen verschijnen **uitlijnhulplijnen** als zwakke blauwe lijnen zodra de linker-, rechter-, midden-, boven- of onderrand van de gesleepte widget op één lijn ligt met een andere widget — of met het midden / de randen van het canvas zelf. Loslaten binnen 4 px van een hulplijn klikt vast op die lijn, zodat je nette rijen en kolommen bouwt zonder pixels te tellen.

Het herrangschikken animeert in 150 ms; de animatie respecteert de besturingssysteem-instelling *Beweging beperken*.

Lay-outs die vóór deze release zijn opgeslagen (en mogelijk overlappende widgets uit oudere editor-versies bevatten) worden automatisch opgeschoond zodra je ze opent — de compact-pass draait bij het laden en je ziet een opgeruimd raster in plaats van gestapelde kaarten.

### Toetsenbord

De editor is volledig met toetsenbord te bedienen:

- Tab door paletitems, canvas-widgets en werkbalkknoppen.
- Op een canvas-widget: **spatie** om te pakken, **pijltjestoetsen** om te verplaatsen (Links/Rechts = 3 kolommen, Omhoog/Omlaag = 1 rij), **spatie** om los te laten. **Escape** annuleert.
- **Delete** of **Backspace** op een gefocuste widget verwijdert hem.
- Elke verplaatsing wordt aangekondigd in de live status-regio voor schermlezers.

### Audit

Elke opslag of publicatie schrijft een audit-log-regel (`persona_template_published`, `persona_template_draft`, of `persona_template_reset`) zodat je terug kunt vinden wie welke persona-lay-out heeft veranderd en wanneer.

### Wat de editor (nog) niet doet

- **Per gebruiker overschrijven.** Een gebruiker kan zijn eigen dashboard niet aanpassen — alleen academiebeheerders zetten de lay-out per persona.
- **Eigen KPI's schrijven.** De 25-KPI-catalogus is gesloten; je kunt er elke uitkiezen, maar geen nieuwe query schrijven.
- **Apart mobiel ontwerpen.** De mobiele preview is alleen-lezen — je stelt prioriteit en zichtbaarheid in op elke widget; de inklapvolgorde wordt daaruit berekend.

## Persona-specifieke override (testtool)

De standaarddashboard-keuze (*Configuratie → Standaarddashboard*) schakelt **Persona-dashboard** vs. **Klassiek tegelraster** voor de hele site. Daaronder maakt een tabel *Persona-specifieke overrides* het mogelijk om een specifiek dashboard te forceren voor één persona terwijl de rest de standaardinstelling volgt. Elke persona-rij biedt drie opties:

- **Overerven (gebruik standaardinstelling)** — de persona volgt de site-brede instelling.
- **Persona-dashboard** — forceer het persona-specifieke dashboard alleen voor deze persona.
- **Klassiek tegelraster** — forceer het oude tegelraster alleen voor deze persona.

Handig om een herontworpen persona-dashboard één persona tegelijk uit te rollen op een echte installatie, of om het oude raster te bekijken zonder de hele site te flippen.

## Databronnen

Elke KPI wordt live berekend op basis van de data van jouw academie. KPI's die afhankelijk zijn van nog niet uitgerolde features (bijv. de status-traffic light uit `#0057`, POP-planningvensters uit `#0054`) tonen een placeholder-streepje (`—`) tot die landen.

## REST API

De geresolveerde lay-out voor elke persona wordt als JSON ontsloten voor toekomstige SaaS-clients:

```
GET    /wp-json/talenttrack/v1/personas/{slug}/template          lezen
PUT    /wp-json/talenttrack/v1/personas/{slug}/template          concept opslaan
DELETE /wp-json/talenttrack/v1/personas/{slug}/template          terugzetten naar standaard
POST   /wp-json/talenttrack/v1/personas/{slug}/template/publish  concept publiceren
POST   /wp-json/talenttrack/v1/me/active-persona                 actieve persona instellen
DELETE /wp-json/talenttrack/v1/me/active-persona                 actieve persona resetten
```

Een ingelogde gebruiker kan templates lezen voor persona's waarvoor hij in aanmerking komt; de write-endpoints vereisen `tt_edit_persona_templates`.

## Visuele conventies (v3.76.0)

Het dashboard opent met een subtiele titelkop — persona-naam als paginatitel plus een ondertitel met datum + clubnaam. Persona's zonder hero-widget (Head of Development, Academiebeheerder, Scout) krijgen een dagdeel-groet als prefix; persona's met een hero (speler, ouder, coach, manager) krijgen geen extra groet omdat de hero die functie al vervult.

Tegel-iconen (de gekleurde één-letter-vierkantjes) en de gele plus-cirkel op actie-kaarten zijn weg. Tegels leunen nu op typografische hiërarchie en een hover-pijltje; actie-kaarten plaatsen de "+" in de label-string. De widget-shell heeft zachtere hoekranden, een twee-staps schaduw die meegeeft op hover, en een getokeniseerd kleurenpalet (`--tt-pd-*` custom properties) zodat toekomstige themapasses één plaats hebben om aan te passen.

Clubs die sterker visueel onderscheid tussen tegels nodig hebben kunnen via de dashboard-editor een per-tegel-beschrijving toevoegen — typografie leest dan als "label + beschrijving" in plaats van "label + icoon".

## Ploegoverzicht-grid (HoD-landing, v3.76.0)

Per-ploeg samenvattingskaarten in een responsief grid. Elke kaart toont ploegnaam, leeftijdscategorie, hoofdtrainer, en twee getallen: gemiddelde evaluatie-rating en opkomstpercentage over een instelbaar venster (standaard 30 dagen). Tikken op een kaart klapt hem inline open en toont de spelersuitsplitsing van die ploeg met opkomst % en rating per speler.

**Slot-config** — ingesteld in het eigenschappen-paneel van de dashboard-editor:

```
days=30,limit=20,sort=concern_first
```

- `days` — venster in dagen (1-365). Standaard 30.
- `limit` — max aantal kaarten. Standaard 20.
- `sort` — `alphabetical` (standaard) | `rating_desc` | `attendance_desc` | `concern_first`.

`concern_first` zet ploegen onder een drempelwaarde bovenaan. Drempels: rating 6.0 en opkomst 70%; clubs kunnen overschrijven via `tt_config`-sleutels `team_concern_rating_threshold` en `team_concern_attendance_threshold`.

De uitklap-status is per gebruiker, per kaart, opgeslagen in `localStorage` (`tt_pd_team_card_{team_id}`). Synchronisatie tussen apparaten wordt niet geboden — het is een UI-voorkeur, geen datavoorkeur.

## Verder lezen

- Wisselen van rol in het gebruikersmenu: [Toegangsbeheer](?page=tt-docs&topic=access-control)
- Volledige tegelcatalogus: [Coachdashboard](?page=tt-docs&topic=coach-dashboard)

## Databron-dropdowns (v3.79.0)

De persona-dashboard-editor vroeg vroeger om vrije tekst voor "databron"-waarden bij niet-KPI-widgets — beheerders moesten dus preset-keys uit het hoofd kennen, zoals `audit_log_recent`. Elke widget publiceert nu zijn eigen catalogus en de editor toont een dropdown:

- Actiekaart → 7 acties (Nieuwe evaluatie, Nieuw doel, Nieuwe activiteit, …)
- Infokaart → 4 presets (Coach-bericht, PDP wacht op bevestiging, Volgende activiteit, Licentie & modules)
- Mini spelerslijst → 3 presets (Podium, Recente evaluaties, Sterkste stijgers)
- Datatabel → 5 presets (Stages die een besluit vereisen, Recente scoutrapporten, Audit-gebeurtenissen, Komende activiteiten, Doelen per principe)
- Navigatietegel → elke geregistreerde tegel-slug, dynamisch geladen

Oudere waarden die niet meer overeenkomen met een preset blijven zichtbaar (met "(legacy)"-suffix), zodat een verwijderde preset opvalt in plaats van stilletjes leeg te worden.
