---
title: Modules
group: frontend
summary: Module-toggles per installatie — schakel Methodology, Workflow, License, etc. uit zonder code aan te raken.
audience: [admin]
order: 80
---

# Modules (beheerdersgids)

**TalentTrack → Toegangsbeheer → Modules**

Elke TalentTrack-module kan hier worden uitgeschakeld. Uitgeschakelde modules `register()` en `boot()` niet — hun tegels, REST-routes, beheerpagina's en capabilities verdwijnen totdat ze weer worden ingeschakeld. De toggle is per installatie, dus een multi-tenant deployment heeft een aparte per-tenant-vlag nodig (uitgesteld tot v2 van #0011).

## Frontend-toegang (v4.21.15+)

Dezelfde toggle is bereikbaar vanuit de frontend-beheeromgeving via **`?tt_view=modules`** (en een **Modules**-tegel onder Configuratie), afgeschermd met de capability `tt_manage_modules` (standaard beheerder + clubbeheerder) in plaats van een kale admin-only-controle. Hij is ook beschikbaar via REST voor niet-WordPress-frontends: `GET /wp-json/talenttrack/v1/modules` geeft de modules; een `POST` met `{ "class": "...", "enabled": true|false }` schakelt er één om. De wp-adminpagina blijft als fallback voor gevorderden.

## Kaartindeling (v4.29.0+)

De frontend-Modulespagina toont modules als **kaarten gegroepeerd per categorie** in plaats van een platte lijst. Elke kaart toont een pictogram, een leesbaar label en een korte omschrijving, plus een statuspil — **Kern** (grijs, kan niet worden uitgeschakeld), **On** (groen) of **Off** (gedempt) — en een **Module**-typelabel. De schakelaar rechts schakelt de module in of uit; bij kernmodules staat de schakelaar vast. De bevestigingsdialoog ("herlaad open tabbladen na het opslaan") en de onderliggende REST-contracten zijn ongewijzigd.

De categorieën, op volgorde: **Spelersgegevens**, **Coaching & ontwikkeling**, **Planning & wedstrijddag**, **Communicatie**, **Analyse & rapportage**, **Integraties**, **Administratie** (met de drie altijd-aan kernmodules) en **Geavanceerd / ontwikkelaar**. Het label, de omschrijving, het pictogram en de categorie van elke module staan op één plek — `TT\Shared\Modules\ModuleMetadata` — zodat een gebruiker nooit een kale klassennaam ziet.

Als een module subfuncties heeft, toont de kaart een functieteller (bijv. "2 functies") en een uitklapbaar paneel. Elke functie staat in de kaart van de bovenliggende module, met een eigen **Functie**-pil (visueel anders dan het Module-label), de omschrijving en een eigen schakelaar. Functies verschijnen alleen zolang hun bovenliggende module aanstaat. De pagina is mobile-first: kaarten stapelen op een telefoon in één kolom en de schakelaars voldoen aan het 48px-aanraakdoel.

Een **zoekbalk** boven aan de frontendpagina (`?tt_view=modules`, v4.x+) filtert de lijst live tijdens het typen — op naam of omschrijving van een module of functie. Is de treffer een geneste functie, dan klapt de modulekaart automatisch open zodat de rij zichtbaar is; categorieën zonder overgebleven treffers vallen weg en er verschijnt een leeg-melding wanneer niets overeenkomt. Het is een filter aan de clientkant (geen herlaad), en met JavaScript uit verschijnt gewoon de volledige lijst. De wp-admin Modules-pagina heeft geen zoekfunctie — de frontendpagina is de surface die wordt voortgezet.

## Een module of functie "in ontwikkeling" markeren (v4.x+)

**Een hele module** markeer je in de kop van de modulekaart, met het selectievakje **In ontwikkeling** onder de aan/uit-schakelaar van de module. Markeer je een module, dan geldt dat voor alles wat die module bezit: elke weergave toont het label, en elke dashboardtegel die ernaartoe leidt krijgt een klein **In ontwikkeling**-label — zo is de markering al zichtbaar *voordat* iemand doorklikt, niet pas daarna. Ook een kernmodule (altijd aan) kun je markeren: de markering schakelt niets uit, dus er is geen reden om die uit te zonderen.

