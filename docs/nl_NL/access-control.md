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

## Permission debug

Via **Toegangsbeheer → Permission Debug** kun je de effectieve rechten van een willekeurige gebruiker inspecteren. Handig als een gebruiker meldt "ik kan X niet zien" — controleer wat hij/zij daadwerkelijk heeft.

## Een rol-toewijzing intrekken

Via **Toegangsbeheer → Rollen** (of het bewerkpaneel per persoon) heeft elke toegekende rol een **Intrekken**-actie.

Een klik op Intrekken opent een bevestigingsvenster binnen de app (niet de standaard browserprompt) — bevestig met de rode **Intrekken**-knop, annuleer via **Annuleren**, een klik op de achtergrond of de Escape-toets. Na bevestiging wordt de toewijzing verwijderd en kom je terug op dezelfde pagina met een succesmelding.

Hetzelfde bevestigingspatroon wordt overal gebruikt waar een destructieve actie om je akkoord vraagt (een doel verwijderen vanaf het dashboard, een evaluatiecategorie verwijderen, enz.).
