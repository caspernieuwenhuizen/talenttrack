<!-- audience: admin -->

# Toegangsbeheer

TalentTrack gebruikt het rechten-systeem van WordPress, plus een eigen overlay van "functionele rollen", om te bepalen wie wat mag. De release v3.0.0 refactorde rechten naar granulaire view/edit-paren, waardoor alleen-lezen rollen nu echt over de hele plug-in heen werken.

## De rechten (v3.0.0+)

Elk groot onderdeel heeft een **view**-recht en, voor schrijfbare onderdelen, een bijbehorend **edit**-recht:

| Onderdeel    | View-recht            | Edit-recht            |
|--------------|-----------------------|-----------------------|
| Teams        | `tt_view_teams`       | `tt_edit_teams`       |
| Spelers      | `tt_view_players`     | `tt_edit_players`     |
| Personen     | `tt_view_people`      | `tt_edit_people`      |
| Evaluaties   | `tt_view_evaluations` | `tt_edit_evaluations` |
| Sessies      | `tt_view_activities`    | `tt_edit_activities`    |
| Doelen       | `tt_view_goals`       | `tt_edit_goals`       |
| Instellingen | `tt_view_settings`    | `tt_edit_settings`    |
| Rapporten    | `tt_view_reports`     | *(geen edit-tegenhanger)* |

Elke TalentTrack-gebruiker heeft ook het basisrecht `read` van WordPress nodig om te kunnen inloggen.

## Legacy-rechten

De rechten van vóór v3 bestaan nog steeds en werken nog steeds:

- `tt_manage_players` — impliciet toegekend als een gebruiker zowel `tt_view_players` ALS `tt_edit_players` heeft
- `tt_evaluate_players` — impliciet toegekend met zowel `tt_view_evaluations` ALS `tt_edit_evaluations`
- `tt_manage_settings` — impliciet toegekend met zowel `tt_view_settings` ALS `tt_edit_settings`
- `tt_view_reports` — onveranderd

Daardoor blijft externe code of andere plug-ins die op legacy-rechtennamen controleren zonder aanpassing werken. Louter-lezen gebruikers (de rol Waarnemer) falen terecht op legacy `manage`-checks omdat hun edit-tegenhanger ontbreekt.

## De vooraf geconfigureerde rollen

| Rol                          | View                  | Edit                                                   |
|------------------------------|-----------------------|--------------------------------------------------------|
| **Hoofd opleiding**          | Alle onderdelen       | Alle onderdelen (incl. Evaluaties, Instellingen)       |
| **Clubbeheerder**            | Alle onderdelen       | Teams, Spelers, Personen, Sessies, Doelen, Instellingen|
| **Coach**                    | Alles behalve Instellingen | Evaluaties, Sessies, Doelen                       |
| **Scout**                    | Teams, Spelers, Evals | Evaluaties                                             |
| **Staf**                     | Teams, Spelers, Personen | Spelers, Personen                                   |
| **Speler**                   | Alleen eigen data     | Alleen eigen profiel                                   |
| **Ouder**                    | Alleen data van kind  | *(geen)*                                               |
| **Alleen-lezen Waarnemer**   | **Alle onderdelen**   | **Geen**                                               |

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

## Functionele rollen

Functionele rollen zijn clubrollen uit de praktijk (Hoofdcoach, Assistent-coach, Fysio) die automatisch WordPress-rollen kunnen toekennen. Stel koppelingen in via **Toegangsbeheer → Functionele rollen**.

Voorbeeld: je functionele rol "Hoofdcoach" kan gebruikers automatisch de WordPress-rol `tt_coach` toekennen. Dan krijgen ze evaluatierechten automatisch zodra je een persoon aan een team toevoegt als "Hoofdcoach".

