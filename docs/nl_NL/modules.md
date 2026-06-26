<!-- audience: admin -->

# Modules (beheerdersgids)

**TalentTrack → Toegangsbeheer → Modules**

Elke TalentTrack-module kan hier worden uitgeschakeld. Uitgeschakelde modules `register()` en `boot()` niet — hun tegels, REST-routes, beheerpagina's en capabilities verdwijnen totdat ze weer worden ingeschakeld. De toggle is per installatie, dus een multi-tenant deployment heeft een aparte per-tenant-vlag nodig (uitgesteld tot v2 van #0011).

## Frontend-toegang (v4.21.15+)

Dezelfde toggle is bereikbaar vanuit de frontend-beheeromgeving via **`?tt_view=modules`** (en een **Modules**-tegel onder Configuratie), afgeschermd met de capability `tt_manage_modules` (standaard beheerder + clubbeheerder) in plaats van een kale admin-only-controle. Hij is ook beschikbaar via REST voor niet-WordPress-frontends: `GET /wp-json/talenttrack/v1/modules` geeft de modules; een `POST` met `{ "class": "...", "enabled": true|false }` schakelt er één om. De wp-adminpagina blijft als fallback voor gevorderden.

## Kaartindeling (v4.29.0+)

De frontend-Modulespagina toont modules als **kaarten gegroepeerd per categorie** in plaats van een platte lijst. Elke kaart toont een pictogram, een leesbaar label en een korte omschrijving, plus een statuspil — **Kern** (grijs, kan niet worden uitgeschakeld), **On** (groen) of **Off** (gedempt) — en een **Module**-typelabel. De schakelaar rechts schakelt de module in of uit; bij kernmodules staat de schakelaar vast. De bevestigingsdialoog ("herlaad open tabbladen na het opslaan") en de onderliggende REST-contracten zijn ongewijzigd.

De categorieën, op volgorde: **Spelersgegevens**, **Coaching & ontwikkeling**, **Planning & wedstrijddag**, **Communicatie**, **Analyse & rapportage**, **Integraties**, **Administratie** (met de drie altijd-aan kernmodules) en **Geavanceerd / ontwikkelaar**. Het label, de omschrijving, het pictogram en de categorie van elke module staan op één plek — `TT\Shared\Modules\ModuleMetadata` — zodat een gebruiker nooit een kale klassennaam ziet.

Als een module subfuncties heeft, toont de kaart een functieteller (bijv. "2 functies") en een uitklapbaar paneel. Elke functie staat in de kaart van de bovenliggende module, met een eigen **Functie**-pil (visueel anders dan het Module-label), de omschrijving en een eigen schakelaar. Functies verschijnen alleen zolang hun bovenliggende module aanstaat. De pagina is mobile-first: kaarten stapelen op een telefoon in één kolom en de schakelaars voldoen aan het 48px-aanraakdoel.

## Waarom een module uitschakelen?

- **Demo aan een niet-betalende prospect.** Schakel License uit zodat de upgrade-banner niet stoort.
- **Pre-launch dev.** Schakel Backup uit totdat de cron-job op de host is geconfigureerd.
- **Per-club productoppervlak.** Een jeugdclub heeft geen Methodologie nodig, dus de Methodology-tab maakt hun setup rommelig.
- **Feature-debug.** Een nieuwe beheerder heeft de Workflow-tab even uit nodig om de rest van het product te doorgronden.
- **Het spelerdashboard inkorten.** De Players-module bevat een feature per spelertegel — Mijn reis, Mijn team, Mijn evaluaties, Mijn activiteiten, Mijn doelen, Mijn POP. Zet een ervan uit (ze staan standaard aan) om die tegel te verbergen *én* de bijbehorende `?tt_view`-URL voor spelers in deze academie te blokkeren. Het spelerprofiel is het altijd-aanwezige anker en heeft geen toggle.
- **Rapporten samenstellen.** De Reports-module bevat een feature per rapport (10 in totaal — de acht standaardrapporten plus de twee wp-admin-rapporten). Zet er een uit (ze staan standaard aan) om de launcher-tegel van dat rapport te verbergen *én* een directe link erheen te weigeren, net als de per-tegel-toggles van de Export-module.

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

Op de frontend-Modulepagina verschijnt elke functie als een ingesprongen rij (↳) direct onder de bovenliggende module, met een eigen Aan/Uit-schakelaar. Een functie verschijnt alleen zolang de bovenliggende module aanstaat. De functies die **standaard uit** staan:

