---
title: Authorisatie­matrix
group: frontend
summary: Persona × entiteit × activiteit × scope-raster — wat elke persona mag, met shadow-modus preview vóór toepassen.
audience: [admin]
order: 60
---

# Authorisatie­matrix (beheerdersgids)

**Configuratie → Authorisatie­matrix** (`?tt_view=matrix`), of **TalentTrack → Toegangsbeheer → Authorisatie­matrix** in wp-admin.

De authorisatie­matrix is de centrale bron voor "wat mag elke persona, op welke entiteit?". Acht persona's × ~30 entiteiten × drie acties (lezen / wijzigen / aanmaken-verwijderen) = enkele honderden cellen. De meegeleverde standaardwaarden komen overeen met wat elke rol vandaag al doet; beheerders kunnen per cel afwijken zonder code te schrijven.

## Wie mag hem bewerken, en wat niet

Er zijn twee schermen voor hetzelfde raster, met dezelfde schrijver eronder:

| | Frontend (`?tt_view=matrix`) | wp-admin (`admin.php?page=tt-matrix`) |
| - | - | - |
| Wie | Iedereen met `tt_manage_authorization` — toegekend aan **administrator** en **Clubbeheerder** | Alleen een WordPress-**administrator** |
| Gewone cellen bewerken | Ja | Ja |
| Beschermde cellen bewerken | Alleen administrator | Ja |
| Terugzetten naar standaard | Nee | Ja |
| Seed exporteren / importeren | Nee | Ja |
| De matrix aan- of uitzetten | Nee | Ja |

**De beschermde cellen.** Een clubbeheerder die zijn eigen persona `create_delete` op `authorization_matrix` zou kunnen geven, heeft zichzelf één opslagactie later alles gegeven. Daarom staan deze cellen voor iemand zonder administrator-account op slot, met de reden op de cel:

- de eigen persona-rij — `academy_admin`;
- de entiteiten die het rechtenmodel, het databaseschema en de back-ups bepalen: `authorization_matrix`, `authorization_changelog`, `settings`, `migrations`, `backup`, `module_management`, `feature_toggles`, `functional_role_definitions`.

Het slot wordt afgedwongen bij het opslaan, niet alleen in de HTML: een zelfgemaakte formulierpost of een directe REST-aanroep op een beschermde cel wordt geteld als geweigerd en schrijft géén matrixregel en géén changelog-regel.

**Waarom wp-admin blijft.** Een verkeerde matrixwijziging kan precies de frontend-schermen verbergen die naar de matrix leiden. De wp-admin-pagina hangt daar niet van af en is daarmee de weg terug — en dat is ook de reden dat terugzetten, de seed-export/import en de aan/uit-schakelaar niet zijn meegedelegeerd.

## Wanneer aanpassen

Drie echte redenen om de matrix aan te raken:

1. **Een nieuwe persona komt in de club.** Je introduceert een "Director of Football" naast het Hoofd Opleidingen; de meegeleverde matrix kent die persona niet. Voeg de persona toe aan het seed-bestand (of wacht op het persona-beheer-UI in v2 van de matrix-epic).
2. **De standaard-scope klopt niet voor jouw club.** Misschien mogen Hoofdcoaches in jouw setup geen activiteiten verwijderen. Schakel de `D`-pil uit voor `head_coach × activities`.
3. **Compliance.** Een bestuursbesluit eist dat scouts geen evaluaties mogen lezen van spelers buiten hun toegewezen scoutgebied. Wijzig de scope van `global` naar `team` voor `scout × evaluations × read`.

Voor al het andere — laat het met rust. De matrix bewerken is scherp; een beheerder die per ongeluk een scope op de verkeerde cel aanscherpt, sluit echte gebruikers buiten echte schermen.

## Wat de cellen betekenen

Elke cel op het raster is `(persona, entiteit, actie, scope)`:

- **R** — lezen. Bekijken / lijst / detailweergave.
- **C** — wijzigen. Bestaande regels aanpassen.
- **D** — aanmaken / verwijderen. Nieuwe regels toevoegen + bestaande verwijderen. Eén werkwoord omdat de impact vergelijkbaar is.
- **Scope** — `global` (overal), `team` (alleen teams waaraan de gebruiker is toegewezen), `player` (alleen het eigen profiel / het kind / de toegewezen proefspeler), `self` (alleen het eigen gebruikersaccount).

## Het raster lezen

Het raster is breed — tien persona's naast elkaar, één rij per entiteit — en schuift zijwaarts binnen zijn eigen kader. **De pagina niet.** Verticaal is er één schuifbalk, die van de pagina zelf: het raster heeft geen eigen venster meer binnen de pagina, dus de rechten van één persona van boven naar beneden lezen is één doorlopende beweging in plaats van twee.

Twee dingen houden je georiënteerd:

- **De entiteitkolom blijft staan** terwijl de personakolommen eronderdoor schuiven, zodat bij een rij R / C / D altijd de naam staat waar die bij hoort.
- **Elke categoriebalk herhaalt de personanamen**, zodat de kolom waar je naar kijkt binnen een balk te herkennen is en niet alleen helemaal boven aan de tabel.

**Bereik staat op een eigen regel.** Elke entiteitrij heeft een kleine knop **Bereik**; die opent eronder een rij met per persona één keuzelijst voor het bereik. Het blijft een bereik per persona per entiteit — een trainer kan spelers op teamniveau lezen terwijl een scout ze wereldwijd leest — het maakt alleen niet langer elke entiteit twee rijen hoog of je er nu naar kijkt of niet. Zonder JavaScript staat de bereikrij vanaf het begin open, zodat die altijd bereikbaar is.

De matrix is bewust een bureaubladscherm: een bezoeker op een telefoon krijgt dat te horen, met de reden erbij, in plaats van een raster waarvan de rijen en kolommen *de inhoud zijn* in één kolom geperst.

## Standaard versus beheerder-bewerkt

- Cellen uit het meegeleverde seed-bestand zijn **gedimd** weergegeven.
- Cellen die jij hebt gewijzigd zijn **vet**.
- De knop "Reset to defaults" leegt `tt_authorization_matrix` en herseed't vanuit `config/authorization_seed.php`. Elke handmatige aanpassing gaat verloren. Wel geregistreerd in de changelog.

## De changelog

Elke bewerking (toekenning, intrekking, scope-wijziging, reset) schrijft een rij naar `tt_authorization_changelog`:

| Veld | Betekenis |
| - | - |
| `persona, entity, activity, scope_kind` | De cel die wijzigde |
| `change_type` | `grant` / `revoke` / `scope_change` / `reset` |
| `before_value` / `after_value` | Boolean voor/na |
| `actor_user_id` | Wie op opslaan klikte |
| `note` | "scope: team → global" voor scope_change-rijen |