**Een losse functie** markeer je op dezelfde manier vanaf de functierij binnen de modulekaart.

Het label verschijnt op een dashboardtegel zodra de functie van de tegel gemarkeerd is **of** de bijbehorende module, zodat beide niveaus voor de gebruiker hetzelfde werken. Tegels op het persona-dashboard, het klassieke tegeloverzicht, de "Mijn werk"-kolom en de kindtegels van een ouder tonen het label allemaal.

Elke functierij heeft naast de aan/uit-schakelaar een tweede besturingselement: een **In ontwikkeling**-selectievakje. Vink je het aan, dan toont elke weergave die de functie bezit boven aan een klein amberkleurig **In ontwikkeling**-label, zodat iedereen die het scherm gebruikt — coaches, spelers én ouders — weet dat het nog wordt gebouwd en kan veranderen. Het label is puur informatief: het schakelt niets uit en verbergt niets, en de functie blijft precies zo werken als voorheen. Het staat los van de aan/uit-schakelaar, dus een functie kan live *en* gemarkeerd zijn, en je kunt de markering weer uitzetten zonder te raken aan of de functie aanstaat. Alleen beheerders die modules mogen beheren (`tt_manage_modules`) zien of wijzigen de markering; het label zelf is zichtbaar voor elke gebruiker van het gemarkeerde scherm. De markering is ook te lezen en te zetten via het REST-endpoint `/talenttrack/v1/features`.

## Waarom een module uitschakelen?

- **Demo aan een niet-betalende prospect.** Schakel License uit zodat de upgrade-banner niet stoort.
- **Pre-launch dev.** Schakel Backup uit totdat de cron-job op de host is geconfigureerd.
- **Per-club productoppervlak.** Een jeugdclub heeft geen Methodologie nodig, dus de Methodology-tab maakt hun setup rommelig.
- **Feature-debug.** Een nieuwe beheerder heeft de Workflow-tab even uit nodig om de rest van het product te doorgronden.
- **Het spelerdashboard inkorten.** De Players-module bevat een feature per spelertegel — Mijn reis, Mijn team, Mijn evaluaties, Mijn activiteiten, Mijn doelen, Mijn POP. Zet een ervan uit (ze staan standaard aan) om die tegel te verbergen *én* de bijbehorende `?tt_view`-URL voor spelers in deze academie te blokkeren. Het spelerprofiel is het altijd-aanwezige anker en heeft geen toggle.
- **Rapporten samenstellen.** De Reports-module bevat een feature per rapport (15 in totaal — de acht standaardrapporten, de twee wp-admin-rapporten, de drie aanwezigheidsrapporten, gespeelde minuten per team en de rapportkaarten). Zet er een uit (ze staan standaard aan) om de launcher-tegel van dat rapport te verbergen *én* een directe link erheen te weigeren, net als de per-tegel-toggles van de Export-module.

## Wat de toggle daadwerkelijk doet

Wanneer een module wordt uitgeschakeld, **bij de volgende paginalaad**:

- `Kernel::loadModules()` slaat de klasse volledig over — `register()` + `boot()` draaien nooit.
- Hooks, REST-routes, capability-declaraties, geplande events van die module — alle stilzwijgend afwezig.
- **Tegels op het frontend-dashboard** die bij de module horen verdwijnen uit het tegelraster.
- **wp-admin sidebarregels** van de module verdwijnen uit het menu, en hun directe URL's stoppen met werken.
- **Tegels + statkaarten op het wp-admin dashboard** voor de entiteit van de module verdwijnen.
- Een gebruiker die op `?tt_view=<slug>` van een uitgeschakelde module landt (bookmark, oude tab) ziet een vriendelijke "dit onderdeel is momenteel niet beschikbaar"-melding met een terugknop — geen 404 of fatal.
- `MatrixGate::can()` kortsluit elke matrixrij waarvan de `module_class` is uitgeschakeld — zelfs als een persona de toestemming heeft, is de entiteit onbereikbaar. Eén autorisatiecheck, geen parallel "staat dit aan?"-pad.
- **Helponderwerpen** van de module verdwijnen uit Help & Docs — uit de zijbalk, het zoekveld, de helplade en de directe onderwerp-URL's. Zie hieronder.
- Bestaande datarijen in de tabellen van de module zijn **onaangeroerd** — de module weer aanzetten herstelt toegang tot alle historische data.