- **Cohort-overgangen** (Journey-module, standaard **uit**) — de academie­brede zoekopdracht "vind spelers op journey-gebeurtenis + datumbereik" (`?tt_view=cohort-transitions`). Uitzetten verbergt de tegel, de pagina en de REST-route (`/journey/cohort-transitions`). De rest van Journey — spelers­tijdlijn, blessures, safeguarding-notities — blijft volledig beschikbaar.
- **Teamchemie** (Team Development-module, standaard **uit**) — het formatiebord met voorgestelde XI en chemie-score (`?tt_view=team-chemistry`). Uitzetten verbergt de tegel, de pagina en de chemie-/koppel-/team-fit-REST-routes. De **Teamblauwdruk**-editor — die in dezelfde module zit en dezelfde capability deelt — blijft beschikbaar.
- **Analytics-verkenner** (Analytics-module, standaard **uit**) — de ad-hoc verkenner voor KPI- en dimensievragen (`?tt_view=analytics`, `explore`, `scheduled-reports`). Zie de sectie hieronder voor wat blijft draaien als hij uitstaat. (Vanaf v4.30.0 is dit een `FeatureRegistry`-functie, beheerd op dezelfde frontend-Modulepagina als de andere, niet langer alleen op de wp-admin-pagina.)
- **Eigen widgets** (Eigen widgets-module, standaard **uit**) — de bèta-bouwer voor eigen dashboardwidgets. Uitzetten slaat de hele moduleboot over — geen beheerpagina, geen REST-routes, geen tegel in het editorpalet — precies zoals de oude optie `tt_custom_widgets_enabled`. (Vanaf v4.30.0 is dit een `FeatureRegistry`-functie; de vorige optiewaarde wordt bij de upgrade meegenomen, zodat er niets verandert.)

De functies die **standaard aan** staan (ze draaien vandaag al; uitzetten is een opt-out, dus academies die ze willen houden ze zonder iets te doen):

- **Oefeningen uit foto halen** (Oefeningen-module, standaard **aan**) — de foto→oefening-AI-extractie (`POST /vision/extract`) en de bijbehorende vastleg-UI. Uitzetten laat de extractie-REST-route 403 teruggeven; de CRUD van de oefeningenbibliotheek blijft ongemoeid.
- **Deellinks voor blauwdrukken** (Team Development-module, standaard **aan**) — openbare, alleen-lezen deellinks voor teamblauwdrukken (`?tt_view=team-blueprint-share`) en het genereren/roteren van de deel-URL. Uitzetten verbergt de deelacties in de blauwdruk-editor, laat de openbare deel-URL de melding "niet geldig" tonen en weigert de rotatie-actie; het bewerken van blauwdrukken blijft ongemoeid.
- **Workflow onboardingpijplijn** (Workflow-module, standaard **aan**) — de automatische taken die prospects door de wervingstrechter leiden (prospect registreren → uitnodigen → proeftraining → stagebeoordeling → teamaanbod). Uitzetten stopt het aanmaken van nieuwe taken door deze zes templates; de onboarding-pijplijnweergave en bestaande taken blijven zichtbaar, en elke andere workflow-template blijft werken. Dit is de schakelaar waarmee een academie "workflow alleen voor onboarding" kan draaien — laat deze aan en zet de overige templates uit in de workflow-templateconfiguratie.
- **Team planner** (Planning-module, standaard **aan**) — de week-voor-week teamplanningskalender (`?tt_view=team-planner`). Uitzetten verbergt de Team planner-tegel en de pagina; het **Activiteiten**-logboek — de terugkijkende weergave — blijft beschikbaar, zodat een academie die activiteit voor activiteit werkt de vooruitkijkende planner kan uitzetten.
- **Sms-kanaal** (Comms-module, standaard **aan**) — biedt sms aan als berichtenkanaal (bezorging vereist nog een providerplug-in). Uitzetten verwijdert de sms-kanaaladapter zodat er geen sms verstuurd kan worden; e-mail, push, WhatsApp-link en in-app blijven werken.
- **Geplande berichten** (Comms-module, standaard **aan**) — de dagelijkse cron die doelaansporingen, aanwezigheidssignalen, onboarding-aansporingen en herinneringen voor stafontwikkeling verstuurt. Uitzetten stopt het registreren van de geplande cron; gebeurtenisgestuurde berichten blijven afgaan vanuit hun eigen modules.
- **Medische gebeurtenissen op tijdlijn** (Journey-module, standaard **aan**) — toont blessures en medische gebeurtenissen op de spelerstijdlijn aan staf die de medische-inzage-rechten al heeft. Uitzetten verbergt medische gebeurtenissen op de tijdlijn, zelfs voor bevoegde staf (een academiebrede privacyrem); het recht zelf blijft ongewijzigd.
- **OPP-kalenderintegratie** (OPP-module, standaard **aan**) — schrijft geplande OPP-gesprekken naar de kalenderfeed wanneer een ontwikkelplan wordt aangemaakt of overgedragen. Uitzetten slaat het kalenderschrijven over; OPP-plannen, gesprekken en beoordelingen blijven ongemoeid.
- **Dashboardlay-out-editor** (Persona Dashboard-module, standaard **aan**) — de sleep-en-neerzet-bouwer voor persona-dashboardlay-outs. Uitzetten verbergt het editor-menu-item, de Configuratie-tegel en de editorpagina zelf; de weergegeven dashboards blijven werken met hun opgeslagen lay-outs.
- **Wedstrijdvoorbereiding pdf-export** (Match Prep-module, standaard **aan**) — de afdruk-/exporteer-naar-pdf-acties van het A4-wedstrijdvoorbereidingsblad. Uitzetten verbergt de Afdrukken/exporteren-knoppen en weigert zowel de client-afdrukroute als de server-DomPDF-export; de wedstrijdvoorbereidingseditor op het scherm blijft ongemoeid.
- **Toernooi-auto-balanceren** (Tournaments-module, standaard **aan**) — de greedy eerlijk-verdelen-autoplanner die een wedstrijdgrid invult op basis van inzetbaarheid, gelijke speelminuten en spreiding van basisplaatsen. Uitzetten verbergt de Auto-balanceren-knop op elke wedstrijdkaart en laat de `auto-plan`-REST-route 403 teruggeven zodat hij niet rechtstreeks kan worden aangeroepen; de per-wedstrijd planner en het handmatig wisselen via klikken blijven ongemoeid, zodat een Hoofd Opleiding dat speelminuten met de hand plant de snelkoppeling kan weghalen zonder de planner te verliezen.