De changelog wordt weergegeven binnen de Matrix-pagina. Hij maakt geen deel uit van het gezamenlijke auditlog.

## Wijzigingen toepassen

Nieuwe installaties starten met de matrix **al actief** — een gloednieuwe academy boot met matrix-gedreven autorisatie aan, omdat de geseede matrix al elke persona dekt. (Dit gebeurt eenmalig en alleen bij een verse installatie; het bijwerken van een bestaande site zet dit nooit om.) Op een bestaande site blijft de matrix slapend totdat een beheerder die bewust activeert, zoals hieronder. Wil je dit op een nieuwe installatie uitzetten, open dan **TalentTrack → Toegangsbeheer → Toegangsbeheer activeren** en klik op **Rollback**, of zet `tt_authorization_active` op `0` in `tt_config`.

Cellen bewerken is **shadow-modus** totdat je op **Apply** klikt op de pagina Toegangsbeheer activeren (TalentTrack → Toegangsbeheer → Toegangsbeheer activeren).

Tijdens shadow-modus:

- De `tt_authorization_matrix`-tabel reflecteert je bewerkingen.
- De legacy `current_user_can( 'tt_view_evaluations' )`-aanroepen beslissen nog steeds wie wat mag.
- Niets breekt voor echte gebruikers; je kunt zorgeloos bewerken.

Wanneer je op **Apply** klikt:

- Een vlag (`tt_authorization_active`) gaat naar `1`.
- De `user_has_cap`-filter routeert elke legacy `tt_*`-cap-check via de matrix.
- Echte gebruikers zien de nieuwe rechten bij hun volgende verzoek.

Klik **Rollback** om de vlag weer naar `0` te zetten — matrixdata blijft bewaard, alleen de routing wijzigt. Rollback is één klik; matrix-gedreven autorisatie is een bewust omkeerbare beslissing.

## Het toegangsbeheer-voorbeeld

Voordat je op Apply klikt, toont de pagina Toegangsbeheer activeren:

- Per gebruiker **Gained** caps (de matrix verleent iets dat de oude caps niet deden).
- Per gebruiker **Revoked** caps (de matrix weigert iets dat de oude caps wel verleenden) — de gevaarlijke kolom.
- Een CSV-download voor offline-analyse.

Lege Gained + lege Revoked = de matrix komt overeen met de legacy-caps voor die gebruiker. De meeste gebruikers in een verse installatie beginnen zo; de matrix bestaat primair als substraat voor verandering, niet als gedragsverschuiving.

## Persona's in v1

Acht persona's worden meegeleverd in de seed:

- `player` — een speler die eigen data bekijkt (self-scope op de meeste reads).
- `parent` — een ouder van een speler (scope tot het kind via `tt_player_parents`).
- `assistant_coach` — een `tt_coach` WP-gebruiker met `tt_team_people.is_head_coach = 0` voor minstens één team.
- `head_coach` — een `tt_coach` WP-gebruiker met `tt_team_people.is_head_coach = 1` voor minstens één team. Een coach kan beide persona's tegelijk hebben als hij/zij hoofdcoach is van het ene team en assistent van een ander. De hoofdcoach heeft `players [rc, team]` — hij/zij kan een spelersrecord van een eigen team corrigeren (positie, rugnummer, voorkeursvoet) zonder tussenkomst van een beheerder. `create_delete` wordt bewust niet gegeven: een speler toevoegen of verwijderen is een registratiehandeling met gevolgen voor selectiegrootte, facturatie en sociale veiligheid, en blijft bij `academy_admin`. `assistant_coach` houdt `players [r, team]`, en omdat beide persona's dezelfde `tt_coach`-WP-rol delen, is de matrix de enige laag die ze uit elkaar houdt — daarom staat deze toekenning daar en niet op de rol.
- `head_of_development` — `tt_head_dev` WP-rol; overziet de hele academie.
- `scout` — `tt_scout` WP-rol; leest spelers over teams heen. Sinds v4.20.103 zijn evaluatie-reads beperkt tot toegewezen spelers, en POP-dossiers/-oordelen worden helemaal niet toegekend — selectiebeslissingen zijn geen scouting-input.
- `team_manager` — nieuw in #0033 Sprint 7; `tt_team_manager` WP-rol. Logistiek voor een team (activiteiten, aanwezigheid, uitnodigingen) zonder coachingautoriteit.
- `academy_admin` — `administrator` of `tt_club_admin` WP-rol.

Een gebruiker kan meerdere persona's tegelijk vasthouden (een ouder die ook hoofdcoach is). De matrix gebruikt de **unie** standaard — elke persona die toestemming verleent wint. De persona-switcher in het gebruikersmenu laat multi-persona-gebruikers het dashboard tijdelijk filteren naar de visie van één persona; dat is een UI-lens, geen autorisatiebeperking.

## Toernooien — alleen-beheerder in v1 (#0093, #1943)

De toernooiplanner levert twee capabilities mee — `tt_view_tournaments` en `tt_edit_tournaments`. In v1 houden alleen `administrator` + `tt_club_admin` (de Academy Admin-persona) ze vast. Geen enkele andere persona (Coach, HoD, Scout, Speler, Ouder) ziet de functie tot de persona-uitbreiding-vervolglevering.

De functie heeft een matrix-entiteit: `tournaments`. De seed verleent **alleen academy_admin `rcd[global]`** — dit reproduceert het alleen-beheerder-ontwerp van v1 (WP-administrators passeren via de matrix-administrator-uitzondering). Geen enkele andere persona heeft een rij. `LegacyCapMapper` overbrugt de ruwe capabilities zodat de bestaande `current_user_can( 'tt_view_tournaments' / 'tt_edit_tournaments' )`-controlepunten via de matrix worden opgelost zodra die actief is:

| Ruwe capability | Matrix-tuple |
| - | - |
| `tt_view_tournaments` | `tournaments` / `read` |
| `tt_edit_tournaments` | `tournaments` / `change` |

`tt_edit_tournaments` dekte historisch bewerken **én** aanmaken **én** verwijderen (er is geen aparte beheer-capability), dus de seed-toekenning is volledig `rcd` — het overbruggen van bewerken naar `change` behoudt de aanmaak/verwijder-dekking omdat de enige begunstigde alle drie de handelingen heeft. De ruwe capability-houders (administrator + `tt_club_admin`) komen netjes overeen met de seed-begunstigde, dus routering via de matrix is **toegangsbehoudend** — geen enkele persona wint of verliest toegang. Migratie `0179_authorization_seed_topup_tournaments` vult de entiteit op bestaande installaties bij in `tt_authorization_matrix` (idempotente `INSERT IGNORE`).

