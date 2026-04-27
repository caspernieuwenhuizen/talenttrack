<!-- audience: admin -->

# Modules (beheerdersgids)

**TalentTrack → Toegangsbeheer → Modules**

Elke TalentTrack-module kan hier worden uitgeschakeld. Uitgeschakelde modules `register()` en `boot()` niet — hun tegels, REST-routes, beheerpagina's en capabilities verdwijnen totdat ze weer worden ingeschakeld. De toggle is per installatie, dus een multi-tenant deployment heeft een aparte per-tenant-vlag nodig (uitgesteld tot v2 van #0011).

## Waarom een module uitschakelen?

- **Demo aan een niet-betalende prospect.** Schakel License uit zodat de upgrade-banner niet stoort.
- **Pre-launch dev.** Schakel Backup uit totdat de cron-job op de host is geconfigureerd.
- **Per-club productoppervlak.** Een jeugdclub heeft geen Methodologie nodig, dus de Methodology-tab maakt hun setup rommelig.
- **Feature-debug.** Een nieuwe beheerder heeft de Workflow-tab even uit nodig om de rest van het product te doorgronden.

## Wat de toggle daadwerkelijk doet

Wanneer een module wordt uitgeschakeld, **bij de volgende paginalaad**:

- `Kernel::loadModules()` slaat de klasse volledig over — `register()` + `boot()` draaien nooit.
- Beheerpagina's, hooks, REST-routes, capability-declaraties, geplande events van die module — alle stilzwijgend afwezig.
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

## Zie ook

- [Authorisatie­matrix](authorization-matrix.md) — module-disable voedt de matrix-gate.
- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
