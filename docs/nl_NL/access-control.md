---
title: Toegangsbeheer
group: frontend
summary: Rollen, rechten, functionele rollen en de Alleen-lezen Waarnemer.
audience: [admin]
views: [roles, matrix]
order: 50
---

# Toegangsbeheer

TalentTrack gebruikt het rechten-systeem van WordPress, plus een eigen overlay van "functionele rollen", om te bepalen wie wat mag. De release v3.0.0 refactorde rechten naar granulaire view/edit-paren, waardoor alleen-lezen rollen nu echt over de hele plug-in heen werken.

## De rechten

Elk groot onderdeel heeft een **view**-recht en, voor schrijfbare onderdelen, een bijbehorend **edit**-recht:

| Onderdeel | View-recht | Edit-recht |
|--------------|-----------------------|-----------------------|
| Teams | `tt_view_teams` | `tt_edit_teams` |
| Spelers | `tt_view_players` | `tt_edit_players` |
| Personen | `tt_view_people` | `tt_edit_people` |
| Evaluaties | `tt_view_evaluations` | `tt_edit_evaluations` |
| Sessies | `tt_view_activities` | `tt_edit_activities` |
| Doelen | `tt_view_goals` | `tt_edit_goals` |
| Instellingen | `tt_view_settings` | `tt_edit_settings` |
| Rapporten | `tt_view_reports` | *(geen edit-tegenhanger)* |

Elke TalentTrack-gebruiker heeft ook het basisrecht `read` van WordPress nodig om te kunnen inloggen.

## Legacy-rechten

De rechten van vóór v3 bestaan nog steeds en werken nog steeds:

- `tt_manage_players` — impliciet toegekend als een gebruiker zowel `tt_view_players` ALS `tt_edit_players` heeft
- `tt_evaluate_players` — impliciet toegekend met zowel `tt_view_evaluations` ALS `tt_edit_evaluations`
- `tt_manage_settings` — impliciet toegekend met zowel `tt_view_settings` ALS `tt_edit_settings`
- `tt_view_reports` — onveranderd

Daardoor blijft externe code of andere plug-ins die op legacy-rechtennamen controleren zonder aanpassing werken. Louter-lezen gebruikers (de rol Waarnemer) falen terecht op legacy `manage`-checks omdat hun edit-tegenhanger ontbreekt.

## Een leesrecht geeft nooit toestemming om te schrijven

Iets bekijken en iets wijzigen zijn twee verschillende rechten, en de wp-adminpagina's houden zich daar nu overal aan waar een smaller recht bestaat om dat te zeggen.

Vijf wp-adminschermen schermden hun **opslaan** af met een recht waarvan de naam *bekijken* zegt: Categoriegewichten, Aangepaste velden, Evaluatiecategorieën, Eval-typecategorieën en Personen. Bij elk daarvan was het menu-item dat ernaartoe leidt al afgeschermd met het smallere leesrecht, dus de pagina was via de URL bereikbaar voor iemand die het toegangspunt bewust verbergt — en de schrijfactie erachter werd toegestaan op grond van leestoegang.

**Voor wie dit iets verandert.** Vooral voor het **Hoofd Ontwikkeling**. `tt_view_settings` is een verzamelrecht: je hebt het zodra je alle deel-leesrechten hebt. Het Hoofd Ontwikkeling heeft die bewust — het mag de configuratie inzien — en is bij het opsplitsen van de instellingenrechten juist zijn `tt_edit_*`-rechten kwijtgeraakt. De wp-adminpagina's gaven dat schrijfrecht via het lees-verzamelrecht terug. Dat gebeurt niet meer. Moet een Hoofd Ontwikkeling echt categoriegewichten, aangepaste velden of evaluatiecategorieën kunnen wijzigen, geef dan het bijbehorende `tt_edit_*`-recht: een bewuste keuze in plaats van een bijeffect.

Clubbeheerder en beheerder merken niets: die hebben alle betrokken schrijfrechten al. Trainers en teammanagers ook niet: die hadden `tt_view_settings` nooit.

Twee schrijfacties noemen nog een leesrecht. Beide zijn vastgelegd in plaats van stilzwijgend verruimd, omdat het juiste recht nog niet bestaat en er een verzinnen op zichzelf een wijziging aan het rechtenmodel is:

| Actie | Afgeschermd met | Waarom nog open |
| - | - | - |
| Een rol toekennen of intrekken bij een persoon | `tt_view_settings` | Er is geen recht voor het toekennen van een rol. Het dichtstbijzijnde, `tt_manage_authorization`, betekent "de rechtenmatrix bewerken" — een andere handeling. |
| Een geplande rapportage archiveren | `tt_view_analytics` | Er is geen schrijfrecht voor analyse. |

## Een recht zegt óf je mag, een scope zegt van wie

`tt_edit_activities` hebben betekent dat je trainingen plant. Het zegt niet **van wie**, en een tijdlang vroegen de schrijfroutes voor activiteiten daar helemaal niet naar.

`userCanOrMatrix()` beantwoordt de rechtenvraag en beperkt bewust niet tot een team — dat staat ook in de eigen docblock. Elke coach heeft `tt_edit_activities`, dus `POST /activities`, `PUT /activities/{id}`, archiveren, terugzetten en definitief verwijderen gaven allemaal `true` voor élke activiteit in de club. De *lijst* beperkte altijd al tot de eigen teams, en juist daarom viel het niemand op: de coach zag de wedstrijd van een ander team niet, maar kon hem op id wél aanpassen.

Alle vijf de schrijfacties op één record stellen nu beide vragen. De regel gaat over **scope, niet over persona**:

- wie `activities` wijzigen heeft op **globale** scope, schrijft elke activiteit — hoofd opleiding, academiebeheer;
- wie het op **team**-scope heeft, schrijft alleen de eigen teams: coach, teammanager, of een persona die nog niet bestaat;
- anders antwoordt de route `403 forbidden_team` en wordt er niets weggeschreven.

**Een activiteit naar een ander team verplaatsen vraagt beide kanten.** Een update die `team_id` wijzigt, vereist dat de aanvrager zowel het team van herkomst als het team van bestemming heeft. Slechts één kant controleren zou een coach de mogelijkheid geven een ongewenste wedstrijd bij een andere selectie neer te leggen, of er juist een weg te halen — allebei zijn het schrijfacties op een team waar hij niets over te zeggen heeft.

Een activiteit zonder team heeft geen team om buiten scope van te vallen; daar is het recht het hele antwoord.

## Een leesrecht is geen clubbrede gegevenstoegang

`tt_view_players` beantwoordt de vraag *"mag deze persoon naar spelers kijken"*.
Niet *"mag deze persoon naar **deze** spelers kijken"* — dat is het teambereik
uit de rechtenmatrix, en daarom staat er bij een hoofdtrainer
`players [r, team]` en niet `[r, global]`.

