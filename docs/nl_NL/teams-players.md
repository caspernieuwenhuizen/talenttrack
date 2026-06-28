<!-- audience: admin -->

# Teams & spelers

## Teams

Een **team** is een selectie binnen een specifieke leeftijdscategorie (bijv. "U13 Blauw", "U15 Rood"). Elk team heeft:

- Een naam en een optioneel label voor de leeftijdscategorie
- Een hoofdcoach (uit je **Personen**-register)
- Toegewezen spelers

Maak teams aan op de beheerpagina **Teams**. De leeftijdscategorie is belangrijk, omdat [categorie gewichten](?page=tt-docs&topic=eval-categories-weights) per leeftijdscategorie worden gedefinieerd.

### De Teams-lijst (v4.40.0 — #1614)

De Teams-lijst (de frontend **Teams**-weergave) toont je teams als een raster van klikbare kaarten in plaats van een tabel. Elke kaart bevat:

- Een gekleurde accentbalk bovenaan en een initialen-embleem, getint op leeftijdsband zodat een team altijd dezelfde kleur heeft.
- De teamnaam en een hoofdcoach-regel ("Nog geen hoofdcoach" als er geen is toegewezen).
- Een statstrook met twee waarden: **Spelers** (huidige selectiegrootte) en **Aankomend** (activiteiten gepland in de komende 14 dagen).

De hele kaart is een link — tik of klik er ergens op om de teampagina te openen. De kaarten worden op telefoons in één kolom gestapeld en stromen op bredere schermen in meerdere kolommen.

Boven het raster heb je nog steeds **zoeken**, het filter **Leeftijdscategorie** en het filter **Status** (Actief / Gearchiveerd / Alle) — gearchiveerde teams blijven bereikbaar door Status te wijzigen. Sorteren gebeurt via één **Sorteren op**-dropdown (naam, leeftijdscategorie of aantal spelers), omdat kaarten geen kolommen hebben.

### De teampagina

Als je op een team klikt — vanuit de Teams-lijst, een spelersprofiel of waar dan ook een teamnaam gelinkt is — opent de eigen pagina van dat team. Deze toont:

- **Header**: teamnaam, pil met leeftijdscategorie, hoofdcoach.
- **Notities**, indien aanwezig.
- **Selectie**: alleen-lezen spelerslijst (rugnummer, voet). Elke speler linkt naar de eigen pagina.
- **Staf**: de mensen die via Functionele rollen aan het team zijn toegewezen.
- **Team bewerken**-knop (rechtsboven) — alleen zichtbaar als je het bewerkrecht voor teams hebt. Klik om het beheerformulier hieronder te openen.

### De teambewerkpagina in één oogopslag

Het bewerkformulier bereik je via de knop **Team bewerken** op de teampagina (of de "Edit"-rij-actie in de Teams-lijst voor gebruikers met het recht). Het toont drie blokken:

1. **Teamgegevens** — naam, leeftijdscategorie, hoofdcoach, notities, aangepaste velden.
2. **Staftoewijzingen** — de mensen die met dit team werken (coaches, assistenten, fysio, enz.). Toewijzingen hier toevoegen/verwijderen.
3. **Spelers in dit team** — de huidige selectie in een tabel met rugnummer, posities, voet, geboortedatum. Elke rij linkt naar de eigen pagina van de speler. Bovenaan staat een knop "Speler aan dit team toevoegen".

## Spelers

Een **speler** is een individuele voetballer. Elke speler heeft:

- Voornaam en achternaam
- Positie(s), voorkeursvoet, rugnummer
- Lengte, gewicht, geboortedatum
- Optionele koppeling aan een WordPress-gebruikersaccount (zodat hij/zij kan inloggen)
- Eventuele aangepaste velden die je academie heeft geconfigureerd

Maak spelers aan op de beheerpagina **Spelers**. Gebruik de knop **+ Nieuwe toevoegen**.

## Speler koppelen aan WordPress-gebruiker

Als een speler een `wp_user_id` heeft, wordt die gebruiker na inloggen doorgestuurd naar zijn eigen dashboardweergave op de frontend-shortcode. Zonder deze koppeling bestaat de speler alleen als record dat je kunt evalueren.

