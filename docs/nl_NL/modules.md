<!-- audience: admin -->

# Modules (beheerdersgids)

**TalentTrack → Toegangsbeheer → Modules**

Elke TalentTrack-module kan hier worden uitgeschakeld. Uitgeschakelde modules `register()` en `boot()` niet — hun tegels, REST-routes, beheerpagina's en capabilities verdwijnen totdat ze weer worden ingeschakeld. De toggle is per installatie, dus een multi-tenant deployment heeft een aparte per-tenant-vlag nodig (uitgesteld tot v2 van #0011).

## Frontend-toegang (v4.21.15+)

Dezelfde toggle is bereikbaar vanuit de frontend-beheeromgeving via **`?tt_view=modules`** (en een **Modules**-tegel onder Configuratie), afgeschermd met de capability `tt_manage_modules` (standaard beheerder + clubbeheerder) in plaats van een kale admin-only-controle. Hij is ook beschikbaar via REST voor niet-WordPress-frontends: `GET /wp-json/talenttrack/v1/modules` geeft de modules; een `POST` met `{ "class": "...", "enabled": true|false }` schakelt er één om. De wp-adminpagina blijft als fallback voor gevorderden.

## Waarom een module uitschakelen?

- **Demo aan een niet-betalende prospect.** Schakel License uit zodat de upgrade-banner niet stoort.
- **Pre-launch dev.** Schakel Backup uit totdat de cron-job op de host is geconfigureerd.
- **Per-club productoppervlak.** Een jeugdclub heeft geen Methodologie nodig, dus de Methodology-tab maakt hun setup rommelig.
- **Feature-debug.** Een nieuwe beheerder heeft de Workflow-tab even uit nodig om de rest van het product te doorgronden.

## Wat de toggle daadwerkelijk doet

Wanneer een module wordt uitgeschakeld, **bij de volgende paginalaad**:

- `Kernel::loadModules()` slaat de klasse volledig over — `register()` + `boot()` draaien nooit.
- Hooks, REST-routes, capability-declaraties, geplande events van die module — alle stilzwijgend afwezig.
- **Tegels op het frontend-dashboard** die bij de module horen verdwijnen uit het tegelraster.
- **wp-admin sidebarregels** van de module verdwijnen uit het menu, en hun directe URL's stoppen met werken.
- **Tegels + statkaarten op het wp-admin dashboard** voor de entiteit van de module verdwijnen.
- Een gebruiker die op `?tt_view=<slug>` van een uitgeschakelde module landt (bookmark, oude tab) ziet een vriendelijke "dit onderdeel is momenteel niet beschikbaar"-melding met een terugknop — geen 404 of fatal.
- `MatrixGate::can()` kortsluit elke matrixrij waarvan de `module_class` is uitgeschakeld — zelfs als een persona de toestemming heeft, is de entiteit onbereikbaar. Eén autorisatiecheck, geen parallel "staat dit aan?"-pad.
- Bestaande datarijen in de tabellen van de module zijn **onaangeroerd** — de module weer aanzetten herstelt toegang tot alle historische data.

## Altijd-aan modules

Drie modules kunnen niet worden uitgeschakeld. Hun toggle is inert met een tooltip:

| Module | Waarom |
| - | - |
| `Auth` | Inloggen + uitloggen. Het product is onbereikbaar zonder. |
| `Configuration` | De instellingstabel + lookups. De meeste andere modules lezen uit `tt_config`. |
| `Authorization` | De matrix zelf. Uitschakelen zou iedereen buitensluiten van de toggle. |

## License-module — speciaal geval

De License-module ontvangt **standaard ingeschakeld** + met een inline waarschuwing wanneer uitgeschakeld:

> ⚠️ **Vergeet niet de gate te implementeren voordat je live gaat.**
> License uitschakelen verwijdert alle monetisatiecontroles (`LicenseGate::*`).
> Pre-launch is dit prima voor demo's en dev. Voor de publieke launch
> moet je ofwel `LicenseModule` hardcoded inschakelen, ofwel een
> `TT_DEV_MODE`-constante implementeren die deze toggle in productie
> uitschakelt.

De waarschuwing is bewust. Op dit moment (pre-monetisatie-launch) is de runtime-toggle de eenvoudige weg; zodra het product live is, wordt de toggle een harde gate die constante-gedreven afdwinging nodig heeft zodat een kwaadwillende beheerder hem niet kan uitschakelen om de facturatie te ontwijken.

