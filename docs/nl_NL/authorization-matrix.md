<!-- audience: admin -->

# Authorisatie­matrix (beheerdersgids)

**TalentTrack → Toegangsbeheer → Authorisatie­matrix**

De authorisatie­matrix is de centrale bron voor "wat mag elke persona, op welke entiteit?". Acht persona's × ~30 entiteiten × drie acties (lezen / wijzigen / aanmaken-verwijderen) = enkele honderden cellen. De meegeleverde standaardwaarden komen overeen met wat elke rol vandaag al doet; beheerders kunnen per cel afwijken zonder code te schrijven.

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

Tot #0021 (de audit-log viewer epic) verschijnt, wordt de changelog alleen weergegeven binnen de Matrix-pagina. Na #0021 gaan deze rijen op in de unieke audit-log.

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
- `head_coach` — een `tt_coach` WP-gebruiker met `tt_team_people.is_head_coach = 1` voor minstens één team. Een coach kan beide persona's tegelijk hebben als hij/zij hoofdcoach is van het ene team en assistent van een ander.
- `head_of_development` — `tt_head_dev` WP-rol; overziet de hele academie.
- `scout` — `tt_scout` WP-rol; leest spelers over teams heen. Sinds v4.20.103 (#1378) zijn evaluatie-reads beperkt tot toegewezen spelers, en POP-dossiers/-oordelen worden helemaal niet toegekend — selectiebeslissingen zijn geen scouting-input.
- `team_manager` — nieuw in #0033 Sprint 7; `tt_team_manager` WP-rol. Logistiek voor een team (activiteiten, aanwezigheid, uitnodigingen) zonder coachingautoriteit.
- `academy_admin` — `administrator` of `tt_club_admin` WP-rol.

Een gebruiker kan meerdere persona's tegelijk vasthouden (een ouder die ook hoofdcoach is). De matrix gebruikt de **unie** standaard — elke persona die toestemming verleent wint. De persona-switcher in het gebruikersmenu laat multi-persona-gebruikers het dashboard tijdelijk filteren naar de visie van één persona; dat is een UI-lens, geen autorisatiebeperking.

## POP-zichtbaarheid — één gedeelde beslissing, frontend en REST (#1923)

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

## Teamchemie — één gedeelde beslissing, frontend en REST (#1922)

Teamchemie- en teamblauwdruk-autorisatie wordt op één plek beslist: `TT\Modules\TeamDevelopment\TeamChemistryAccess`. De gerenderde blauwdrukweergave (`FrontendTeamBlueprintsView`), de dashboard-dispatchercontrole voor de weergaven `team-chemistry` / `team-blueprints`, de deellink-rotatiehandler en elke REST-`permission_callback` op `TeamDevelopmentRestController` roepen deze aan, zodat de frontend en de REST-API niet langer verschillend kunnen antwoorden.

De beslissing wordt opgelost via de matrixentiteit `team_chemistry` (`MatrixGate`), niet via de ruwe capabilities `tt_view_team_chemistry` / `tt_manage_team_chemistry`:

- `TeamChemistryAccess::canRead()` / `canManage()` — matrix-autoriteit `read` / `change` op `team_chemistry`, **met negeren** van de subfunctie-schakelaar `team_chemistry` (de teamblauwdruk-editor blijft bewust beschikbaar wanneer de chemiebord-functie uit staat).
- `TeamChemistryAccess::canReadChemistry()` / `canManageChemistry()` — dezelfde autoriteit **plus** dat de subfunctie `team_chemistry` aan staat (de chemiebord-oppervlakken, die de functieschakelaar respecteren — #1485).

Omdat de matrix nu de enige bron van waarheid is, krijgen twee persona's die voorheen de ruwe leescapability hadden geen `team_chemistry`-toegang meer:

- **Assistent-coaches verliezen `team_chemistry`-leestoegang.** De matrix laat `team_chemistry` weg bij `assistant_coach` (verwijderd door de redactionele beslissing #1060 "AC is operationeel, HC is ontwikkeling"). Assistent-coaches delen de WP-rol `tt_coach` met hoofdcoaches, dus de rol draagt de capability nog, maar de persona-bewuste matrixcontrole weigert hen. Hoofdcoaches (ook `tt_coach`) houden toegang via hun rij `team_chemistry [rc, team]`.
- **Alleen-lezen-waarnemers verliezen `team_chemistry`-leestoegang.** De alles-ziende waarnemer (`tt_readonly_observer`) heeft geen `team_chemistry`-matrixrij, dus de controle weigert hem. De verouderde `tt_view_team_chemistry`-roltoekenning wordt bij upgrade ingetrokken zodat de WP-capabilities samenvallen met de matrixautoriteit.

Persona's die toegang houden: `head_coach` (lezen + beheren, teamscope), `team_manager` (lezen, teamscope), `scout` (lezen, globaal), `head_of_development` (lezen, globaal), `academy_admin` (lezen + beheren, globaal). WP-beheerders en andere houders van `tt_edit_settings` omzeilen de per-team-leescontrole zoals voorheen.

## Zie ook

- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
- [Modules](modules.md) — een module uitschakelen kortsluit zijn matrixrijen.