## Archiveren versus verwijderen

Gearchiveerde spelers blijven in de database maar verdwijnen uit actieve lijsten (oude evaluaties blijven wel naar ze verwijzen). Permanent verwijderen werkt alleen als er geen evaluaties, doelen of sessies naar de speler verwijzen. Gebruik in de meeste gevallen **archiveren** — zie [Bulkacties](?page=tt-docs&topic=bulk-actions).

## Spelerdossierpagina (v3.79.0)

De spelerdetailpagina is een dossier met zes tabs: Profiel / Doelen / Evaluaties / Activiteiten / PDP / Stage. Elke tab toont tot 50 records (25 voor activiteiten, 10 voor PDP/Stage), elke record linkt door naar de eigen detailpagina, en broodkruimels vervangen de losse terugknop.

## Spelersdossier-UX (v3.92.6 — #0082)

Het spelersdossier kreeg een herontworpen hero-kaart en lege-staat-CTA's per tab.

- **Hero-kaart.** Foto (of initialenplaceholder als er geen foto is) staat naast een gestructureerd infoblok: team en leeftijdsgroep, statuspil, leeftijdscategorie-badge, dagen-in-academie + datum toegetreden, en tot drie "recentste record"-chips die direct linken naar de recentste activiteit, evaluatie en doel. Chips verdwijnen als het bijbehorende record niet bestaat; de hele rij verbergt zich als er niets te tonen is. Stapelt op 360px breedte, naast elkaar vanaf 480px.
- **Lege-staat-CTA's.** Wanneer een tab geen records heeft krijg je voortaan, in plaats van een schuingedrukte regel "Nog geen doelen vastgelegd", een gecentreerde kaart: icoon, kop, één zin uitleg, en een primaire actieknop. De knop vult de speler vooraf in en routeert naar de wizard waar die bestaat (vlak formulier anders). Alleen-lezen-kijkers (scout / ouder / een speler op zijn eigen dossier) zien wel de kop en uitleg, maar geen knop — de aanmaakactie wordt onderdrukt omdat ze de bevoegdheid niet hebben. De Activiteiten-lege-staat legt uit dat activiteiten op teamniveau worden vastgelegd; de CTA verdwijnt wanneer de speler nog geen team toegewezen heeft en wordt vervangen door "Wijs deze speler eerst toe aan een team".
- **Aantal-badges per tab.** Elke niet-Profiel-tab toont een kleine badge met het aantal records (Doelen 12, Evaluaties 4, Activiteiten 38, enz.). Tabs zonder records worden in een gedempte kleur weergegeven zodat je in één oogopslag de gevulde tabs ziet zonder elke lege tab open te klikken.
- **Profiel-tab tweekoloms-layout.** Vanaf 768px splitst de Profiel-tab zich in Identiteit (geboortedatum, positie, voet, rugnummer, status) links en Academie (team, leeftijdscategorie, datum toegetreden) rechts. Eén kolom op mobiel.

## Spelersdossier-UX herontwerp (v4.8.0 — #977)

Het spelersdossier is opnieuw opgebouwd als een port van `.local-mockups/player-profile/index.html`. Backend ongewijzigd — dezelfde `tt_players`-rij, dezelfde `tt_view_players`-bevoegdheidspoort, dezelfde `?tt_view=players&id=N`-URL — maar het visuele contract verandert ingrijpend.