De frontend- en REST-schermen hebben zich altijd aan dat bereik gehouden. Zeven
wp-adminpagina's niet: Spelers, Teams, Evaluaties, Doelen, Activiteiten,
Speler-ratecards en Rapportages bouwden hun lijsten en keuzelijsten uit de
ongefilterde query en lieten het menurecht doorgaan voor de gegevenstoegang.
Een trainer die naar wp-admin navigeerde zag elk kind in de academie — op de
spelerslijst inclusief geboortedatum en de naam, het e-mailadres en het
telefoonnummer van de ouder.

Ze tonen een trainer nu alleen de spelers en teams van de eigen teams:

- **Lijsten** filteren op dezelfde manier als hun REST-tegenhanger. De
  spelerslijst controleert elke regel met dezelfde toets die `GET /players`
  gebruikt, zodat de regels en het aantal kloppen — en een ouder ziet nog
  steeds het eigen kind.
- **`action=edit` en `action=view`** weigeren een id buiten het bereik voordat
  er een selectie-, staf- of aanwezigheidspaneel wordt getoond. `?id=1,2,3…`
  aflopen leest geen ander team meer.
- **Keuzelijsten op bewerkformulieren** houden het huidige team of de huidige
  speler van het record selecteerbaar, ook buiten het bereik van de kijker, dus
  opslaan kan die koppeling niet stilzwijgend wissen.

Een beheerder, en elke persona met een **globaal** leesrecht op de entiteit —
Hoofd Ontwikkeling, Academiebeheerder, Clubbeheerder en de Alleen-lezen
Waarnemer op de schermen waarvoor het is toegekend — ziet nog steeds alles.

Meldt een trainer dat een wp-adminlijst leeg is, vraag dan aan welke teams die
persoon is gekoppeld onder **Personen → Functionele rollen**: het bereik komt
uit die koppelingen, niet uit het recht.

## De vooraf geconfigureerde rollen

| Rol | View | Edit |
|------------------------------|-----------------------|--------------------------------------------------------|
| **Hoofd opleiding** | Alle onderdelen | Alle onderdelen (incl. Evaluaties, Instellingen) |
| **Clubbeheerder** | Alle onderdelen | Teams, Spelers, Personen, Sessies, Doelen, Instellingen|
| **Coach** | Alles behalve Instellingen | Evaluaties, Sessies, Doelen |
| **Scout** | Teams, Spelers, Evals | Evaluaties |
| **Staf** | Teams, Spelers, Personen | Spelers, Personen |
| **Speler** | Alleen eigen data | Alleen eigen profiel |
| **Ouder** | Alleen data van kind | *(geen)* |
| **Alleen-lezen Waarnemer** | **Alle onderdelen** | **Geen** |

Wijs rollen toe via **Toegangsbeheer → Rollen & Rechten** of de standaard Gebruikersadmin van WordPress.

De toegang van een **ouder** tot zijn of haar kind wordt automatisch afgeleid van de ouder–kindkoppeling (gelegd wanneer de ouder de uitnodiging accepteert): de ouderrol wordt toegekend, afgebakend tot elk gekoppeld kind, op het moment dat het nodig is. Een ouder/verzorger ziet alleen de gegevens van zijn of haar eigen gekoppelde kind(eren) — nooit het kind van een ander gezin, en nooit de andere verzorgers die aan hetzelfde kind gekoppeld zijn.

## Alleen-lezen Waarnemer

v3.0.0 maakt deze rol zinvol over de hele plug-in. Een waarnemer kan:

- Het volledige beheer zien: teams, spelers, personen, evaluaties, sessies, doelen, rapporten
- De tegellanding op de frontend zien met elke tegel waar hij/zij view-rechten voor heeft
- Detailweergaven openen en alle data zien

Maar niet:

- Iets toevoegen, bewerken of verwijderen
- Configuratie wijzigen
- Administratieve acties uitvoeren

Elke knop "bewerken", "toevoegen", "opslaan" of "verwijderen" is voor waarnemers verborgen omdat die afgeschermd is achter `tt_edit_*`. Directe URL-toegang tot edit-acties wordt op controller-niveau geblokkeerd.

Gebruiksgevallen:
- Assistent-coach in opleiding (later te promoveren naar Coach)
- Bestuurslid of clubvoorzitter die volledige inzage wil
- Externe beoordelaar of auditor
- Ouder-liaison met bredere zichtrechten dan gewone ouders

### Precies wat een waarnemer ziet

"Alle onderdelen" hierboven is de korte versie. Dit is de lijst, en het is de moeite waard die te lezen vóórdat je de rol aan iemand van buiten de academie geeft — een bestuurslid, een sponsor, een externe auditor. Een waarnemer leest, academiebreed:

| Wat ze mogen lezen | Wat niet |
| --- | --- |
| **Teams** — elk elftal, de selectie en de details | Iets aan een team wijzigen |
| **Spelers** — het dossier en profiel van elke speler | Een speler toevoegen, bewerken of verwijderen |
| **Personen** — de stafgids | Een stafdossier bewerken |
| **Evaluaties** — de beoordelingen die trainers vastleggen | Een evaluatie schrijven of delen |
| **Activiteiten** — de trainings- en wedstrijdkalender | Iets plannen, bewerken of afgelasten |
| **Doelen** — de ontwikkeldoelen van spelers | Een doel stellen of afsluiten |
| **Rapportages** — de rapportageschermen van de academie | Een rapportage bouwen of inplannen |
| **Instellingen** — de configuratieschermen, alleen-lezen | Een instelling wijzigen |

**En verder niets.** Een waarnemer ziet met name géén zorgnotities, blessures of andere medische gegevens, geen privénotities van trainers over een speler, geen gedragsbeoordelingen, geen potentieelinschaling, geen contactgegevens van ouders, geen foto's of video van spelers, geen privéberichten, geen auditlog en geen impersonatielog. Die blijven bij de mensen die er verantwoordelijk voor zijn — het meeste ligt alleen bij Hoofd Ontwikkeling en Academie-admin, en een deel wordt zelfs bewust niet aan hoofdtrainers gegeven.

Die grens is precies waar de rol om draait. "Alleen-lezen" klinkt onschuldig, en een stoel die de zorgnotities van een kind kon lezen zou dat niet zijn, hoe weinig die ook kan wijzigen.

### Per speler, niet alleen in bulk

Tot #3644 kon de waarnemer de evaluaties van alle teams naar een spreadsheet exporteren en werd hem dezelfde data op de pagina van één speler geweigerd. Beide routes vroegen "mag jij dit lezen", en ze vroegen het aan twee verschillende autoriteiten.

De bulkexports controleren op de ruwe leescapability, die de matrixbrug beantwoordt, dus `evaluations [r, global]` uit de seed liet hen door. Het pad per record — `AuthorizationService::canViewPlayer()` → `userHasPermission()` — bepaalde de scopes van een gebruiker uit `tt_user_role_scopes`, de functionele-rolkoppeling en de afgeleide speler-/ouderlinks, en **raadpleegde de matrix nooit**. Een waarnemer heeft geen van die rijen: zijn hele toekenning is de seed. Hij kwam dus op nul scopes uit en elke beslissing per record werd fout.