## Helponderwerpen volgen de schakelaars

Een helponderwerp beschrijft een functie. Kan de installatie die functie niet
draaien, dan is het onderwerp geen voorproefje van wat je mist — het is een
uitleg bij een scherm dat er niet is. De documentatie leest daarom dezelfde
vier schakelaars als de rest van het systeem:

| Front-matter sleutel | Verborgen wanneer |
| --- | --- |
| `module:` | de module uit staat |
| `feature:` | de functieschakelaar uit staat |
| `tier:` | je licentie lager is dan het niveau dat het onderwerp noemt |
| `capability:` | jij die capability zelf niet hebt |

Een onderwerp zonder deze sleutels wordt hierdoor nooit verborgen — dat geldt
voor de meeste onderwerpen, en die gedragen zich precies zoals altijd.

**Verbergen is volledig, niet cosmetisch.** Een verborgen onderwerp staat niet
in de inhoudsopgave, niet in het zoekveld, niet in de helplade, en is ook via
de eigen URL onbereikbaar. Er is geen "upgrade om dit te lezen"-verleider: een
academie op Free ziet Pro-documentatie helemaal niet. Wil je weten wat een
hoger niveau je oplevert, dan hoort dat op de licentiepagina, niet in de
helpindex.

**Het is omkeerbaar en direct.** Zet je de module weer aan, dan staan de
onderwerpen er bij de volgende paginalaad weer. Er wordt niets over de
schakelaar heen gecachet en er wordt niets verwijderd.