- **Hero.** Papieren ondergrond met zachte onderschaduw (geen blauwe verloopstrook meer). Het statussignaal verhuist naar een 4px gekleurde rand om de avatar — groen voor `active`, goud voor `trial`, rood voor `released`, neutraal grijs voor `inactive`. Rugnummer wordt een kleine badge in de rechteronderhoek van de avatar met een papier-gekleurde outline. Daaronder: naam + teamlink + statuspil (met inline "X jr in de academie") + eerste positiepil.
- **Actierij.** `+ Gedrag vastleggen` (primair geïnverteerd) · `Potentieel instellen` · `Bewerken` · `⋯` overflow met **Archiveren** en (als de speler nog geen team heeft) **Aan team toewijzen**. Bevoegdheidsgating ongewijzigd: `tt_rate_player_behaviour`, `tt_set_player_potential`, `tt_edit_players`.
- **Kerngegevens-strook.** Drie kaartjes (Geboortedatum / Voet / Toegetreden) met telkens een hintregel (leeftijd, alternatieve positie, jaren-in-academie). 3-koloms op mobiel + tablet; verandert in een verticale 1-koloms stapel op desktop waar de strook in de linkerkolom belandt.
- **In één oogopslag KPI-strook.** Drie KPI-kaarten — Gem. beoordeling (met `▲`/`▼` trendpijl t.o.v. het rollende gemiddelde), Aanwezigheid % (laatste 30 dagen), Doelen (actief aantal met optionele hint `N binnenkort verlopen` wanneer er één binnen 7 dagen vervalt). Elke kaart is een link die naar de bijbehorende tab springt.
- **Tabs.** Pil-vormige chips vervangen de onderstreepte navigatie. Elke tab draagt een teller-badge via `PlayerFileCounts::for()` zodra het aantal > 0 is. Mobiel scrolt horizontaal; tablet+ vouwt naar één zichtbare rij. De Notities-tab verdwijnt volledig voor gebruikers zonder `tt_view_player_notes`. **Schrijf notities alsof het gezin meeleest**: verborgen voor spelers en ouders in de UI, maar bij een AVG-inzageverzoek blijven ze opvraagbaar tenzij je FG een gerechtvaardigd-belang-uitsluiting documenteert (zie de privacy-operatorgids).
- **Profiel-tab.** De Identiteit- en Academie-kaarten blijven (zelfde velden, nu in kaart-met-kv-rij chrome). Twee nieuwe kaarten: **Ouders · Voogden** (toont gekoppelde rijen uit `tt_player_parents` met naam + primair-vlag + telefoon + e-mail) en **Ontdekking** (toont de `tt_prospects`-rij die gepromoveerd is naar deze speler, met scout + evenement + datum). Vriendelijke lege staten wanneer er geen koppeling bestaat.
- **Lijsttabs.** Doelen / Evaluaties / Activiteiten / PDP / Stage / Notities krijgen allemaal een uniform card-row-patroon: 44px datumbadge | titel + meta | chevron of rechterkant-chip. Datumbadges kleuren rood-getint voor doelen die binnen 7 dagen verlopen en accent-blauw voor de activiteit van vandaag. Evaluaties dragen een kleur-gecodeerde beoordelingschip (groen voor ≥75% van de schaal, oranje voor <50%). Geplande activiteiten tonen een neutrale "Gepland"-pil in plaats van de standaard-Aanwezig pre-fill van de wizard.
- **PDP-tab.** Actieve cyclus toont een voortgangsbalk in 4 stappen (kickoff → midcyclus → einde cyclus → afgetekend). Voorgaande cycli verschijnen als card-row-lijst.
- **Spelerskaart-tab (v4.63.x — #1988).** De kaart-showcase die voorheen alleen op de eigen "Mijn kaart" van een speler stond, is nu een tabblad op het samengevoegde profiel — zo ziet een trainer, hoofd opleiding of ouder de stand in één oogopslag zonder de spelerspagina te verlaten: de vaardighedenradar, de FIFA-achtige spelerskaart en de vier beoordelings-KPI's (Laatste, Laatste 5 met momentum-delta, Aller tijden, Evaluaties). Zelfde doelgroep als de rest van de pagina; geen extra rechten. Vóór de eerste beoordeelde evaluatie toont de kaart zijn eigen "binnenkort"-status, rendert de radar niets in plaats van een lege figuur en blijft de KPI-rij verborgen.
- **Drie responsive vormen.** Mobiel (≤719px) — één kolom, sticky horizontaal-scrollende tabs. Tablet (720-1023px) — één kolom op max 720px, tabs vouwen, Profiel-kaarten 2-koloms, 96px avatar. Desktop (≥1024px) — twee-koloms grid: 320px linkerkolom (Kerngegevens + In één oogopslag verticaal) + flexibele rechterkolom (tabs + actieve sectie). Hero + actierij overspannen beide kolommen. De `.tt-player-detail__rail` en `.tt-player-detail__main` wrappers gebruiken `display: contents` onder 1024px, zodat de kolomhoogtes onafhankelijk blijven op desktop.
- **Wat eruit blijft.** Analytics-tab (verwijderd v3.110.187) wordt niet teruggebracht. Inline rij-acties voor archiveren/verwijderen op Evaluaties (verwijderd v3.110.148) blijven weg — destructieve acties leven op de evaluatie-detailpagina.

## Teamdetail — stagespelers (v3.79.0)

De teamdetailpagina toont nu lopende stagespelers in een eigen subsectie **Stagespelers**. Eerder vielen ze uit de roster door de actieve-status-filter.

## Teamdetail — herontwerp in spelersprofiel-stijl (v4.40.0 — #1613)

De teampagina is opnieuw opgebouwd in de stijl van het [spelersprofiel](?page=tt-docs&topic=teams-players): dezelfde vormen, hetzelfde kaartsysteem, hetzelfde responsieve rail/main-grid. Backend ongewijzigd — dezelfde `tt_teams`-rij, dezelfde `tt_view_teams`-poort, dezelfde `?tt_view=teams&id=N`-URL.

- **Hero.** Papieren ondergrond met het teamembleem (initialen in een accentchip, status-gekleurde rand), naam, een "Teams · leeftijdsgroep"-subregel en identiteitspillen (status, leeftijdsgroep, aantal spelers). De hero wordt **altijd getoond** en kan niet verborgen worden.
- **Actierij.** Nieuwe activiteit (primair) · Planner · Bewerken · Seizoens-intakes printen · Aanpassen · `⋯` overflow (Archiveren). Bevoegdheidsgating: Nieuwe activiteit → `tt_edit_activities`, Bewerken + Archiveren → `tt_edit_teams`, batch-print → `tt_edit_goals`, Aanpassen → coach van dit team.
- **Kerngegevens-strook.** Leeftijdsgroep · Hoofdcoach · Spelers. 3-koloms op mobiel, verticaal in de linkerkolom op desktop.
- **In één oogopslag KPI's.** Aankomend (geplande activiteiten in de komende 14 dagen, linkt naar de planner) · Gem. aanwezigheid (laatste 30 dagen) · Gem. selectiebeoordeling (gemiddelde over de evaluaties van de selectie, op de beoordelingsschaal van de academie). De getallen komen uit `TeamKpisRepository`, niet uit de view.
- **Kaarten.** Roster, Staf, Teaminfo, Stagespelers (indien aanwezig), Aankomende activiteiten — elk een kaartpaneel. **Elke tabelrij is nu een hele-rij-link** (Roster → speler, Staf → persoon, Aankomende activiteiten → activiteit) — dit lost het oude probleem op dat "de tabel met aankomende activiteiten geen rij-klik had". De interne kolomlink blijft het toetsenbord- / hulptechnologiepad; middenklik en cmd/ctrl-klik openen in een nieuw tabblad.

### Aanpassen — secties per coach

Een knop **Aanpassen** (alleen zichtbaar voor coaches die het team beheren) opent een paneel met sectie-schakelaars. De keuze is **persoonlijk voor die coach** en geldt voor **alle teams die hij/zij coacht** — het is geen clubbrede instelling en verandert niets aan wat anderen zien. De schakelbare secties zijn: Kerngegevens, In één oogopslag, Roster, Staf, Teaminfo, Stagespelers, Aankomende activiteiten. De hero wordt altijd getoond.

De voorkeur wordt per gebruiker opgeslagen en gelezen/geschreven via `GET`/`PUT /wp-json/talenttrack/v1/me/preferences/team-detail`, zodat een toekomstige niet-WordPress-frontend dezelfde indeling krijgt. Spelers, ouders, beheerders en coaches die niets hebben aangepast zien allemaal de standaard — elke sectie aan.

## Bewerk-rechtenpad (v3.79.0)

De bewerken-knop op de teamdetailpagina en de Teams REST-endpoints (list / get / create / delete) gebruiken nu `AuthorizationService::userCanOrMatrix` in plaats van `current_user_can`. Daardoor passeren ook gebruikers de poort die `tt_edit_teams` via de matrix scope-rij krijgen (functionele rol-bridge), in lijn met het patroon dat al voor tegels en de Activities REST geldt.