Wat een uitgeschakelde functie doet, bij de volgende paginalading:

- De **tegel** verdwijnt van het dashboard (naastgelegen tegels in dezelfde module blijven).
- Wie op de `?tt_view=<slug>` van de functie belandt (bladwijzer, oud tabblad) ziet dezelfde vriendelijke melding "dit onderdeel is momenteel niet beschikbaar" als bij een uitgeschakelde module.
- `MatrixGate` weigert de eigen matrix-entiteit van de functie op elk niveau — de capability is onbereikbaar, zelfs voor een persona die hem bezit — zonder entiteiten te raken die met naastgelegen onderdelen gedeeld worden.
- De **REST-routes** van de functie geven 401/403; routes achter naastgelegen onderdelen blijven werken.
- Bestaande datarijen blijven **ongemoeid** — weer aanzetten herstelt de toegang tot alle historie.

De status staat in `tt_feature_state` (met de `club_id` tenancy-steiger), plus `updated_by` + timestamp voor audit. Het is via REST beschikbaar voor niet-WordPress-frontends: `GET /wp-json/talenttrack/v1/features` toont de functies; `POST` met `{ "key": "...", "enabled": true|false }` schakelt er één (beide afgeschermd met `tt_manage_modules`).

### Analytics-verkenner

- **Analytics-verkenner** (standaard **uit**) — de ad-hoc Analytics-tegel en dimensie-/KPI-verkenner (`?tt_view=analytics`, `explore`, `scheduled-reports`). Vanaf v4.30.0 is dit een `FeatureRegistry`-functie, beheerd op de frontend-Modulepagina naast de andere (de wp-admin-Modulepagina werkt ook nog; beide schrijven dezelfde `tt_feature_state`-rij). Uitzetten verbergt de tegel en die pagina's, maar de **analytics-engine blijft draaien** — de aanwezigheids-, speelminuten- en standaardrapporten plus de dashboard-KPI's werken gewoon, want die gebruiken de engine rechtstreeks, niet de verkenner-UI. Sinds v4.26.9 verbergt de schakelaar ook elke inline **Verkennen →**-link (spelerdetail, teamdetail, standaardrapporten, de prospects-per-scout-tegel op de rapportenstartpagina), zodat het uitzetten van de Verkenner geen verwijzingen naar een uitgeschakelde functie achterlaat. De activiteitendetailpagina toont helemaal geen Verkenner-rij meer.

## Alleen-lezen status voor iedereen (`?tt_view=features`, v4.23.1+)

De Modulepagina is alleen voor beheerders (het is een schrijfvlak). Voor transparantie krijgt elke gebruiker — coach, speler, ouder — een alleen-lezen **Functies**-weergave op **`?tt_view=features`**, bereikbaar via een **Functies**-tegel onder de groep **Over** op het dashboard. Er is geen speciale capability voor nodig.

Het toont elke gebruikersgerichte module met een **Aan / Uit / Altijd aan**-badge, een regel "Levert:" (opgebouwd uit de onderdelen die de module bezit), en eventuele subfuncties eronder met hun eigen badge + beschrijving. Er zijn geen knoppen — het is een momentopname van wat live is. Gebruikers die modules *mogen* beheren zien een link **Modules & functies beheren** naar de bewerkbare pagina.

Dezelfde data is via REST beschikbaar op `GET /wp-json/talenttrack/v1/feature-status` (elke ingelogde gebruiker). Alle vormgeving zit in `FeatureStatusService`, zodat de weergave en de API hetzelfde antwoord geven. Alleen modules die de gebruiker daadwerkelijk iets tonen (een tegel of functie bezitten) verschijnen — pure infrastructuurmodules worden weggelaten.

## Zie ook

- [Authorisatie­matrix](authorization-matrix.md) — module-disable voedt de matrix-gate.
- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