## Matrix-entiteit `exercises` — de oefeningenbibliotheek

De oefeningen-/drilbibliotheek (`tt_exercises`, bediend door `ExercisesRestController` op `/wp-json/talenttrack/v1/exercises`) is clubbreed: een drill die een coach schrijft, is herbruikbaar voor de hele academie. De bibliotheek staat **los van `activities`**, de teamgebonden sessiekalender — daarom krijgt zij een eigen matrix-entiteit, `exercises`, in plaats van de activiteiten-scope te lenen.

Vóór #1944 was de schrijf-capability `tt_manage_exercises` niet gekoppeld, zodat de REST-schrijfpaden zodra de matrix actief is voor iedereen op `false` zouden uitkomen. #1944 voegt de entiteit + seed en de `LegacyCapMapper`-brug toe:

| Ruwe capability | Matrix-tupel |
| - | - |
| `tt_manage_exercises` | `exercises` / `create_delete` |

De leespaden blijven gegate op `tt_view_activities` (coaches zien de bibliotheek bij het plannen van sessies), wat al gekoppeld is. De schrijf-capability wordt als `rcd[global]` geseed aan **head_coach + assistant_coach + head_of_development + academy_admin**.

Beide coach-persona's worden bewust geseed. De ruwe `tt_manage_exercises`-capability is in handen van `administrator` (matrix-uitzondering) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — en `tt_coach` is de WordPress-rol achter **zowel** de head_coach- **als** de assistant_coach-persona. Alleen head_coach seeden zou stilzwijgend de schrijftoegang van assistent-coaches intrekken (de versmalling in de stijl van #1060). Beide worden geseed, dus routering via de matrix is **toegangsbehoudend** — elke ruwe capability-houder, inclusief assistent-coaches, behoudt schrijftoegang tot de bibliotheek. De scope is `global` omdat de bibliotheek clubbreed is en vandaag geen teamafbakening kent.

Migratie `0180_authorization_seed_topup_exercises` vult de entiteit op bestaande installaties bij in `tt_authorization_matrix` (idempotente `INSERT IGNORE`, die alleen over de nieuwe `exercises`-rijen loopt).

## Matrix-entiteit `media` — foto's en video

