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

Cellen bewerken is **shadow-modus** totdat je op **Apply** klikt op de Migration Preview-pagina (TalentTrack → Toegangsbeheer → Migration preview).

Tijdens shadow-modus:

- De `tt_authorization_matrix`-tabel reflecteert je bewerkingen.
- De legacy `current_user_can( 'tt_view_evaluations' )`-aanroepen beslissen nog steeds wie wat mag.
- Niets breekt voor echte gebruikers; je kunt zorgeloos bewerken.

Wanneer je op **Apply** klikt:

- Een vlag (`tt_authorization_active`) gaat naar `1`.
- De `user_has_cap`-filter routeert elke legacy `tt_*`-cap-check via de matrix.
- Echte gebruikers zien de nieuwe rechten bij hun volgende verzoek.

Klik **Rollback** om de vlag weer naar `0` te zetten — matrixdata blijft bewaard, alleen de routing wijzigt. Rollback is één klik; matrix-gedreven autorisatie is een bewust omkeerbare beslissing.

## De migratiepreview

Voordat je op Apply klikt, toont de Migration Preview-pagina:

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
- `scout` — `tt_scout` WP-rol; leest spelers + evaluaties over teams heen.
- `team_manager` — nieuw in #0033 Sprint 7; `tt_team_manager` WP-rol. Logistiek voor een team (activiteiten, aanwezigheid, uitnodigingen) zonder coachingautoriteit.
- `academy_admin` — `administrator` of `tt_club_admin` WP-rol.

Een gebruiker kan meerdere persona's tegelijk vasthouden (een ouder die ook hoofdcoach is). De matrix gebruikt de **unie** standaard — elke persona die toestemming verleent wint. De persona-switcher in het gebruikersmenu laat multi-persona-gebruikers het dashboard tijdelijk filteren naar de visie van één persona; dat is een UI-lens, geen autorisatiebeperking.

## Zie ook

- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
- [Modules](modules.md) — een module uitschakelen kortsluit zijn matrixrijen.
