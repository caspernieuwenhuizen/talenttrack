<!-- audience: admin, dev -->

# Workflow- en takenmotor

De workflow-motor zet "we zouden eigenlijk na elke wedstrijd moeten evalueren" om in een ingeplande, zichtbare taak die met een deadline in iemands inbox belandt. De motor draagt de orkestratielaag die coaches, spelers en het Hoofd Opleidingen op tijd aan hun rol in de systematische ontwikkeling herinnert.

Deze pagina is de overzichtspagina. De implementatiedetails voor cron-betrouwbaarheid en e-mailbezorging staan op aparte pagina's zodra die schermen live zijn.

## Wat het doet (in één alinea)

Templates beschrijven een terugkerende of gebeurtenis-gestuurde taak ("een coach-evaluatie na een wedstrijd, deadline 72 uur na de sessie, één taak per geëvalueerde speler"), plus hoe de juiste persoon wordt gevonden ("de hoofdcoach van het team") en welk formulier wordt ingevuld. De motor activeert het template op het rooster (cron, event of handmatige knop), maakt één taak per (toegewezen persoon × betrokken entiteit) aan en routeert die naar de juiste inbox. Het invullen van de taak slaat het antwoord op en activeert eventueel vervolgwerk.

## Wat v1 oplevert

- **Motor + schema (Sprint 1, deze release)** — de fundamenttabellen (`tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_template_config`), de kolom `parent_user_id` op `tt_players` en de PHP-API. Nog geen live taken — templates die ze aanmaken landen in Sprint 3.
- **Inbox + bel + e-mail + self-diagnostics (Sprint 2)** — elke gebruiker met een TalentTrack-rol krijgt een "Mijn taken"-scherm; een onopvallende bel toont het openstaande aantal; e-mails verzenden bij taakaanmaak en kort vóór een deadline. Een self-diagnostic banner waarschuwt op hosts waar WP-cron niet betrouwbaar draait.
- **Vier meegeleverde templates (Sprint 3-4)**
  - **Coach-evaluatie na wedstrijd** — activeert wanneer een wedstrijd-sessie wordt afgesloten; fan-out van één taak per geëvalueerde speler; deadline 72 uur.
  - **Wekelijkse zelfevaluatie speler** — zondagavond 18:00; één taak per ingedeelde speler; deadline 7 dagen.
  - **Kwartaaldoelen met goedkeuringsketen** — begin van elk kwartaal; de inzending van de speler activeert een goedkeuringstaak voor de coach.
  - **Kwartaalreview Hoofd Opleidingen** — begin van elk kwartaal; één taak voor elk Hoofd Opleidingen; deadline 14 dagen.
- **Dashboard + templateconfiguratie (Sprint 5)** — het HoD-overzicht (afrondingspercentage per coach/team, gebruik per template) en een academy-admin-pagina om templates aan/uit te zetten en cadens / deadlines te overschrijven.

## Rechten

Sprint 1 reserveert vier capabilities zodat de volgende sprints hun schermen kunnen toevoegen zonder rolverdelingen telkens te moeten aanpassen:

| Capability | Standaard toegekend aan |
| --- | --- |
| `tt_view_own_tasks` | elke TalentTrack-rol + administrator |
| `tt_view_tasks_dashboard` | administrator + Hoofd Opleidingen + Club Admin |
| `tt_configure_workflow_templates` | administrator + Club Admin |
| `tt_manage_workflow_templates` | alleen administrator |

## Toewijzingsbeleid voor minderjarigen

Veel taken (een wekelijkse zelfevaluatie, een doelstellingformulier) zijn op de speler gericht. Spelers onder de 16 hebben mogelijk geen eigen login; clubs moeten kunnen kiezen of die taken naar de speler, de ouder of beiden gaan.

De motor leest `tt_workflow_minors_assignment_policy` uit `tt_config` en kent vier waarden:

- `direct_only` — taak gaat altijd naar de WP-gebruiker van de speler.
- `parent_proxy` — taak gaat altijd naar de WP-gebruiker van de ouder (`tt_players.parent_user_id`).
- `direct_with_parent_visibility` — taak gaat naar de speler; de ouder heeft alleen leestoegang (Sprint 2-inbox).
- `age_based` (standaard) — onder 13: parent_proxy. 13-15: direct_with_parent_visibility. 16+: direct_only.

Sprint 1 levert de resolver + de geseede standaardwaarde. Het scherm om het beleid te wijzigen komt in Sprint 5.

## Betrouwbaarheid — cron en e-mail

De motor leunt op WP-cron voor ingeplande triggers en `wp_mail()` voor notificaties. Sprint 2 levert:

- Een cron-self-diagnostic-banner die waarschuwt wanneer ingeplande taken niet op tijd zijn afgevuurd.
- Een e-mailbevestigingsflow bij activering: een klik-om-te-bevestigen-testmail, met een fallback-banner als de admin niet binnen 7 dagen klikt.

Beide linken naar speciale instelhandleidingen voor hosts waar WP-cron of `wp_mail()` niet werkt.

## Wat zit niet in v1

- Geen formulierenbouwer — elk formulier is een PHP-klasse die met de plugin meekomt of door een developer wordt toegevoegd.
- Geen browser-push — alleen bel + e-mail tot de PWA-pass (apart epic) is geland.
- Geen meerstaps-ketens als first-class primitive — Phase 1 lost goal-setting op met een handmatige `onComplete`-hook; Phase 2 generaliseert dit.

## Zie ook

- [Toegangsbeheer](access-control.md) — voor de capability-slugs die de motor gebruikt.
- [Sessies](sessions.md) — wedstrijd-sessies zijn de trigger voor wedstrijdevaluaties.
- [Doelen](goals.md) — kwartaaldoelen zijn een van de meegeleverde templates.