De mediabibliotheek (`tt_media` + `tt_media_links`, epic #2589) krijgt een eigen entiteit. Zij valt bewust niet onder `players`: een foto van een kind is een andere gevoeligheid dan het spelersdossier, en een academie moet het één kunnen toekennen zonder het ander.

Drie caps sluiten erop aan, in plaats van het gebruikelijke view/edit-paar. Uploaden is een *create*, en het matrixvocabulaire brengt create onder `create_delete` onder, dus een uploadcontrole heeft een cap nodig die bij dat werkwoord uitkomt:

| Ruwe cap | Matrixtupel |
| - | - |
| `tt_view_media` | `media` / `read` |
| `tt_edit_media` | `media` / `change` |
| `tt_manage_media` | `media` / `create_delete` |

Geseede rechten:

| Persona | Handelingen | Scope |
| - | - | - |
| player | r | self |
| parent | r | player |
| scout | r | player |
| team_manager | r | team |
| assistant_coach | rcd | team |
| head_coach | rcd | team |
| head_of_development | rcd | global |
| academy_admin | rcd | global |

Drie daarvan zijn keuzes en geen vanzelfsprekendheden:

**De scout leest op `player`-scope, niet globaal.** Dat volgt de aanscherping van `evaluations` voor dezelfde persona in #1378. Een foto van een kind is minstens zo gevoelig als een geschreven oordeel erover, en academiebrede leestoegang was vóór #1378 het breedste recht op gevoelige gegevens in de matrix.

Let op het praktische gevolg, dat `evaluations` overigens al kent: `MatrixGate::userHasScope()` kan `player`-scope alleen oplossen voor de speler zelf en voor diens ouder. Er bestaat geen koppeling scout → speler totdat #0017 landt, dus **komt het mediarecht van een scout vandaag nergens op uit** — een scout ziet in de praktijk geen media. Dat is de veilige kant van dat gat, en de rij wordt nu al geseed zodat scouts precies het bedoelde recht krijgen zodra #0017 de koppeling levert, in plaats van dat er dan een matrixwijziging nodig is.

**Trainers hebben `create_delete` omdat create en delete één werkwoord zijn.** Een trainer die niet kan aanmaken, kan niet uploaden — en dan is de functie onbruikbaar voor precies de mensen voor wie zij bestaat. Het gevolg, dat hetzelfde recht ook verwijderen toestaat, is de juiste afweging: wie per ongeluk een foto met een gezin deelt, moet die zelf kunnen terugtrekken zonder op een beheerder te wachten.

**De teammanager leest alleen.** Een teammanager beheert een selectie; hij beheert niet het bewijsmateriaal van de ontwikkeling van een speler.

Toegang wordt bepaald door `MediaVisibilityService`, niet per scherm. De regel: een gebruiker mag iets met een media-item als hij dat ook mag met **een record waaraan het gekoppeld is** — de koppeling is de eenheid van toegang, want een media-item op zichzelf heeft geen onderwerp. Twee toevoegingen bovenop `MatrixGate`: een `player`-koppeling is óók bereikbaar voor staf met scope op het **team** van die speler (een trainer is immers niet de speler en niet de ouder), en een `activity`-koppeling wordt herleid naar het team van die activiteit.

**Meerdere kinderen op één foto is toegestaan.** Een fragment dat aan drie spelers hangt, is voor alle drie de gezinnen zichtbaar. Dat is een bewuste productkeuze (epic #2589, D5) en volgt uit de koppelingsregel in plaats van een uitzondering te zijn — zie `docs/nl_NL/media-library.md`, waar het beleid staat zodat de toestemmingstekst van een academie erop kan aansluiten. `MediaVisibilityTest` legt het vast, zodat het niet voor een bug wordt aangezien en "gerepareerd" wordt.

Migratie `0220_authorization_seed_media` vult de entiteit aan in `tt_authorization_matrix` op bestaande installaties (idempotente `INSERT IGNORE`, uitsluitend over de nieuwe `media`-rijen).

## Matrix-entiteit `email_compose` — de in-product mailer

De in-product e-mailcomposer (`FrontendMailComposeView`, bereikbaar via `?tt_view=mail-compose&person_id=N`) verstuurt via `wp_mail()` en schrijft per verzending een auditregel weg. Een e-mail versturen is een **handeling**, geen record — er is geen "e-mail-entiteit" om te lezen of te bewerken — dus krijgt zij, net als `impersonation_action`, een eigen **handelings-entiteit** `email_compose` in plaats van een bestaande data-entiteit te lenen.

Vóór #1945 was de handelings-capability `tt_send_email` niet gekoppeld, zodat de composer zodra de matrix actief is voor iedereen op `false` zou uitkomen. #1945 voegt de entiteit + seed en de `LegacyCapMapper`-brug toe:

| Ruwe capability | Matrix-tupel |
| - | - |
| `tt_send_email` | `email_compose` / `create_delete` |

`create_delete` is het operatieve werkwoord — versturen is de handeling — naar analogie van `tt_impersonate_users → impersonation_action:create_delete`. De capability wordt `rcd[global]` geseed aan **head_coach + assistant_coach + head_of_development + academy_admin**. De scope is `global` omdat de mailer op de Personen-pagina academiebreed is (niet teamgebonden).

Beide coach-persona's worden bewust geseed. De ruwe `tt_send_email`-capability is in handen van `administrator` (matrix-uitzondering) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** — en `tt_coach` is de WordPress-rol achter **zowel** de head_coach- **als** de assistant_coach-persona. Alleen head_coach seeden zou stilzwijgend de e-mailcomposer van assistent-coaches intrekken (de dubbel-persona-val uit #1944). Beide worden geseed, dus routering via de matrix is **toegangsbehoudend** — elke ruwe capability-houder, inclusief assistent-coaches, behoudt de composer.

Migratie `0181_authorization_seed_topup_email_compose` vult de entiteit op bestaande installaties bij in `tt_authorization_matrix` (idempotente `INSERT IGNORE`, die alleen over de nieuwe `email_compose`-rijen loopt).

## Rapportgeneratie — `tt_generate_report` is nu matrix-gekoppeld

Rapportgeneratie (`FrontendReportWizardView`, bereikbaar via `?tt_view=report-wizard`; plus de knop "Rapport genereren…" op het spelerdossier in `FrontendPlayersManageView`) wordt afgeschermd door de handelings-capability `tt_generate_report` — los van `tt_generate_scout_report`, die naar `scout_access:create_delete` koppelt. Een rapport genereren is een **create**-handeling, dus `tt_generate_report` koppelt naar `reports:create_delete`:

| Ruwe capability | Matrix-tupel |
| - | - |
| `tt_generate_report` | `reports` / `create_delete` |

De ruwe capability is vandaag in handen van `administrator` (matrix-uitzondering) + `tt_club_admin` + `tt_head_dev` + **`tt_coach`** (de rol achter **zowel** head_coach als assistant_coach). De `reports`-matrix-entiteit gaf die persona's voorheen alleen `read`, dus een naïeve koppeling naar `create_delete` zou generatie stilzwijgend **intrekken** voor coaches en HoD. #1946 behoudt de toegang door `create_delete`-rechten toe te **voegen** in plaats van te verkrappen:

| Persona | Nieuw recht | Scope |
| - | - | - |
| head_coach | `reports` / `create_delete` | team |
| assistant_coach | `reports` / `create_delete` | team |
| head_of_development | `reports` / `create_delete` | global |
| academy_admin | (had al `reports:rcd[global]`) | global |

Beide coach-persona's worden geseed — `tt_coach` is de dubbel-persona-val: alleen head_coach seeden zou generatie voor assistent-coaches verliezen. Coaches krijgen `team`-scope omdat de per-speler team-scope-afscherming al in `FrontendReportWizardView` zit; HoD krijgt `global` (overziet de hele academie). `change` is bewust weggelaten — er is geen oppervlak om een bestaand rapport te bewerken, alleen lezen + genereren. `team_manager`, `scout`, `player` en `parent` houden enkel `reports:read` en winnen niets, dus de koppeling is **toegangsbehoudend** — precies de huidige houders behouden generatie.

Migratie `0182_authorization_seed_topup_report_generation` vult de drie nieuwe rechten op bestaande installaties bij in `tt_authorization_matrix` (idempotente `INSERT IGNORE`, die alleen over de nieuwe `reports:create_delete`-rijen voor head_coach / assistant_coach / head_of_development loopt).

## POP-zichtbaarheid — één gedeelde beslissing, frontend en REST

De zichtbaarheid van een POP-dossier wordt op één plek bepaald: `TT\Modules\Pdp\PdpAccess`. Zowel het gerenderde dossiers-tabblad (`FrontendPdpManageView`) als elke REST-ingang (`PdpFilesRestController`, `PdpVerdictsRestController`) roepen `PdpAccess::canSeeFile( $user_id, $player_id )` aan, zodat beide kanten niet langer verschillend kunnen antwoorden — de oorzaak van het verschil tussen hoofdcoach en HoD in #1758.

De lees-ladder (matrix-bewust, in volgorde):

1. **Globale POP-leestoegang** — een matrixrecht `pdp_file/read/global` (Hoofd Ontwikkeling, Academie-beheerder), de WordPress-sitebeheerder, de oude `tt_edit_settings`-umbrella, of de HoD-/academie-beheerder-persona-terugval voor installaties met een nog slapende matrix.
2. **POP-bewerker van het team van de speler** — heeft `tt_edit_pdp` en coacht het team van de speler (`coach_owns_player`).
3. **POP-lezer van het team van de speler** — heeft `tt_view_pdp` en coacht het team van de speler.

`PdpAccess::canEditFile()` volgt dezelfde ladder met de bewerk-capability, en `PdpAccess::isGlobalVerdictAuthority()` beantwoordt "is deze ondertekenaar het hoofd van de academie?" via de matrix (`pdp_verdict/change/global`) in plaats van de oude rolnaam-stringvergelijking met `tt_head_dev` (#0052 PR-B-schuld).

De voorheen alleen-ingelogd POP-REST-callbacks zijn aangescherpt naar capability-checks (#0052: capabilities zijn het contract, nooit `is_user_logged_in()` als autorisatie):

- `GET /pdp-blocks` en `GET /seasons` — beheer-configuratie-reads, nu afgeschermd op `tt_access_frontend_admin` via de matrixbrug (`AuthorizationService::userCanOrMatrix`). De schrijfpaden blijven ongewijzigd (`tt_edit_settings`).
- `PATCH /pdp-conversations/{id}` — afgeschermd op aanwezigheid van `tt_view_pdp`; de gezaghebbende per-rij-controle (coach-eigenaar / gekoppelde speler / gekoppelde ouder) blijft in `allowedFieldsFor()`.

De effectieve toegang blijft ongewijzigd — iedereen die een POP eerder kon lezen of bewerken krijgt hetzelfde antwoord; het werk verwijderde de frontend/REST-afwijking en de rolnaamvergelijking, het verbreedde of versmalde geen enkele persona.

## Teamchemie — één gedeelde beslissing, frontend en REST

Teamchemie- en teamblauwdruk-autorisatie wordt op één plek beslist: `TT\Modules\TeamDevelopment\TeamChemistryAccess`. De gerenderde blauwdrukweergave (`FrontendTeamBlueprintsView`), de dashboard-dispatchercontrole voor de weergaven `team-chemistry` / `team-blueprints`, de deellink-rotatiehandler en elke REST-`permission_callback` op `TeamDevelopmentRestController` roepen deze aan, zodat de frontend en de REST-API niet langer verschillend kunnen antwoorden.

De beslissing wordt opgelost via de matrixentiteit `team_chemistry` (`MatrixGate`), niet via de ruwe capabilities `tt_view_team_chemistry` / `tt_manage_team_chemistry`:

- `TeamChemistryAccess::canRead()` / `canManage()` — matrix-autoriteit `read` / `change` op `team_chemistry`, **met negeren** van de subfunctie-schakelaar `team_chemistry` (de teamblauwdruk-editor blijft bewust beschikbaar wanneer de chemiebord-functie uit staat).
- `TeamChemistryAccess::canReadChemistry()` / `canManageChemistry()` — dezelfde autoriteit **plus** dat de subfunctie `team_chemistry` aan staat (de chemiebord-oppervlakken, die de functieschakelaar respecteren — #1485).

Omdat de matrix nu de enige bron van waarheid is, krijgen twee persona's die voorheen de ruwe leescapability hadden geen `team_chemistry`-toegang meer:

- **Assistent-coaches verliezen `team_chemistry`-leestoegang.** De matrix laat `team_chemistry` weg bij `assistant_coach` (verwijderd door de redactionele beslissing #1060 "AC is operationeel, HC is ontwikkeling"). Assistent-coaches delen de WP-rol `tt_coach` met hoofdcoaches, dus de rol draagt de capability nog, maar de persona-bewuste matrixcontrole weigert hen. Hoofdcoaches (ook `tt_coach`) houden toegang via hun rij `team_chemistry [rc, team]`.
- **Alleen-lezen-waarnemers verliezen `team_chemistry`-leestoegang.** De alles-ziende waarnemer (`tt_readonly_observer`) heeft geen `team_chemistry`-matrixrij, dus de controle weigert hem. De verouderde `tt_view_team_chemistry`-roltoekenning wordt bij upgrade ingetrokken zodat de WP-capabilities samenvallen met de matrixautoriteit.

Persona's die toegang houden: `head_coach` (lezen + beheren, teamscope), `team_manager` (lezen, teamscope), `scout` (lezen, globaal), `head_of_development` (lezen, globaal), `academy_admin` (lezen + beheren, globaal). WP-beheerders en andere houders van `tt_edit_settings` omzeilen de per-team-leescontrole zoals voorheen.

### Resterende blauwdruk-oppervlakken via `TeamChemistryAccess`

Twee blauwdruk-codepaden bepaalden na #1922 hun autoriteit nog met de ruwe capabilities `tt_view_team_chemistry` / `tt_manage_team_chemistry`; #1939 leidt ook deze via `TeamChemistryAccess`, zodat de hele blauwdruk-functie nu antwoordt vanuit de matrixentiteit `team_chemistry`:

- De aanmaak-wizard voor teamblauwdrukken (`Modules\Wizards\TeamBlueprint\ReviewStep::submit()`) gate't "blauwdruk aanmaken" op `TeamChemistryAccess::canManage()`.
- De blauwdruk-commentaarthread (`Modules\Threads\Adapters\BlueprintThreadAdapter`) gate't lezen op `canRead()` en posten op `canManage()`.

Dit zijn handhaving-alleen herverwijzingen — ze landen exact op de `team_chemistry`-toegang die #1922 vestigde (dezelfde personatabel hierboven).

### De toegangspoort van de wizard sluit aan

Eén blauwdruk-oppervlak bleef achter: de *toegangspoort* van de wizard. `WizardRegistry::isAvailable()` vraagt `AuthorizationService::userCanOrMatrix()` naar de `requiredCap()` van de wizard, en `tt_manage_team_chemistry` is alleen toegekend aan administrator / head_dev / club_admin en heeft geen brug in `LegacyCapMapper` — dus werd een hoofdtrainer geweigerd. De lijstweergave was onder #1922 al overgestapt op `TeamChemistryAccess::canManage()` en toonde dus wél de knop "+ Nieuwe blauwdruk"; het onderliggende toegangspunt loste vervolgens op naar de lege terugval-URL en de knop deed niets.

`NewTeamBlueprintWizard` beantwoordt die vraag nu zelf, via een optionele hook `isAvailableFor( int $user_id ): bool` die `WizardRegistry` aanroept in plaats van de capability-poort zodra een wizard hem declareert. Hij geeft `TeamChemistryAccess::canManage()` terug — dezelfde beslissing die de lijstweergave, de editor, `ReviewStep` en de REST-schrijfacties nemen. Geen enkele andere wizard declareert de hook; de overige zeven houden het ongewijzigde `requiredCap()`-pad.

`tt_manage_team_chemistry` bruggen in `LegacyCapMapper` viel af als oplossing: `LegacyCapMapper::evaluate()` bepaalt via `MatrixGate::canAnyScope()`, die de subfunctie-schakelaar toepast. De functie `team_chemistry` staat standaard uit terwijl de blauwdruk-oppervlakken bewust blijven werken als hij uit staat, dus de brug zou de knop juist dood laten op precies de installaties die de fout melden. De ruwe capability toekennen aan `tt_coach` viel eveneens af — assistent-trainers delen die WP-rol en de matrix weigert hun `team_chemistry`.

Effectieve toegangswijziging: **hoofdtrainers kunnen nu blauwdrukken aanmaken**, wat hun rij `team_chemistry [rc, team]` altijd al zei. Voor geen enkele andere persona verandert het antwoord.

### De blauwdruk- en formatieroutes controleren *welk* team

`canRead()` / `canManage()` hierboven beantwoorden "heb je `team_chemistry` ergens". Dat is de juiste vraag voor een dashboardtegel en de verkeerde voor een route met `{id}`: een grant op **teamscope** voldoet er voor **elk** team aan. De chemieroutes zijn als eerste op scope gezet; de blauwdruk-, formatie- en speelstijlroutes hielden het ongescopete paar, waardoor `GET /blueprints/{id}` iedereen met een grant op één elftal de volledige wedstrijdopstelling van een ander elftal gaf — positielabel, tier en speler-id — en de schrijfvarianten die opstelling lieten herschrijven of verwijderen.

Elke route met `{id}` op `TeamDevelopmentRestController` bepaalt nu eerst het team:

- `GET/PUT /teams/{id}/formation`, `GET/PUT /teams/{id}/style`, `GET/POST /teams/{id}/blueprints` — het team-id staat in het pad en wordt direct doorgegeven.
- `GET/PUT/DELETE /blueprints/{id}` plus `/assignment`, `/assignments`, `/status` en `POST /clone` — het `team_id` van de blauwdruk wordt opgezocht via `TeamBlueprintsRepository::teamIdFor()`, dat alleen die ene kolom leest en nooit de opstellingsregels, zodat het bepalen van toegang niet zelf lekt wat het gaat weigeren. Een blauwdruk die niet in deze club bestaat levert team `0` op, wat de controle laat falen in plaats van slagen.
- `GET /formation-templates` houdt de ongescopete afscherming — die payload is de geseede sjabloonbibliotheek, geen teamgegevens.

De predicaten zijn `TeamChemistryAccess::canReadForTeam()` / `canManageForTeam()`. Ze omhullen `canRead()` / `canManage()` — **niet** het chemiepaar — zodat de blauwdrukeditor beschikbaar blijft wanneer de subfunctie `team_chemistry` uitstaat (#1485, #1922). De scopehelft loopt via de nieuwe `MatrixGate::hasAuthority()`, de gescopete tegenhanger van `hasAuthorityAnyScope()`, die de teamtoewijzing bepaalt zonder de functieschakelaar toe te passen.

Weigeringen zijn **403** — dit is een rechtenantwoord, geen abonnementsantwoord (#3104).

Effectieve toegangswijziging: een `team_chemistry`-grant op **teamscope** (hoofdtrainer, teammanager) bereikt nu alleen de teams waaraan de houder daadwerkelijk is toegewezen. **Globale** grants (scout, hoofd ontwikkeling, academie-admin) veranderen niet en bereiken nog steeds elk team.

## Handelings-capability-bruggen naar bestaande speler-status-entiteiten

De PlayerStatus-handelings-capability "potentieel-band instellen" was matrix-blind terwijl zijn data-capability-broer matrix-bewust was, waardoor de frontend (`FrontendPlayerDetailView`, `FrontendPlayerStatusCaptureView`) en REST (`PlayerStatusRestController`) konden afwijken. #1939 brugt de handelings-capability zodat beide oppervlakken vanuit dezelfde matrixentiteit antwoorden:

- **`tt_set_player_potential` → `player_potential:change`** (gebrugd). De ruwe WP-toekenning (`PlayerStatusModule`: administrator + head_dev + club_admin) komt exact overeen met de begunstigden van `player_potential:change` in de matrix (`head_of_development` + `academy_admin` globaal; geen andere persona houdt `change`), dus de brug is toegangsbehoudend.

Eén verwante handelings-capability werd onder #1939 **bewust niet gebrugd** omdat dat de effectieve toegang zou wijzigen; #1941 (hieronder) maakt die goedgekeurde wijziging en brugt hem alsnog:

- **`tt_rate_player_behaviour`** bleef onder #1939 op de native WP-capability-evaluatie. De ruwe toekenning omvat `tt_assistant_coach`, maar de seed van `player_behaviour_ratings` heeft geen `assistant_coach`-rij (verwijderd door #1060). Brugging zou de assistent-coach-toegang intrekken — een effectieve-toegangswijziging, geen handhaving-alleen herverwijzing — dus werd dit gemarkeerd voor een productbeslissing (de les van #1922: verplaats nooit stilletjes toegang terwijl je "slechts" een capability brugt). De beslissing landde in #1941.

## Mappingrij-bruggen + twee goedgekeurde toegangswijzigingen

#1941 (kind van #1757) brugt zes verouderde handelings-capabilities naar matrixtupels waarvan de entiteit + activiteit **al geseed** is, zodat de frontend- en REST-oppervlakken die op elke capability gaten nu vanuit hetzelfde `MatrixGate`-antwoord oplossen (`current_user_can()` loopt via `LegacyCapMapper` wanneer de matrix actief is). Vier zijn toegangsbehoudend; twee dragen een goedgekeurde effectieve-toegangswijziging.

Toegangsbehoudende bruggen (de matrix-begunstigden komen overeen met de eerdere ruwe toekenning):

- **`tt_manage_staff_development` → `staff_development:create_delete`.** Geseed aan Head of Development + Academy Admin globaal, overeenkomend met de ruwe toekenning. (Gebrugd naar `create_delete`, **niet** `change` — `change` heeft elke coach op self/team-scope, wat het beheeroppervlak zou verbreden.)
- **`tt_manage_modules` → `module_management:create_delete`** (omgezet in #2187; was `feature_toggles:change` onder #1941). Geseed aan alleen Academy Admin. Modulebeheer heeft nu een **eigen** matrix-entiteit, los van de vooral-lezen `feature_toggles` configuratie-entiteit en de `module_state` statusweergave — zodat de matrix "een hele module aan/uit zetten" apart bestuurt van "een configuratie-feature-toggle bewerken". Head of Development heeft `feature_toggles [read]` maar **geen** `module_management`-rij, dus wint niets; modulebeheer blijft alleen-admin. De omzetting kwam voort uit de Modules-beheerpagina (`ModulesPage` / `FrontendModulesView`) die eerder toegang bepaalde via een `current_user_can('administrator')` rolnaam-vergelijking — vervangen door `current_user_can('tt_manage_modules')` zodat de matrix, niet een WP-rolnaam, de toegang beslist. Migratie 0194 vult bestaande installaties aan met de `module_management`-grant zodat geen enkele beheerder de Modules-pagina verliest bij een upgrade.
- **`tt_view_scout_assignments` → `scout_my_players:read`.** Geseed aan alleen de Scout-persona, overeenkomend met de scout-only ruwe toekenning. (De capability opent alleen het "Mijn spelers"-oppervlak; de toewijzingslijst staat in user meta.)
- **`tt_manage_invitations` → `settings:create_delete`.** De administratieve uitnodigingslijst / bulkbeheer-endpoints. Gebrugd naar de admin-niveau-entiteit `settings` (geseed aan alleen Academy Admin; Head of Development heeft geen `settings`-rij), zodat alleen de Academy Admin (en WP-beheerders, die omzeilen) uitnodigingen beheert. Bewust **niet** `invitations:create_delete` — dat tupel is doorgeseed naar coaches/ouders (zodat zij een uitnodiging kunnen *versturen*) en is veel te breed voor het beheeroppervlak. De per-uitnodiging-verstuurcapabilities houden hun `invitations`-tupel.

Goedgekeurde toegangswijzigingen:

- **`tt_manage_teams` → `team:create_delete`** (Head of Development krijgt alle-teams-exports). `team:create_delete` is globaal geseed aan Head of Development + Academy Admin. De capability gate'te de cross-team-exportkeuzelijst (`FrontendExportsView`) en was een alleen-admin-fantoom; onder de matrix ziet de Head of Development nu ook de alle-teams-exportkeuzelijst — bedoeld, want de HoD overziet de hele academie. Hoofdcoaches houden `team [rc, team]` (geen `create_delete`) en zien dus nog steeds alleen hun eigen teams in de keuzelijst.
- **`tt_rate_player_behaviour` → `player_behaviour_ratings:change`** (assistent-coaches verliezen gedragsbeoordeling). De matrix-seed voor `player_behaviour_ratings` heeft geen `assistant_coach`-rij (#1060 "AC is operationeel, HC is ontwikkeling"). Gedragsbeoordeling is een ontwikkelingsoordeel, dus onder de matrix kunnen assistent-coaches geen gedragsbeoordelingen meer schrijven — ze blijven de speler-status-uitsplitsing lezen, ze beoordelen alleen niet. De verouderde ruwe `tt_rate_player_behaviour`-toekenning op de rol `tt_assistant_coach` wordt bij upgrade ingetrokken (`PlayerStatusModule::ensureCapabilities`, naar het voorbeeld van #1922's waarnemer-intrekking) zodat installaties met een nog sluimerende matrix ook samenvallen. Brugging sluit ook de frontend/REST-afwijking waar de data-capability `tt_edit_player_behaviour_ratings` matrix-bewust was maar de handelings-capability niet.

Effectieve toegang voor / na:

| Persona | `tt_manage_teams` (alle-teams-exports) | `tt_rate_player_behaviour` (gedrag beoordelen) |
| - | - | - |
| Hoofdcoach | nee → nee (alleen teamscope, ongewijzigd) | ja → ja |
| Assistent-coach | nee → nee | **ja → nee** (verliest het) |
| Teammanager | nee → nee | nee → nee |
| Scout | nee → nee | nee → nee |
| Head of Development | **nee → ja** (krijgt het) | ja → ja |
| Academy Admin | ja → ja | ja → ja |

## De alle-teams-lens komt uit de matrix

Diverse rapportage- en analyse-schermen tonen een **academiebrede ("alle teams") lens** aan senior staf en een **team-gescopete lens** aan coaches — een Head of Development ziet de aanwezigheid van elk team, een hoofdcoach ziet alleen de teams die hij coacht. De verbreder die bepaalt "mag deze gebruiker hier verder kijken dan zijn eigen teams?" was vroeger het capability-idioom `current_user_can( 'tt_view_all_teams' ) || current_user_can( 'tt_edit_settings' )`. Maar `tt_view_all_teams` werd nooit aan een rol toegekend, dus de echte poort was de te grove instellingen-capability plus de WordPress-admin-bypass — een instellingen-capability die "clubbrede leestoegang" moest voorstellen.

#1942 vervangt dat idioom overal door één gedeelde beslissing: **`TT\Modules\Authorization\AllTeamsScope`**, die de matrix vraagt om **globale-scope leestoegang op de eigen entiteit van het scherm**. Elk scherm wijst naar de entiteit waarvan het de gegevens toont:

| Scherm | Gecontroleerde matrix-entiteit |
| - | - |
| Standaardrapporten, rapporten-launcher, speler-radar-rapport, coach-evaluatiekwaliteit (REST) | `reports` (read / global) |
| Aanwezigheid (team / speler / klassement) + minuten-rapporten, aanwezigheids-ranglijst (REST), cohortbord, teamplanner, lijst wedstrijduitvoeringen, widget "wedstrijden die beoordeling nodig hebben", de deep-link van de Activiteiten-tegel | `activities` (read / global) |
| Evaluaties "audit een andere coach"-override (`GET /evaluations/recent`) | `evaluations` (read / global) |

Doordat de gerenderde views én de REST-permission-callbacks nu uit dezelfde helper beslissen, kunnen de frontend en de API de alle-teams-vraag niet meer verschillend beantwoorden.

Effect op persona's (uit de geleverde seed):

- **Head of Development en Academy Admin behouden de clubbrede weergave** op elk scherm — zij hebben globale leestoegang op `reports`, `activities` en `evaluations`.
- **Scouts krijgen de clubbrede rapporten- en analyse-lens.** De seed geeft scouts al globale leestoegang op `reports` en `activities` (een scout leest per ontwerp team-overstijgend), maar de fantoom-capability ontzegde hen de brede lens; de matrixcontrole laat hen nu wel door. Scouts krijgen **niet** de evaluatie-audit-override — zij hebben alleen speler-gescopete leestoegang op `evaluations`.
- **Team-gescopete coaches (hoofd / assistent) blijven beperkt tot hun eigen teams**, precies zoals voorheen — zij hebben `reports` / `activities` alleen op teamscope.

Het WordPress-instellingenbeheerder-/administrator-pad blijft behouden als terugval op de gerenderde schermen, zodat een operator die de WP-installatie beheert nooit toegang verliest terwijl de matrix van een club nog sluimert. Er is geen matrix-entiteit, seed of migratie gewijzigd — dit is een call-site-refactor op de bestaande toekenningen.

## Matrix-entiteit `recycle_bin` — definitief verwijderen

De prullenbak (archiveren → prullenbak → opschonen) introduceert één nieuwe
matrix-entiteit: `recycle_bin`. De prullenbak beheren — weggegooide rijen
bekijken, herstellen en definitief opschonen — wordt geregeld door de enkele
capability `tt_manage_recycle_bin`. Opschonen is de operatieve destructieve
handeling, dus de capability brugt naar `recycle_bin / create_delete`:

| Ruwe capability | Matrix-tuple |
| - | - |
| `tt_manage_recycle_bin` | `recycle_bin` / `create_delete` |

De seed verleent **alleen academy_admin `rcd[global]`** — dit reproduceert het
alleen-beheerder-ontwerp (WP-administrators passeren via de
matrix-administrator-uitzondering). Geen enkele andere persona heeft een rij.
De capability levert alleen-academiebeheerder mee in `RolesService`
(`RECYCLE_BIN_CAPS` → `tt_club_admin` + administrator), dus de ruwe
capability-houders komen netjes overeen met de seed-begunstigde: routering via
de matrix is **toegangsbehoudend** — geen enkele persona wint of verliest
toegang.

De capability staat bewust **niet** in `RolesService::VIEW_CAPS` /
`EDIT_CAPS`, zodat hij niet via `allViewCapsTrue()` automatisch doorstroomt
naar HoD — precies het `tournaments`-ontwerp hierboven.

Migratie `0187_authorization_seed_topup_recycle_bin` vult de entiteit op
bestaande installaties bij in `tt_authorization_matrix` (idempotente `INSERT
IGNORE`, alleen de nieuwe `recycle_bin`-rijen). Het schema + de
retentieconfiguratie landen in de gepaarde migratie
`0186_recycle_bin_foundation`.

## Matrix-entiteit `strava_integration` — persoonlijke activiteitskoppeling (#2127, #2153)

De Strava-integratie wordt geregeld door de matrix-entiteit
`strava_integration`, gekoppeld vanuit twee ruwe capabilities:

| Ruwe capability | Matrix-tuple |
| - | - |
| `tt_view_strava` | `strava_integration` / `read` |
| `tt_edit_strava_credentials` | `strava_integration` / `change` |

De **operatorconsole** (Configuratie → Integraties: app-credentials,
webhook-abonnement, koppelingenoverzicht) is geseed voor `head_coach` en
`academy_admin` met `global`-scope — migratie
`0191_authorization_seed_topup_strava` vulde die rijen op.

`player` heeft `strava_integration` `rc[self]`: Strava is
**persoonlijke activiteitsdata**, dus een speler koppelt zijn eigen Strava
vanaf zijn profiel en kan nooit de koppeling van een andere speler aanraken.
Dit weerspiegelt het `my_profile` self-recht van de speler. Migratie
`0193_authorization_seed_player_strava` vult de twee spelerrijen op bestaande
installaties op (idempotente `INSERT IGNORE`, alleen de
`player` / `strava_integration`-tuples). Gedrag van trainer en beheerder
blijft ongewijzigd.

## Twee rollen die geen persona-rijen bereikten (#3177)

`readonly_observer` en `tt_staff` kwamen allebei uit op iets waar de matrix geen antwoord op had, op twee net iets verschillende manieren. Beide zijn nu geseed.

**Alleen-lezen Waarnemer** had wel een personasleutel in `PersonaResolver` maar geen rijen in de seed. De Sprint 1-notitie legde die omissie vast als bewust — elke scopevraag was toen nog een rechtencontrole — en dat klopte niet meer zodra schermen op matrixscope overgingen. Alles wat om een **globale** grant vroeg kreeg nee, dus de rol versmalde tot de teams waaraan die is toegewezen, en dat zijn er geen: een lege `GET /teams`, lege keuzelijsten, geen academiebrede rapportages.

**`tt_staff`** is het scherpere geval: die had helemaal geen personakoppeling, dus `personasFor()` gaf `[]` terug en `MatrixGate` sloeg af vóór de matrix. Omdat `AuthorizationModule::filterUserHasCap()` `$allcaps[$cap]` *toewijst* in plaats van samenvoegt, werden op een installatie met `tt_authorization_active` de eigen rechten van de rol **overschreven met false** — geweigerd, niet versmald. Geen seed kon dat alleen oplossen; de persona moest bestaan.

### Wat elk heeft gekregen

`readonly_observer` — lezen op **globale** scope, en nergens een schrijfwerkwoord:

`team`, `players`, `people`, `evaluations`, `activities`, `goals`, `reports`, `settings`.

Die acht zijn precies waar `RolesService::VIEW_CAPS` via `LegacyCapMapper` op uitkomt, dus de seed is toegangsbehoudend: hij geeft de matrix exact wat de rechtenbrug al geeft.

Een eerste voorstel was globale leestoegang op **alle 138 entiteiten**, geredeneerd vanuit de docstring `"view EVERYTHING, edit NOTHING"` van de rol. Dat draaide de verhouding om — die docstring beschrijft `allViewCapsTrue()`, en dat zijn deze acht rechten, niet de matrix. De brede variant zou een bestuurslid of sponsor de derde persona hebben gemaakt die `safeguarding_notes` over minderjarigen kan lezen, naast `player_injuries`, `player_notes`, `parent_accounts`, `media`, `audit_log` en `impersonation_log`. 52 van de 138 entiteiten liggen vandaag alleen bij Hoofd Ontwikkeling en Academie-admin, en nog eens 17 bestaan alleen op `self`- of `player`-scope, waar een globale rij betekenisloos is.

`staff` — lezen/wijzigen op **team**scope, gelijk aan `team_manager`, waar de #0085-notitie deze rol bij groepeert:

| Entiteit | Werkwoorden | Scope |
| --- | --- | --- |
| `team` | lezen | team |
| `players` | lezen, wijzigen | team |
| `people` | lezen, wijzigen | team |
| `player_notes` | lezen, wijzigen | team |
| `my_person` | lezen, wijzigen | self |

`my_person` is de enige rij die niet uit een rechtenkoppeling volgt — het zelfbedieningsdeel van `people:change`, zodat een fysio zijn eigen dossier kan bijhouden voordat die aan een elftal is gekoppeld. Strikt smaller dan de `people`-grant hierboven.

**`players:create_delete` wordt bewust niet gegeven.** De rol houdt `tt_manage_players` als kaal WP-recht, maar dat recht is in deze codebase geen "selectie beheren": het dekt de seizoensovergang, het aanmaken van spelersaccounts, maatwerkvelddefinities en het verwijderen van spelers, en `BehaviourPendingSource` gebruikt het als markering voor "ziet elke speler in de academie" voor — in het eigen commentaar — HoD's en beheerders. Dat seeden zou een materiaalman het beheerdersoppervlak geven. Niet seeden verandert niets aan het huidige gedrag: op een matrix-actieve installatie heeft de rol nu niets, en op een matrix-inactieve wordt de seed niet geraadpleegd. Of het kale recht op de roldefinitie moet blijven staan is een aparte vraag, want dát weghalen zou matrix-inactieve installaties wél veranderen.

Migratie `0249_authorization_seed_topup_observer_and_staff` vult beide persona's aan op bestaande installaties — idempotente `INSERT IGNORE`, alleen deze twee persona's, en weigert voor de waarnemer een andere activiteit dan `read` weg te schrijven, ook als de seed er later een zou krijgen. Voor geen enkele andere persona verandert het antwoord.

## Zie ook

- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
- [Modules](modules.md) — een module uitschakelen kortsluit zijn matrixrijen.
- [Prullenbak](recycle-bin.md) — bewaartermijn, eigenaar van verwijderen, AVG.