`userHasPermission()` raadpleegt nu als laatste de matrix, ná de scopebronnen, voor de leesrechten die daar een equivalent hebben (`players.view`, `players.view_own_children`, `evaluations.view`, `people.view`, `team.view`). Twee gevolgen zijn het benoemen waard:

- **Dit is geen waarnemersfix.** Elke persona wiens toekenning alleen in `config/authorization_seed.php` staat liep tegen dezelfde muur — een scout zonder rolscope-rij ook. De rolnaam van de waarnemer apart behandelen had de melding gesloten en het defect laten staan.
- **Bewust alleen lezen.** `change` en `create_delete` worden niet overbrugd. Dit ging over een geweigerde leesactie; een schrijfrecht overbruggen verruimt de toegang aan de kant waar een fout in het dossier van een kind terechtkomt, en is een eigen beslissing.

Een gebruiker zonder matrixrij én zonder scoperij wordt precies zo geweigerd als voorheen, en van geen enkele export is de capability versmald — de export was juist de route die met de seed overeenkwam.

## Staf

De rol Staf is de stoel voor de fysio, de materiaalman en algemene clubstaf. Ze is afgebakend tot **de elftallen waaraan die persoon verbonden is**, niet tot de academie:

| Wat ze mogen lezen en bewerken, voor hun eigen teams | Wat niet |
| --- | --- |
| **Spelers** van die teams | Bij een elftal komen waaraan ze niet verbonden zijn |
| **Personen** van die teams | Een speler aanmaken of verwijderen |
| **Spelersnotities** — het staf-only logboek in het spelersdossier | Een seizoensovergang draaien of spelersaccounts aanmaken |
| Hun eigen stafdossier, altijd | Een blessuredossier lezen, tenzij ze de fysio van dat team zijn |
| | Metingen lezen, tenzij hun functionele rol dat zegt |

Teamgegevens zijn voor staf alleen-lezen; bewerken kan bij spelers, personen en spelersnotities.

### Blessures en metingen volgen de functionele rol, niet de rol Staf

Staf is één rol die de fysio, de materiaalman en alles daartussen dekt. Voorheen droeg die rol ook **blessures** en **metingen** — voor een fysio precies goed, voor een materiaalman veel meer dan nodig, en er was geen manier om de één de shirts te geven zonder de ander de medische historie en de groeicurves.

Beide komen nu van de **functionele rol die iemand op een team heeft**, in te stellen via **Personen → Functionele rollen**:

| Functionele rol op het team | Blessures van dat team | Metingen van dat team |
| --- | --- | --- |
| **Fysio** | Lezen en vastleggen | Lezen |
| **Hoofdcoach** | — | Lezen |
| **Assistent-coach** | — | Lezen |
| **Materiaalman** | — | — |
| Manager, Overig | — | — |

Hoofdtrainers en assistent-trainers houden hun eigen, ruimere toegang via de rollen Coach: dat zijn WordPress-rollen met hun eigen rechten, en daar verandert hier niets aan. De tabel hierboven gaat over wat de *functionele rol* toevoegt aan een Staf-stoel.

Uit "op dat team, en op geen enkel ander" volgen twee dingen. Een fysio die aan drie elftallen gekoppeld is, leest de blessures van drie elftallen. Een fysio die aan één elftal gekoppeld is en bij een tweede alleen op de lijst staat, leest er één. En loopt de koppeling af, dan vervalt de toegang.

**Metingen vastleggen verandert niet.** De functionele rollen hierboven geven het *lezen* van de cijfers. Lengte, gewicht en testuitslagen invoeren hoort bij de rollen Coach, Hoofdcoach en Teammanager, en die blijven ongemoeid — niemand die vandaag een testavond draait, raakt het invoerscherm kwijt.

**Wélke tests iemand vervolgens ziet, is een aparte vraag.** Elke test in je catalogus heeft een zichtbaarheidsniveau, in te stellen via **Tests beheren**. De functionele rol bepaalt of iemand de metingsschermen überhaupt bereikt; het niveau van de test bepaalt welke cijfers daar te zien zijn. Een test die je als alleen-medisch hebt gemarkeerd, blijft dat voor iedereen.

Wat blijft: niemand in deze groep kan een blessuredossier of een meting **verwijderen**. Het weghalen van een medisch dossier van een minderjarige blijft bij het hoofd opleiding en de academiebeheerder.

### Wat er verandert voor een bestaand Staf-account

**Niets, totdat je die persoon een functionele rol geeft.** Een bestaand Staf-account zonder functionele rol op enig team houdt exact de toegang die het had — blessures en metingen van de gekoppelde elftallen inbegrepen, en ook het vastleggen van die metingen. Dat is bewust: stilletjes versmallen zou het blessurescherm of het invoerscherm midden in het seizoen weghalen bij mensen die ze gebruiken, zonder enige melding waarom.

De smallere vorm is iets waar een academie zelf voor kiest, persoon voor persoon, door een functionele rol toe te kennen. Zodra iemand als **Fysio** van een team staat geregistreerd, wordt de blessure- en metingstoegang precies dat team. Zodra iemand als **Materiaalman** staat geregistreerd, vervallen beide.

Het migratiepad is dus: ga naar **Personen → Functionele rollen**, geef elke stafmedewerker de rol die zijn of haar werk beschrijft, en de toegang volgt. Tot die tijd verandert er niets aan hun account.

**Staf krijgt het spelersbeheer niet.** Het recht achter "spelers beheren" draagt namelijk ook de seizoensovergang, het aanmaken van inloggegevens voor spelers, het bewerken van maatwerkvelddefinities en het verwijderen van spelersdossiers — een academiebreed beheerdersoppervlak, geen elftaloppervlak. Heeft een fysio een nieuwe speler nodig, dan vraagt die dat aan een trainer of beheerder.

Een stafmedewerker zonder elftal ziet niets. Dat is bewust: het koppelen aan hun teams is de handeling die de toegang geeft, en die is zichtbaar in de staflijst van het team. Hun dashboard zegt het ook: "Je bent nog niet aan een team gekoppeld. Vraag je academiebeheerder om je aan een team toe te voegen."

## Functionele rollen

Functionele rollen zijn de taken die mensen bij een elftal doen: Hoofdcoach, Assistent-coach, Fysio, Materiaalman, Manager. Een functionele rol toekennen verandert nooit iemands WordPress-rol. Het doet twee dingen, allebei alleen op dat team:

- Onder **Toegangsbeheer → Functionele rollen** is elke functionele rol gekoppeld aan een of meer autorisatierollen. De rechten daarvan gelden voor de persoon op het team waarop die de rol heeft.
- Vijf functionele rollen hebben daarnaast een eigen set rechten. Zie de tabel hieronder.