**Een typefout faalt open.** Een onderwerp dat een niet-bestaande module of
functie noemt blijft zichtbaar in plaats van te verdwijnen — een document dat
op andermans installatie stilletjes wegvalt is het lastigere probleem. De
docs-lint vangt de typefout af vóór het meegaat in een release.

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
- **Spelervergelijking** (Stats-module, standaard **aan**) — de tegel en weergave Spelervergelijking (`?tt_view=compare`) om maximaal vier spelers naast elkaar te vergelijken. Uitzetten verbergt de tegel en blokkeert een directe link erheen; de rest van de Stats-module (Podium, Applicatie-KPI's) blijft ongemoeid.
- **Podium** (Stats-module, standaard **aan**) — de tegel en weergave Podium (`?tt_view=podium`) met teamranglijsten en topspelers. Uitzetten verbergt de tegel en blokkeert een directe link erheen; de rest van de Stats-module (Spelervergelijking, Applicatie-KPI's) blijft ongemoeid.

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

## Uitschakelbaarheid — het contract voor een nieuwe module (#2599)

*Doelgroep: ontwikkelaars.* Alles hierboven gaat over het gebruiken van de schakelaars. Dit gaat over ze eerlijk houden.

Het schakelmechanisme werkte altijd al. Wat ontbrak was iets dat **faalt** wanneer een nieuwe module of een nieuw routeerbaar scherm zonder schakelaar meegaat — het was dus allemaal conventie, en een conventie ontdek je doordat een academie vraagt: "waarom kan ik dit niet uitzetten?".

`tools/check-module-toggles.php` draait bij elke PR die de bestanden raakt die over uitschakelbaarheid gaan. Vijf controles:

1. **Elke moduleklasse op schijf staat in `config/modules.php`.** Een module die er wel is maar niet aangemeld, start nooit op en is voor geen enkele beheerder aan te zetten.
2. **Elke aangemelde module heeft een `ModuleMetadata`-vermelding.** Zonder die vermelding toont de modulepagina een geslugificeerde klassenaam waar een label hoort. Deze controle vond op de dag dat ze geschreven werd vijf modules zonder metadata.
3. **Elke `?tt_view=`-slug van een tegel heeft een uitschakelaar.** Dat kan op drie manieren, en alleen de derde vraagt om het manifest:
   1. een `FeatureRegistry`-vermelding claimt de slug in haar `view_slugs`;
   2. de tegel noemt een `module_class` die een academie kan uitzetten — de moduleschakelaar verbergt hem dan al;
   3. hij staat in `config/always_on_surfaces.php`, mét reden.
4. **Geen matrix-entiteit wordt door twee functies geclaimd.** De docblock van de catalogus zegt dit al altijd; niets controleerde het, en een dubbele claim gate't stilletjes ook het scherm van de buur.
5. **Elke `module_class` van een functie verwijst naar een aangemelde module.** Een functie die een niet-aangemelde klasse noemt, gate't stilletjes niets.

### Wat dit betekent als je een module toevoegt

- Zet de klasse in `config/modules.php`, en alleen in `ModuleRegistry::ALWAYS_ON_MODULES` als het product er écht onbruikbaar zonder is — vandaag voldoen drie modules daaraan.
- Voeg een `ModuleMetadata`-vermelding toe: een label, een omschrijving van één regel in de taal van de academie in plaats van die van de codebase, een icoon, een categorie.
- Voeg een `FeatureRegistry`-vermelding toe als de module schermen bezit die een academie misschien niet wil, en **zet elke nieuwe view-slug in dezelfde PR die het scherm toevoegt**. Die gewoonte is de hele bedoeling; de gate zorgt dat ze niet afhangt van wie eraan denkt.

### Wanneer een scherm altijd aan moet staan

Controleer eerst of hij écht niet uit te zetten is: **een tegel die zijn eigen module noemt, is al gedekt**, want de moduleschakelaar verbergt hem. Dat is het normale geval, en de module benoemen is bijna altijd de juiste oplossing in plaats van er iets aan toe te voegen.

Heeft hij werkelijk geen uitschakelaar, zet hem dan in `config/always_on_surfaces.php` met een zin over wat er stukgaat als hij uit kan. Er staan zes vermeldingen, allemaal echte keuzes: de instellingenpagina, de functieschakelpagina zelf, migraties en het auditlogboek snijden elk de weg terug af; functionele rollen zijn de manier waarop iemand überhaupt rechten krijgt; en `open-wp-admin` is een link búiten het product, dus die mag niet afhangen van of het product het doet.

Het manifest bevatte kort ook 54 andere, als `grandfathered`. Bijna allemaal waren ze een bijproduct van de eerste versie van de gate, die alleen route (1) kende en dus een functieschakelaar eiste voor schermen waarvan de module allang uitschakelbaar was. Route (2) toevoegen liet er in één klap 47 verdwijnen — en bracht één echte bug aan het licht: een tegel voor de databrowser die geen module noemde en dus bleef staan terwijl zijn eigen module uitstond.

### De gate wordt zelf getest

`bin/module-toggle-selfcheck.php` draait de gate tegen bewust kapotgemaakte kopieën van de boom en controleert dat hij op elk daarvan faalt, en om de juiste reden. Dat een gate slaagt, bewijst niet dat hij werkt.

Twee dingen kan hij bewust niet controleren, en dat zegt hij dan ook in plaats van te gokken: een tegel-slug die tijdens runtime uit een variabele wordt opgebouwd, en elk scherm dat helemaal niet als tegel is aangemeld.

## Zie ook

- [Authorisatie­matrix](authorization-matrix.md) — module-disable voedt de matrix-gate.
- [Toegangsbeheer](access-control.md) — het bredere rol- + capability-model.