Het toewijzen van een persoon via Functionele rollen schrijft ook een rij in `tt_user_role_scopes` (scope_type=`team`, scope_id=het team) zodat de matrix-team-scopecontrole voor die persoon op dat team waar wordt. Bij het verwijderen van de laatste toewijzing voor een (persoon, team)-paar wordt ook de scope-rij verwijderd. Personen met meerdere rollen op hetzelfde team houden één scope-rij totdat de laatste rol wordt ingetrokken. De backfill-migratie `0062_fr_assignment_scope_backfill.php` heeft installaties van vóór deze koppeling rechtgetrokken (#0079).

## Tegelzichtbaarheid via aparte entiteiten (#0079)

Dashboardtegels die uitkomen op een coach- of beheerderssurface declareren een tegelspecifieke matrixentiteit (`team_roster_panel`, `coach_player_list_panel`, `evaluations_panel`, `activities_panel`, `goals_panel`, `podium_panel`, `team_chemistry_panel`, `pdp_panel`, `people_directory_panel`, `wp_admin_portal`) los van de onderliggende data-entiteit (`team`, `players`, `evaluations`, …). De data-entiteiten blijven REST + repository-reads sturen — de dispatcher en de tegel-gate vragen het `*_panel`-entiteit aan, zodat het verlenen van "scout leest teamdata globaal" niet langer een coach-tegel **Mijn teams** op het scoutdashboard plaatst. De dispatcher (`DashboardShortcode`) leest de entiteit uit het tegelregister en raadpleegt `MatrixGate::canAnyScope` voor hetzelfde antwoord als de tegel-gate, zodat de eerdere situatie waarin een tegel rendert maar de bestemming alsnog *"Dit onderdeel is alleen beschikbaar voor coaches en beheerders."* meldt, definitief weg is.

## Entiteiten van de instroompijplijn (#0081)

De recruitmenttrechter introduceert twee nieuwe matrixentiteiten, met een opzettelijk smal toegangsbereik omdat prospect-gegevens de gevoeligste PII in het systeem zijn (verzameld voordat er een contractuele relatie bestaat — wettelijke grondslag is toestemming):

- **`prospects`** — Hoofdcoach leest op teamniveau (de eigen leeftijdscategorie). Scout heeft RCD op *self*-niveau — een scout kan letterlijk geen prospects van een andere scout zien via welk codepad dan ook (afgedwongen op SQL-niveau in `ProspectsRepository`). Hoofd Opleiding en Academy Admin hebben RCD globaal.
- **`test_trainings`** — zelfde toegangsbereik, behalve dat de Scout deze globaal mag lezen (zodat een scout de geplande sessie kan zien waarvoor zijn prospect is uitgenodigd).

Een dagelijkse retentie-cron ruimt vastgelopen of definitief afgewezen prospects automatisch op, conform `wp_options.tt_prospect_retention_days_no_progress` (standaard 90) / `tt_prospect_retention_days_terminal` (standaard 30). Doorgestroomde prospects (`promoted_to_player_id IS NOT NULL`) blijven beschermd — bij doorstroming worden de prospect-gegevens onderdeel van de PII van een academy-speler en blijft de rij staan in het `PlayerDataMap`-erasure-manifest, gekoppeld aan de identiteit van de speler.

## Prullenbakbeheer — `tt_manage_recycle_bin` (#2020)

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

## Permission debug

Via **Toegangsbeheer → Permission Debug** kun je de effectieve rechten van een willekeurige gebruiker inspecteren. Handig als een gebruiker meldt "ik kan X niet zien" — controleer wat hij/zij daadwerkelijk heeft.

## De Toegangsbeheer-tools vinden

De geavanceerde autorisatiepagina's — Autorisatiematrix, Toegangsbeheer activeren, Gebruikers vergelijken, Permission Debug, Permission Chain Debug — staan onder de kop **Toegangsbeheer** in de TalentTrack-zijbalk in wp-admin. Ze verschijnen daar in zowel de oude als de moderne menu-indeling (elke vermelding is afgeschermd op de eigen capability, dus je ziet alleen wat je mag openen). Vanuit de frontend toont het scherm **Rollen & rechten** ze ook onder "Geavanceerde autorisatietools" voor snelle toegang.

## Een rol-toewijzing intrekken

Via **Toegangsbeheer → Rollen** (of het bewerkpaneel per persoon) heeft elke toegekende rol een **Intrekken**-actie.

Een klik op Intrekken opent een bevestigingsvenster binnen de app (niet de standaard browserprompt) — bevestig met de rode **Intrekken**-knop, annuleer via **Annuleren**, een klik op de achtergrond of de Escape-toets. Na bevestiging wordt de toewijzing verwijderd en kom je terug op dezelfde pagina met een succesmelding.

Hetzelfde bevestigingspatroon wordt overal gebruikt waar een destructieve actie om je akkoord vraagt (een doel verwijderen vanaf het dashboard, een evaluatiecategorie verwijderen, enz.).

## Speler-gestuurde ouderzichtbaarheid (#1867)

Een speler kan afzonderlijke ontwikkelonderdelen (evaluaties, doelen, reis, metingen, POP) verbergen voor een **gekoppelde ouder**. De poort is `AuthorizationService::parentCanViewSection( $user_id, $player_id, $section )`, bovenop `canViewPlayer()`: hij beperkt alleen een gekoppelde ouder - de speler zelf en staf (team/globaal) komen er altijd langs, en een niet-afschermbaar onderdeel is altijd zichtbaar. Standaard zichtbaar: het ontbreken van een voorkeursrij in `tt_player_parent_visibility` betekent dat het onderdeel gedeeld is, dus bestaande ouders houden hun toegang zonder migratie. Veiligheids-/medische velden vallen onder hun eigen caps en zijn niet door de speler te sturen. Zowel de gerenderde weergaven als de REST-reads van de onderdelen raadplegen de poort.

## Operator-handleidingen voor beveiliging en privacy

Twee cap-en-matrix-aanpalende operator-handleidingen zijn in v3.97.2 (#0086 Workstream A) gepubliceerd:

- [Beveiliging — handleiding voor de academy admin](?page=tt-docs&topic=security-operator-guide) — de dag-één- + jaarlijkse-checklist voor de Academy Admin: administrator-accounts inperken, MFA-aanbevelingen, audit-log doornemen, vermoede inbraak afhandelen, toekomstige `require_mfa_for_personas`-handhaving.
- [Privacy — handleiding voor de academy admin](?page=tt-docs&topic=privacy-operator-guide) — de AVG-georiënteerde how-to: inzage-verzoeken, recht-op-vergetelheid-verzoeken (handmatig tot de formele wis-pijplijn er is), retentie-vensters per datacategorie, de privacy-levenscyclus van een speler die toetreedt en vertrekt.

De publieke trust-artefacten (security-pagina, privacybeleid, DPA-template) staan op `talenttrack.app/security` en `talenttrack.app/privacy`; de bron staat ter bewerking in `marketing/security/`.