Het toewijzen van een persoon via Functionele rollen schrijft ook een rij in `tt_user_role_scopes` (scope_type=`team`, scope_id=het team) zodat de matrix-team-scopecontrole voor die persoon op dat team waar wordt. Bij het verwijderen van de laatste toewijzing voor een (persoon, team)-paar wordt ook de scope-rij verwijderd. Personen met meerdere rollen op hetzelfde team houden één scope-rij totdat de laatste rol wordt ingetrokken. De backfill-migratie `0062_fr_assignment_scope_backfill.php` heeft installaties van vóór deze koppeling rechtgetrokken.

### Een functionele rol kan ook zelf toegang geven

Vijf functionele rollen dragen een kleine set rechten die geldt **op het team waarop de rol gehouden wordt, en nergens anders**:

| Functionele rol | Wat die op dat team geeft |
| --- | --- |
| **Fysio** | Blessures lezen en vastleggen; metingen lezen; de spelerslijst van de selectie openen |
| **Hoofdcoach** | Metingen lezen |
| **Assistent-coach** | Metingen lezen |
| **Materiaalman** | De selectie, de mensen eromheen, de activiteitenkalender en de vakantiekalender van de academie lezen, en de team-, speler- en activiteitenschermen openen |
| **Manager** | De selectie, de mensen eromheen, de activiteitenkalender en de vakantiekalender van de academie lezen, en de team-, speler- en activiteitenschermen openen; aanwezigheid vastleggen; de beschikbaarheid van spelers lezen (het statusstoplicht) |

De vakantiekalender van de academie hoort bij het schema, want zonder die kalender leest een gat tussen twee trainingen als ontbrekende data in plaats van als een geplande onderbreking. Voor allebei de rollen is het alleen lezen: het bijhouden van de kalender blijft bij degene die dat doet, en geen van beide rollen krijgt het scherm Vakanties aangeboden.

Een Manager leest het schema maar maakt of wijzigt geen activiteiten; dat blijft bij de trainers. Een Manager krijgt geen toegang tot blessures. Een teammanager die ook EHBO doet, krijgt **Fysio** als tweede functionele rol op hetzelfde team, en het blessurelogboek volgt die rol.

Een Manager legt de aanwezigheid vast in het **aanwezigheidsraster**, bereikbaar vanaf de activiteit zelf — de acties Bewerken, Archiveren en Afronden van de activiteit blijven bij de trainers. Het raster is een desktopsurface; op een telefoon verschijnt de gebruikelijke desktop-melding met de activiteitenlijst als alternatief. Trainers zien dezelfde link. Een materiaalman niet: het schema lezen en de aanwezigheid vastleggen zijn twee verschillende taken.

"Het scherm openen" is echt een ander recht dan "de data lezen". Het dashboard bepaalt via een tegelzichtbaarheidsentiteit (zie de volgende paragraaf) of een surface wordt aangeboden, en REST bepaalt via de data-entiteit wat die surface mag tonen. Het tweede hebben zonder het eerste is precies waardoor een teammanager zijn hele selectie via de API las en op Teams, Spelers en Activiteiten *"Je hebt geen toegang tot dit onderdeel"* kreeg. De paneelentiteit toevoegen geeft geen enkele regel extra te lezen; het zorgt dat de twee helften hetzelfde antwoord geven.

De lijst voor de materiaalman staat er bewust helemaal uitgeschreven. "Alles wat de rol Staf heeft, behalve blessures" zou een definitie door aftrekken zijn, en het volgende gevoelige onderdeel dat aan Staf wordt toegevoegd zou dan op de stoel van de materiaalman belanden zonder dat iemand dat besloten heeft. Dat is geen theorie: de metingen bleven na de blessures nog één release op de Staf-stoel staan, en precies zo lang las een materiaalman de groeicurve van elke speler.

Dit is een echte tweede bron van toegang, geen etiket: het antwoord voor een persoon is wat zijn rol geeft **plus** wat deze functionele rollen geven, samen opgelost. De set staat in `config/functional_role_grants.php`; eigen regels toevoegen is een codewijziging, geen instelling.

## Tegelzichtbaarheid via aparte entiteiten