## Afhankelijkheden tussen modules

**Nog niet afgedwongen.** Een module uitschakelen waarvan een andere module afhangt, kan stilzwijgend de afhankelijke breken. Voorbeelden:

- `WorkflowModule` bouwt taaktemplates die `EvaluationsModule`-entiteiten refereren. Evaluations uitschakelen laat Workflow-templates wijzen naar niets — ze no-op'en gracieus maar renderen verwarrend.
- `InvitationsModule` schrijft naar `tt_player_parents` (geïntroduceerd in #0032). Players uitschakelen laat de pivot dode foreign keys bevatten.

Een afhankelijkheidsgrafiek + waarschuwings-UI staat op de v2-roadmap voor de Modules-surface.

## Audit

Elke module-statuswijziging schrijft een rij naar `tt_module_state` met de `updated_by` gebruikers-id en timestamp. Tot #0021 verschijnt en de audit-log viewer dit oppervlakt, is de rij het enige spoor.

## Functies (schakelaars binnen een module)

Sommige modules bezitten meerdere losse onderdelen. Met een **functievlag** zet je er één uit terwijl de rest van de module — en de naastgelegen onderdelen — blijft draaien. Dit is fijnmaziger dan de moduleschakelaar: de hele module uitzetten zou onderdelen meenemen die je juist wilt behouden.

### Functieschakelaars per module (`?tt_view=modules`, v4.23.0+)

Op de frontend-Modulepagina verschijnt elke functie als een ingesprongen rij (↳) direct onder de bovenliggende module, met een eigen Aan/Uit-schakelaar. Een functie verschijnt alleen zolang de bovenliggende module aanstaat. Twee functies staan **standaard uit**:

- **Cohort-overgangen** (Journey-module, standaard **uit**) — de academie­brede zoekopdracht "vind spelers op journey-gebeurtenis + datumbereik" (`?tt_view=cohort-transitions`). Uitzetten verbergt de tegel, de pagina en de REST-route (`/journey/cohort-transitions`). De rest van Journey — spelers­tijdlijn, blessures, safeguarding-notities — blijft volledig beschikbaar.
- **Teamchemie** (Team Development-module, standaard **uit**) — het formatiebord met voorgestelde XI en chemie-score (`?tt_view=team-chemistry`). Uitzetten verbergt de tegel, de pagina en de chemie-/koppel-/team-fit-REST-routes. De **Teamblauwdruk**-editor — die in dezelfde module zit en dezelfde capability deelt — blijft beschikbaar.

Wat een uitgeschakelde functie doet, bij de volgende paginalading:

- De **tegel** verdwijnt van het dashboard (naastgelegen tegels in dezelfde module blijven).
- Wie op de `?tt_view=<slug>` van de functie belandt (bladwijzer, oud tabblad) ziet dezelfde vriendelijke melding "dit onderdeel is momenteel niet beschikbaar" als bij een uitgeschakelde module.
- `MatrixGate` weigert de eigen matrix-entiteit van de functie op elk niveau — de capability is onbereikbaar, zelfs voor een persona die hem bezit — zonder entiteiten te raken die met naastgelegen onderdelen gedeeld worden.
- De **REST-routes** van de functie geven 401/403; routes achter naastgelegen onderdelen blijven werken.
- Bestaande datarijen blijven **ongemoeid** — weer aanzetten herstelt de toegang tot alle historie.

De status staat in `tt_feature_state` (met de `club_id` tenancy-steiger), plus `updated_by` + timestamp voor audit. Het is via REST beschikbaar voor niet-WordPress-frontends: `GET /wp-json/talenttrack/v1/features` toont de functies; `POST` met `{ "key": "...", "enabled": true|false }` schakelt er één (beide afgeschermd met `tt_manage_modules`).

### Analytics-verkenner

- **Analytics-verkenner** (standaard **uit**, beheerd op de **wp-admin**-Modulepagina) — de ad-hoc Analytics-tegel en dimensie-/KPI-verkenner (`?tt_view=analytics`, `explore`, `scheduled-reports`). Uitzetten verbergt de tegel en die pagina's, maar de **analytics-engine blijft draaien** — de aanwezigheids-, speelminuten- en standaardrapporten plus de dashboard-KPI's werken gewoon, want die gebruiken de engine rechtstreeks, niet de verkenner-UI.

## Zie ook

- [Authorisatie­matrix](authorization-matrix.md) — module-disable voedt de matrix-gate.
- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
