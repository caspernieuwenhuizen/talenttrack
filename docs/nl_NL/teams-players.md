<!-- audience: admin -->

# Teams & spelers

## Teams

Een **team** is een selectie binnen een specifieke leeftijdscategorie (bijv. "U13 Blauw", "U15 Rood"). Elk team heeft:

- Een naam en een optioneel label voor de leeftijdscategorie
- Een hoofdcoach (uit je **Personen**-register)
- Toegewezen spelers

Maak teams aan op de beheerpagina **Teams**. De leeftijdscategorie is belangrijk, omdat [categorie gewichten](?page=tt-docs&topic=eval-categories-weights) per leeftijdscategorie worden gedefinieerd.

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

## Teamdetail — stagespelers (v3.79.0)

De teamdetailpagina toont nu lopende stagespelers in een eigen subsectie **Stagespelers**. Eerder vielen ze uit de roster door de actieve-status-filter.

## Bewerk-rechtenpad (v3.79.0)

De bewerken-knop op de teamdetailpagina en de Teams REST-endpoints (list / get / create / delete) gebruiken nu `AuthorizationService::userCanOrMatrix` in plaats van `current_user_can`. Daardoor passeren ook gebruikers de poort die `tt_edit_teams` via de matrix scope-rij krijgen (functionele rol-bridge), in lijn met het patroon dat al voor tegels en de Activities REST geldt.