Dashboardtegels die uitkomen op een coach- of beheerderssurface declareren een tegelspecifieke matrixentiteit (`team_roster_panel`, `coach_player_list_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `people_directory_panel`, `scouting_visits_panel`, `holidays_panel`, `wp_admin_portal`) los van de onderliggende data-entiteit (`team`, `players`, `evaluations`, …). De data-entiteiten blijven REST + repository-reads sturen — de dispatcher en de tegel-gate vragen het `*_panel`-entiteit aan, zodat het verlenen van "scout leest teamdata globaal" niet langer een coach-tegel **Mijn teams** op het scoutdashboard plaatst. De dispatcher (`DashboardShortcode`) leest de entiteit uit het tegelregister en raadpleegt `MatrixGate::canAnyScope` voor hetzelfde antwoord als de tegel-gate, zodat de eerdere situatie waarin een tegel rendert maar de bestemming alsnog *"Dit onderdeel is alleen beschikbaar voor coaches en beheerders."* meldt, definitief weg is.

**Scoutbezoeken is het uitgewerkte voorbeeld.** Een hoofdcoach leest `prospects` op teamniveau met opzet — #0081 gaf hem de instroomtrechter van zijn eigen leeftijdsgroep. De tegel Scoutbezoeken was op diezelfde `prospects`-entiteit gezet om een losstaande 403 op te lossen, en daarmee zaten de twee aan elkaar vast: de hoofdcoach kreeg de bezoekplanner van de scout er gratis bij, en de `prospects`-toekenning weghalen om die te verbergen zou de trechter hebben meegenomen. De tegel declareert nu `scouting_visits_panel`, geseed als lezen-globaal voor **scout**, **hoofd opleidingen** en **clubbeheerder**, en niet voor de hoofdcoach. De schermen zelf blijven op de prospects-rechten afgeschermd, want dat is de data die ze lezen; de paneelentiteit bepaalt alleen wie het scherm krijgt aangeboden. Migratie `0233` vult de entiteit bij op bestaande installaties — zonder die migratie zou de tegel voor iedereen verdwijnen, omdat de dispatch-gate de live matrix leest en niet het seed-bestand.

## Kruislinks tussen weergaven — `CrossViewLink`
Een in-body navigatie-affordance — een kruislink, tegel of knop die naar een andere `?tt_view=<slug>`-weergave verwijst — moet **verborgen zijn wanneer de huidige gebruiker de doelweergave niet kan bereiken**. Voorheen controleerde elke zo'n link de rechten van het doel inline, en die controles liepen uit de pas met de daadwerkelijke early-return-guard van de doelweergave.

`\TT\Shared\Frontend\Components\CrossViewLink` centraliseert die beslissing. De HTML van de link wordt alleen weggeschreven wanneer de huidige gebruiker slaagt voor de gate van de doel-slug:

```php
CrossViewLink::render( 'team-planner', function () use ( $url ) {
    echo '<a class="tt-player-action" href="' . esc_url( $url ) . '">'
        . esc_html__( 'Planner', 'talenttrack' ) . '</a>';
} );
```

Voor een keuze tussen link en span (een actieve link bij toegang, anders een inerte `<span>`) vertak je op de beslishulp: `CrossViewLink::allows( 'methodology' )`.

**Gates staan op één plek.** `CoreSurfaceRegistration::registerCrossViewLinkGates()` koppelt elke slug aan een gate die de **eigen guard van de doelweergave** weerspiegelt — *niet* de zichtbaarheidsentiteit van de dashboardtegel, die vaak verschilt (de `team-planner`-tegel declareert bijvoorbeeld de entiteit `activities_panel` voor tegelzichtbaarheid, maar de team-planner-weergave dwingt `tt_view_plan` af). Een gate is één van:

- een **rechten-string** (bv. `'tt_view_plan'`) → geëvalueerd via `AuthorizationService::userCanOrMatrix`;
- een **`[entity, activity]`-paar** (bv. `['measurements','change']`) → geëvalueerd via `MatrixGate::canAnyScope`;
- een **closure** `fn(int $uid, array $ctx): bool` — voor guards die context nodig hebben (bv. `player-attributes` draait `AuthorizationService::canEvaluatePlayer($uid, $ctx['player_id'])`).

Geef context per link door via `['ctx' => [...]]`; geef een eenmalige expliciete gate door via `['gate' => …]` om het register te overschrijven.

**Een gegate kruislink toevoegen:**

1. Registreer de gate van de doel-slug in `registerCrossViewLinkGates()`, spiegelend aan de echte early-return-guard van die weergave.
2. Verpak de link-render in `CrossViewLink::render( '<slug>', … )` (of vertak op `CrossViewLink::allows`).
3. Als de link recordcontext nodig heeft (een speler-id, team-id), geef die door via `['ctx' => …]` en lees hem in de gate-closure.

Een niet-geregistreerde slug valt terug op een toegeeflijke leescontrole (de gedeclareerde entiteit van de tegel op `read` wanneer de matrix actief is, anders toestaan), zodat bestaande interne links blijven werken; de CI-gate `xview-link-lint.yml` laat een PR falen die een **nieuwe** ongegate `tt_view`-kruislink toevoegt in een `src/**/Frontend/**`-bestand. Voor een terechte uitzondering plaats je een afsluitend `/* tt-xview-ok */` op de regel.

## Entiteiten van de instroompijplijn

De recruitmenttrechter introduceert twee nieuwe matrixentiteiten, met een opzettelijk smal toegangsbereik omdat prospect-gegevens de gevoeligste PII in het systeem zijn (verzameld voordat er een contractuele relatie bestaat — wettelijke grondslag is toestemming):

- **`prospects`** — Hoofdcoach leest op teamniveau (de eigen leeftijdscategorie). Scout heeft RCD op *self*-niveau — een scout kan letterlijk geen prospects van een andere scout zien via welk codepad dan ook (afgedwongen op SQL-niveau in `ProspectsRepository`). Hoofd Opleiding en Academy Admin hebben RCD globaal.
- **`test_trainings`** — zelfde toegangsbereik, behalve dat de Scout deze globaal mag lezen (zodat een scout de geplande sessie kan zien waarvoor zijn prospect is uitgenodigd).

Een dagelijkse retentie-cron ruimt vastgelopen of definitief afgewezen prospects automatisch op, conform `wp_options.tt_prospect_retention_days_no_progress` (standaard 90) / `tt_prospect_retention_days_terminal` (standaard 30). Doorgestroomde prospects (`promoted_to_player_id IS NOT NULL`) blijven beschermd — bij doorstroming worden de prospect-gegevens onderdeel van de PII van een academy-speler en blijft de rij staan in het `PlayerDataMap`-erasure-manifest, gekoppeld aan de identiteit van de speler.

## Prullenbakbeheer — `tt_manage_recycle_bin`

Definitief verwijderen is de meest destructieve actie in het product en zit
daarom achter een eigen capability: **`tt_manage_recycle_bin`**. Het regelt
het bekijken van de prullenbak, het herstellen van weggegooide records en het
definitief opschonen ervan.

De capability wordt **alleen** verleend aan de WordPress-administrator en de
rol Academiebeheerder (`tt_club_admin`). Hij maakt bewust **geen** deel uit
van `RolesService::VIEW_CAPS` / `EDIT_CAPS` — die stromen via
`allViewCapsTrue()` door naar het Hoofd Ontwikkeling en de Alleen-lezen
Waarnemer, wat de prullenbak zou geven aan rollen die geen gegevens mogen
opschonen. In plaats daarvan zit hij in een eigen `RECYCLE_BIN_CAPS`-constante:
`ensureCapabilities()` verleent hem aan WP `administrator`, en de
`tt_club_admin`-roldefinitie noemt hem expliciet. Geen enkele andere
roldefinitie verwijst ernaar, dus coaches, HoD, scouts, staf en waarnemers
houden hem nooit. Het recht `tt_edit_settings` geeft hem **niet**.

Dit is de **enige eigenaar van definitief verwijderen**: de oude per-entiteit
`DELETE /{entity}/{id}/permanent`-endpoints (die voorheen op het zwakkere
`tt_edit_settings` gaten) worden opnieuw afgeschermd op dezelfde capability,
zodat geen verwijderpad zwakker is dan de prullenbak. Zie
[Prullenbak](recycle-bin.md) voor de bewaartermijn en AVG-grondslag.

## Veiligheidsbericht — `tt_send_safeguarding_broadcast`

Een veiligheidsbericht bereikt elk gezin in de gekozen doelgroep en **geen
enkele ontvanger kan het weigeren**: het negeert berichtvoorkeuren en het
negeert de stille uren. Maximaal bereik plus geen afmeldmogelijkheid is de
reden dat het een eigen capability heeft, **`tt_send_safeguarding_broadcast`**,
in plaats van mee te liften op een bestaande.

Twee bestaande capabilities lagen voor de hand en zijn allebei verkeerd.
`tt_send_email` heeft elke coach — één ouder mailen en alle gezinnen
onweigerbaar aanschrijven zijn niet dezelfde handeling. En
`tt_view_player_safeguarding` is een *lees*recht op een gevoelige gebeurtenis
in het dossier van één speler: het juiste onderwerp, het verkeerde werkwoord
en het verkeerde bereik.

Hij wordt **alleen** verleend aan de WordPress-administrator en de rol
Academiebeheerder (`tt_club_admin`), en net als `tt_manage_recycle_bin` zit
hij bewust niet in `RolesService::VIEW_CAPS` / `EDIT_CAPS`, zodat hij niet
doorstroomt naar het Hoofd Ontwikkeling of de Alleen-lezen Waarnemer. Een
**hoofdcoach kan er standaard geen versturen**, en het Hoofd Ontwikkeling
evenmin.

Een academie waarvan de aandachtsfunctionaris veiligheid geen
academiebeheerder is, verleent die persoon de capability. Dat is de bedoelde
route: verbreden is een bewuste, vastgelegde handeling en niet iets wat een
rol erft.

Puur capability-gestuurd, **geen matrix-entiteit** (het precedent van de
Databrowser). De matrix modelleert bereik, en dit bericht heeft daar geen
zinvolle bereikdimensie — de doelgroep wordt per verzending gekozen, vóór een
bevestigingsstap die het aantal ontvangers noemt en vermeldt dat zij het niet
kunnen weigeren.

## Teamaankondigingen — `tt_send_team_announcement` / `tt_send_academy_announcement`

Een aankondiging is het gewone nieuws dat de gezinnen van een team moeten
weten. Anders dan het veiligheidsbericht is hij dus weigerbaar en houden de
stiltetijden hem vast. Wat wél nodig blijft, is een grens op *hoe ver* het
nieuws van één persoon reist, en dat zijn twee capabilities in plaats van
één, want dit zijn twee verschillende handelingen.

**`tt_send_team_announcement`** bereikt de gezinnen van de teams waaraan de
afzender is gekoppeld, en verder niemand. Standaard bij de rollen **Coach**
en **Staf** — de hoofdtrainer en de teammanager zijn de mensen om wie het
gaat. De toekenning is breed en in de praktijk toch smal: de capability zegt
dát iemand mag aankondigen, de teamkoppeling zegt bij wie, en wie hem heeft
zonder teamkoppeling bereikt niemand. Een academie die de beheerder moet
vragen om twaalf gezinnen te vertellen dat het veld dicht is, gebruikt
gewoon weer WhatsApp — en dat is precies wat dit moet beëindigen.

**`tt_send_academy_announcement`** bereikt elk team, een leeftijdscategorie
of alle gezinnen tegelijk. Standaard bij de **WordPress-administrator**, het
**hoofd ontwikkeling** en de rol **Academiebeheerder**. Gezinnen bereiken van
wie deze persoon het kind niet traint is een handeling op academieniveau, dus
is het een recht op academieniveau. Het impliceert het teamniveau — wie
iedereen mag aanschrijven, mag ook één ploeg aanschrijven.

Beide blijven buiten `RolesService::VIEW_CAPS` / `EDIT_CAPS`, net als de twee
blokken hierboven, zodat geen van beide doorsijpelt naar de Alleen-lezen
waarnemer.

**De doelgroep wordt bij binnenkomst gecontroleerd, niet pas bij het
versturen.** De capability opent de routes; `MassAnnouncementSender::canSend()`
bepaalt vervolgens of deze afzender *deze* doelgroep mag bereiken, en de
REST-route vraagt dat vóórdat er iets wordt verstuurd. Een teamgebonden
afzender die het id van een ander team meestuurt, krijgt een 403. De optie
verbergen in de keuzelijst is de beleefdheid; de weigering is de regel.

Puur capability-gestuurd, **geen matrix-entiteit**, om dezelfde reden als bij
het veiligheidsbericht: de doelgroep wordt per verzending gekozen en niet als
scope gemodelleerd.

## Modulebeheer — `tt_manage_modules` / `module_management`

Een hele TalentTrack-module aan- of uitzetten is een beheerder-niveau
handeling, dus staat het achter een eigen capability, **`tt_manage_modules`**,
en een **eigen matrix-entiteit, `module_management`**. De capability bepaalt
de toegang tot zowel de wp-admin Modules-pagina (`ModulesPage`,
`admin.php?page=tt-modules`) als het frontend-equivalent (`FrontendModulesView`,
`?tt_view=modules`), plus de `/wp-json/talenttrack/v1/modules` + `/features`
REST-routes.

Vóór #2187 bepaalde de wp-admin-pagina de toegang via een
**rolnaam-vergelijking** (`current_user_can('administrator')`), die de
autorisatiematrix niet kon besturen — een niet-beheerder-persona met het
recht in de matrix kon de pagina alsnog niet bereiken, wat het principe
"capabilities zijn het contract" schendt. #2187 vervangt beide controles
door `current_user_can('tt_manage_modules')`, zodat de matrix beslist.

`tt_manage_modules` wordt via `LegacyCapMapper` gebrugd naar
`module_management:create_delete`. Dit is een **eigen** entiteit, los van de
vooral-lezen `feature_toggles` configuratie-entiteit die het eerder deelde
 en van de `module_state` statusweergave: een module aan/uit zetten is
een wezenlijk ander recht dan een configuratie-feature-toggle bewerken, en
moet op een eigen rij door de matrix bestuurbaar zijn. De entiteit is
geseed **`rcd` globaal aan alleen Academy Admin** — overeenkomend met de ruwe
capability-houders (WordPress `administrator`, die elke `tt_*`-capability
omzeilt, plus de `tt_club_admin`-rol achter de Academy Admin-persona). Head
of Development heeft `feature_toggles [read]` maar **geen** `module_management`-rij,
dus wint niets — de omzetting is toegangsbehoudend.

Migratie `0194_authorization_seed_module_management` vult de
`module_management`-grant idempotent aan op bestaande installaties (INSERT
IGNORE, beperkt tot de ene entiteit + academy_admin-persona), zodat geen
enkele beheerder de Modules-pagina verliest bij een upgrade wanneer de matrix
actief is.

## Strava-koppeling — spelers koppelen hun eigen

Strava is persoonlijke activiteitsdata, dus een **speler** kan zijn eigen
Strava-account koppelen vanaf zijn profiel. Dit wordt geregeld door de
matrix-entiteit `strava_integration` met `self`-scope (lezen + wijzigen),
toegekend aan de persona `speler` — net als de `my_profile`-rechten van de
speler. Een speler beheert altijd alleen zijn **eigen** koppeling; door de
self-scope kan hij Strava niet koppelen voor een andere speler. De
Strava-**operatorconsole** (Configuratie → Integraties: app-credentials,
webhook-abonnement, koppelingenoverzicht) is een aparte `global`-scope die
Hoofdtrainer en Academie-beheerder hebben en wordt niet beïnvloed door het
spelerrecht. Zie de
[autorisatiematrix](authorization-matrix.md).

## Wanneer een rechtenwijziging van kracht wordt

Releases voegen rechten toe. Het veiligheidsbericht bracht er een mee, en de meeste nieuwe afgeschermde schermen brengen er vroeg of laat een mee. Een recht dat een release toevoegt, bereikt de rollen die het horen te hebben **zodra iemand na de update een TalentTrack-pagina opent** — welke pagina dan ook, op welk scherm dan ook. Het dashboard in de app telt mee. Een trainer die een speler opent telt mee. Je hoeft niets te draaien en niets aan te klikken, en een academie die de WordPress-beheeromgeving nooit opent wacht op niemand.

Dat is van belang omdat het niet altijd zo werkte. De hercontrole liep vroeger alleen bij het laden van een WordPress-beheerpagina. Dat was een veilige aanname zolang een academie runnen betekende dat je daar kwam. Dat hield op toen Setup, rechten en het dashboard allemaal naar de app verhuisden: een academie die volledig in de app werkt kan onbeperkt zonder wp-admin-pagina toe, en een recht dat een release had toegevoegd bleef precies zo lang ongetoekend. Lijkt een rol iets te missen wat een release-notitie beloofde, dan is het openen van een willekeurige pagina nu genoeg; **Configuratie → Database-update** hercontroleert desgewenst ook de volledige rollen- en rechtenopzet.

De hercontrole is **alleen aanvullend**, en dat is met opzet. Ze geeft een rol de rechten die de roldefinitie voorschrijft en neemt er nooit een weg — een recht dat een academie zelf aan een rol gaf, overleeft dus elke update.

**Een rol versmallen is werk voor de matrix.** De autorisatiematrix is een aparte opslag, en de hercontrole leest noch schrijft die: een recht dat je intrekt in **Configuratie → Autorisatiematrix** blijft ingetrokken bij elke volgende update. Een recht rechtstreeks in WordPress bij een rol weghalen beklijft niet op dezelfde manier — de roldefinitie noemt het nog steeds, dus de volgende update geeft het terug. Gebruik de matrix.

## Permission debug

Via **Toegangsbeheer → Permission Debug** kun je de effectieve rechten van een willekeurige gebruiker inspecteren. Handig als een gebruiker meldt "ik kan X niet zien" — controleer wat hij/zij daadwerkelijk heeft.

## De Toegangsbeheer-tools vinden

De geavanceerde autorisatiepagina's — Autorisatiematrix, Toegangsbeheer activeren, Gebruikers vergelijken, Permission Debug, Permission Chain Debug — staan onder de kop **Toegangsbeheer** in de TalentTrack-zijbalk in wp-admin. Ze verschijnen daar in zowel de oude als de moderne menu-indeling (elke vermelding is afgeschermd op de eigen capability, dus je ziet alleen wat je mag openen). Vanuit de frontend toont het scherm **Rollen & rechten** ze ook onder "Geavanceerde autorisatietools" voor snelle toegang.

**De matrix-editor is geen wp-admin-link.** Die heeft een eigen frontend-scherm onder **Configuratie → Authorisatiematrix** (`?tt_view=matrix`), afgeschermd op de capability `tt_manage_authorization` — toegekend aan administrator en Clubbeheerder — in plaats van op het hebben van een WordPress-administratoraccount. Een academie zonder iemand in wp-admin kan nu zelf een te ruime of te krappe toekenning corrigeren, en dat telt: die toekenningen bepalen wie de evaluaties, notities en medische velden van een speler mag openen.

Een Clubbeheerder die vanaf de frontend werkt, kan de eigen persona-rij niet wijzigen, en ook niet de entiteiten die het rechtenmodel, het databaseschema of de back-ups bepalen; die cellen staan op slot en blijven voorbehouden aan een administrator. De wp-admin-pagina is ongewijzigd en blijft de weg terug als een matrixwijziging de frontend verbergt. In `docs/nl_NL/authorization-matrix.md` staat de volledige tabel met wie wat mag.

## Wat nog wp-admin nodig heeft, en waarom

Een academie draaien zou geen WordPress-account moeten vereisen. Vrijwel alles wat een academiebeheerder doet — spelers, teams, rechten, seizoenen, modules, evaluatiewegingen, de methodiek-woordenlijst, de personadashboards — heeft een frontend-scherm, en elke gang naar wp-admin is één misklik verwijderd van de plugin-, gebruikers- en instellingenschermen die het rechtenmodel niet beschrijft.

Twaalf pagina's blijven daar bewust staan. Kom je er terecht, dan vertelt de pagina zelf waarom. De redenen komen in vier soorten, en de eerste is de dragende.

**Herstel — het moet werken als de app dat niet doet.** De rechtenmatrix, het scherm met database-updates en het foutenlogboek hebben allemaal een frontend-equivalent, en de wp-admin-kopieën blijven toch bestaan. Ze zijn de weg terug als een rechtenwijziging iedereen uit de app sluit, of als een mislukte update de app niet meer laat laden. Dat is precies het moment waarop je ze nodig hebt, en precies het moment waarop de frontend je niet kan helpen. Een dubbeling weghalen oogt netjes tot de dag dat het ertoe doet.

**Diagnostiek — een kapot systeem vragen zijn eigen storing te beschrijven.** Permission Chain Debug, Roles Debug, Gebruikers vergelijken en Matrixvoorbeeld beantwoorden allemaal de vraag "waarom ziet deze persoon het verkeerde?". Zet je ze ín de app die je onderzoekt, dan hangt hun antwoord af van datgene wat je aan het onderzoeken bent.

**Inrichting en support.** De demodata-tools, de zaadcontrole en het welkomstscherm zijn eenmalige klussen tijdens het inrichten, gedaan door iemand die al operator is. Ook impersonatie hoort hier thuis, met opzet: de app bekijken als iemand anders is een supporthandeling, en die buiten de app houden maakt zichtbaar wanneer hij gebruikt wordt.

**Ontwikkelaarsinstrumentatie.** Het volledigheidsrapport per module is ontwikkelgereedschap en geen academiewerk.

De lijst zelf staat in `config/admin_only_surfaces.php`, met per pagina één regel uitleg in operatorstaal — dezelfde zin die de pagina je laat zien. Een pagina daaraan toevoegen is een besluit, geen formaliteit: de vraag is nooit "is dit lastig om over te zetten?" maar "wordt het product er slechter van, of wordt herstel onmogelijk als de frontend stuk is?"

Staat een pagina **niet** op die lijst en is hij ook niet vanuit de app te bereiken, dan is dat een gat en geen besluit. `wp tt admin-routes --unrouted` haalt ze op uit een draaiende installatie.

## Een rol-toewijzing intrekken

Via **Toegangsbeheer → Rollen** (of het bewerkpaneel per persoon) heeft elke toegekende rol een **Intrekken**-actie.

Een klik op Intrekken opent een bevestigingsvenster binnen de app (niet de standaard browserprompt) — bevestig met de rode **Intrekken**-knop, annuleer via **Annuleren**, een klik op de achtergrond of de Escape-toets. Na bevestiging wordt de toewijzing verwijderd en kom je terug op dezelfde pagina met een succesmelding.

Hetzelfde bevestigingspatroon wordt overal gebruikt waar een destructieve actie om je akkoord vraagt (een doel verwijderen vanaf het dashboard, een evaluatiecategorie verwijderen, enz.).

## De personawissel verandert wat je ziet, niet wat je mag

Iemand kan meer dan één persona tegelijk hebben. Een trainer met een eigen kind in de academie is het alledaagse geval: die is staflid én ouder, allebei echt, op hetzelfde moment. Met de personawissel op het dashboard kiest zo iemand in welke van die rollen de interface zich kleedt — welke startpagina, welke tegels, welk label op de gebruikerschip.

**Je rechten veranderen er niet door.** Autorisatie kijkt altijd naar élke persona die iemand heeft, en één die toegang geeft is genoeg. Een trainer die de pagina van zijn eigen kind als ouder bekijkt, houdt zijn trainerstoegang tot de rest van de academie; wie terugwisselt, krijgt niets extra's wat hij niet al had.

Dat is belangrijk, want het alternatief faalt geruisloos. Een wissel die ook rechten zou intrekken, haalt de toegang van een trainer op élk scherm weg, houdt die weg over sessies en apparaten heen — de keuze staat immers op het account — en zegt nooit waarom. De trainer merkt alleen dat notities van vorige week verdwenen zijn.

Wil je echt in een andere rol handelen — zien wat een ouder ziet, mét de rechten van een ouder — gebruik dan **Imitatie** (`tt_impersonate_users`) of de **Voorbeeld**-pagina van de matrix. Allebei zijn ze een bewuste keuze, allebei blijven ze zichtbaar zolang ze aanstaan, en allebei stoppen ze wanneer jij ze stopt.

## Speler-gestuurde ouderzichtbaarheid

Een speler kan afzonderlijke ontwikkelonderdelen (evaluaties, doelen, reis, metingen, POP) verbergen voor een **gekoppelde ouder**. De poort is `AuthorizationService::parentCanViewSection( $user_id, $player_id, $section )`, bovenop `canViewPlayer()`: hij beperkt alleen een gekoppelde ouder - de speler zelf en staf (team/globaal) komen er altijd langs, en een niet-afschermbaar onderdeel is altijd zichtbaar. Standaard zichtbaar: het ontbreken van een voorkeursrij in `tt_player_parent_visibility` betekent dat het onderdeel gedeeld is, dus bestaande ouders houden hun toegang zonder migratie. Veiligheids-/medische velden vallen onder hun eigen caps en zijn niet door de speler te sturen. Zowel de gerenderde weergaven als de REST-reads van de onderdelen raadplegen de poort.

## Ouder → kind-koppelmodel

De pivot `tt_player_parents` (`parent_user_id`, `player_id`, `is_primary`, `club_id`) is het **enige gezaghebbende** antwoord op de vraag "welke kinderen heeft deze ouder". `ParentChildResolver` leest deze pivot — afgebakend per club, `status = 'active'`, gesorteerd op meest recente koppeling eerst — en elke afnemer (de kindwisselaar op het dashboard, de me-view-autorisatie, de deelnemersgraaf van doel-threads, de ouder-KPI) roept hem aan, zodat ze het allemaal eens zijn over wie ouder van wie is.

`tt_players.guardian_email` is **geen** live koppelbron. Het is een uitnodigings-/seed-hint: het mag een rij in `tt_player_parents` *aanmaken* wanneer een ouder wordt uitgenodigd, geïmporteerd of geseed, maar wordt nooit tijdens runtime bevraagd om toegang te bepalen. Een ouder die alleen via een overeenkomende `guardian_email` is gekoppeld (en zonder pivotrij) verschijnt pas wanneer hij opnieuw wordt gekoppeld via de uitnodigings-/seed-route of door een beheerder — er is geen migratie.

**Afscheid beëindigt de toegang van de verzorger.** De resolver filtert op `status = 'active'`, dus zodra een speler vertrekt, doorstroomt of anderszins van de actieve selectie af gaat, houden de gekoppelde personen op verzorger te zijn voor toegangsdoeleinden: hun dashboard, de kindwisselaar, de ontwikkelpagina's van het kind, de rechtenmatrix, de print van het ontwikkelplan en de gespreks-endpoints gaan tegelijk dicht. Dat was eerder inconsistent — zes plekken stelden de vraag "is dit een verzorger van deze speler" met hun eigen query, en de plekken zonder statusfilter lieten het dossier van een vertrokken kind via een directe URL bereikbaar terwijl het dashboard niets toonde. `ParentChildResolver::isParentOf()` is nu de enige implementatie, en die is club-scoped.

Een gezin dat het dossier ná het afscheid nodig heeft, hoort een **inzageverzoek-export** te krijgen — een bewuste handeling met een spoor van wie erom vroeg — in plaats van een login die stilletjes blijft werken.

## Ouderdashboard en kindgerichte me-views (#1991 / #1992)

Een verzorger die aan een speler gekoppeld is maar zelf geen spelerrecord heeft, bereikt nu het dossier van **zijn of haar kind**:

- **Startdashboard** — het oude tegelraster toont een ouderspecifiek, kindgericht scherm voor een ouder: de naam en foto van het kind verankeren het scherm, alleen een samengestelde subset van tegels (ontwikkeling, spelerskaart, evaluaties, activiteiten, ontwikkelplan) is zichtbaar, elke tegel draagt de `?player_id=N` van het kind, en de kolom "Werk van vandaag" is verborgen (het scherm is het dossier van het kind, geen takenlijst). Een **kindwisselaar** verschijnt wanneer de ouder aan meer dan één kind is gekoppeld.
- **Me-views** — het openen van `?tt_view=my-development` (en de andere `my-*`-slugs) leidt het onderwerp af uit het gekoppelde kind van de ouder via `ParentChildResolver`. Ouders met één kind worden automatisch herleid; ouders met meerdere kinderen zien eerst een kindkiezer (het meest recente kind is daarna de standaard). De dispatch-poort autoriseert het **herleide doel** via `AuthorizationService::canViewPlayer( $user_id, $target_id )` — niet "is de bezoeker een speler" — zodat een ouder voor zijn eigen kind slaagt via de ouder-scope, en een gebruiker zonder eigen speler en zonder gekoppeld kind nog steeds geweigerd wordt. Dezelfde `canViewPlayer`-autoriteit ligt onder `GET /players/{id}` (REST-pariteit).

Het persona-dashboard (`persona_dashboard.enabled`) levert een parallelle, rijkere ouderervaring; wanneer het op een installatie is uitgeschakeld, is de ouderbewustheid van het oude raster hierboven wat een ouder ziet.

## Operator-handleidingen voor beveiliging en privacy

Twee cap-en-matrix-aanpalende operator-handleidingen zijn in v3.97.2 (#0086 Workstream A) gepubliceerd:

- [Beveiliging — handleiding voor de academy admin](security-operator-guide.md) — de dag-één- + jaarlijkse-checklist voor de Academy Admin: administrator-accounts inperken, MFA-aanbevelingen, audit-log doornemen, vermoede inbraak afhandelen, toekomstige `require_mfa_for_personas`-handhaving.
- [Privacy — handleiding voor de academy admin](privacy-operator-guide.md) — de AVG-georiënteerde how-to: inzage-verzoeken, recht-op-vergetelheid-verzoeken (handmatig tot de formele wis-pijplijn er is), retentie-vensters per datacategorie, de privacy-levenscyclus van een speler die toetreedt en vertrekt.

De publieke trust-artefacten (security-pagina, privacybeleid, DPA-template) staan op `mediamaniacs.nl/talenttrack/security` en `mediamaniacs.nl/talenttrack/privacy`; de bron staat ter bewerking in `marketing/security/`.
