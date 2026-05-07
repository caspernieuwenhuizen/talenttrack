<!-- audience: admin, dev -->

# Aangepaste widgets

Stel je eigen dashboardwidgets samen zonder te programmeren. Kies een geregistreerde gegevensbron (Spelers, Evaluaties, Doelen, Activiteiten, PDP), kies kolommen en filters, kies een grafiektype, sla op — en sleep de widget vervolgens vanuit het palet van de dashboardeditor op een persona-dashboard.

Aangepaste widgets staan naast de standaard widgetcatalogus. Ze volgen dezelfde persona-context, mobiele-prioriteit en bento-grid-formaten; het verschil is dat een beheerder ze tijdens runtime samenstelt in plaats van dat een ontwikkelaar ze in code uitlevert.

## Wanneer een aangepaste widget gebruiken

Gebruik een aangepaste widget wanneer de vraag die je een dashboard wilt laten beantwoorden nog geen standaardwidget heeft. Voorbeelden:

- *"Top 10 actieve spelers in de O13-selectie"* — tabel over `players_active`, filter `team_id`.
- *"Gemiddelde evaluatiebeoordeling per coach in de afgelopen 30 dagen"* — KPI over `evaluations_recent`, aggregatie `avg_overall`.
- *"Doelen per principe"* — staafdiagram over `goals_open`, groeperen op principe.
- *"PDP-bestanden goedgekeurd dit seizoen"* — KPI over `pdp_files`, filter `season_id` + `status=signed_off`.

Als het antwoord al via een standaardwidget (KPI-kaarten, datatabellen) plus bestaande gegevensbronnen kan worden bereikt, kies dan dat pad — minder bewegende delen.

## Een widget bouwen

De bouwer leeft op **TalentTrack → Aangepaste widgets** (ook bereikbaar via het Configuratie-tegelpaneel). Cap-gated op `tt_author_custom_widgets`. Zes stappen:

1. **Bron** — kies welke gegevensbron de widget leest. Elke bron declareert haar eigen kolommen, filters en aggregaties; alles stroomafwaarts wordt opnieuw weergegeven wanneer de bron verandert.
2. **Kolommen** — kies voor tabelwidgets welke kolommen verschijnen. KPI / staaf / lijn-widgets negeren de kolomlijst (ze tonen één geaggregeerde waarde of één reeks).
3. **Filters** — beperk de rijen. Filters worden door de bron gedeclareerd (bv. `team_id`, `date_from`, `status`); laat een filter leeg om het over te slaan.
4. **Opmaak** — kies een grafiektype: Tabel, KPI (één groot getal), Staafdiagram of Lijndiagram. Niet-tabel-typen hebben ook een aggregatie nodig (count / avg / sum / distinct).
5. **Voorbeeld** — slaat de widget als concept op en toont de daadwerkelijke gegevens die het dashboard zou laten zien.
6. **Opslaan** — geef de widget een naam (1-120 tekens) en kies een cache-houdbaarheid in minuten (standaard 5; zet op 0 om caching uit te schakelen).

## Een widget op een persona-dashboard tonen

1. Open **TalentTrack → Dashboardlay-outs** (cap `tt_edit_persona_templates`).
2. Sleep in het palet van de editor de tegel **Aangepaste widget** op het canvas.
3. In het eigenschappenpaneel rechts toont de *Gegevensbron*-dropdown elke opgeslagen aangepaste widget op naam. Kies de jouwe.
4. Sla de persona-lay-out op / publiceer hem.

## Hoe rechten werken

Aangepaste widgets respecteren twee lagen:

- **Auteursrecht** — `tt_author_custom_widgets` (HoD + admin); `tt_manage_custom_widgets` voegt verwijderrecht toe (alleen admin). Beide bruggen naar een `custom_widgets` matrix-entiteit, dus per-clubaanpassingen gebeuren in het matrixbeheer.
- **Bron-cap-overerving op rendertijd** — elke standaard gegevensbron declareert het onderliggende leesrecht (`players_active` → `tt_view_players`, `evaluations_recent` → `tt_view_evaluations`, enz.). Een kijker zonder het onderliggende recht ziet een "Je hebt geen toegang tot deze gegevens."-stub in plaats van de gerenderde widget. Dit wordt afgedwongen in `CustomWidgetRenderer`; je kunt het niet omzeilen door een aangepaste widget samen te stellen.

Een ouder zonder `tt_view_evaluations` kan geen op evaluaties gebaseerde aangepaste widget zien op iemand anders' dashboard, ook niet als een beheerder die daar heeft geplaatst. Dezelfde gate die de onderliggende recordlijst beschermt, beschermt de widget.

## Caching

Elke aangepaste widget heeft een per-widget transient-cache met sleutel `(uuid, user_id)`:

- **TTL is per widget** — ingesteld in de Opslaan-stap; standaard 5 minuten.
- **TTL van 0 schakelt caching uit** — de renderer haalt verse gegevens op bij elke render. Gebruik dit voor snel veranderende widgets waar de cachekosten zwaarder wegen dan de besparing.
- **Opslaan / bijwerken / archiveren wist automatisch** de cache.
- **Handmatig wissen** — elke rij in de lijstweergave heeft een "Cache wissen"-knop. De knop verhoogt de per-uuid versie-teller, waardoor elke eerdere cache-vermelding wordt verweesd; vervolgens worden verse gegevens opgehaald.

Het versie-tellerpatroon zorgt ervoor dat cache-wissen O(1) is, ongeacht hoeveel gebruikers de widget hebben gerenderd.

## Auditlog

Elke opslag / update / archief schrijft een rij naar `tt_audit_log`:

| Actie | Lading |
|---|---|
| `custom_widget.created` | uuid, name, data_source_id, chart_type |
| `custom_widget.updated` | (idem) |
| `custom_widget.archived` | (idem) |

De audit is een van de bronnen voor "wie heeft deze widget het laatst gewijzigd?"-onderzoeken; de dashboardeditor zelf wordt ook geaudit (#0060), dus het volledige pad van aangepaste-widget-bewerking tot persona-template-publicatie is reconstrueerbaar.

## Buiten scope (vandaag)

Deze functies komen bewust niet in v1:

- **Vrije-tekst-SQL-toegang** — te risicovol op een multi-tenant SaaS. Auteurs componeren alleen tegen de geregistreerde gegevensbronklassen.
- **Visuele SQL-bouwer** — substantieel extra UI-werk; opnieuw bekijken als gegevensbronklassen te rigide blijken.
- **Per-versie widgetgeschiedenis** — de audit log legt vast wie/wanneer/wat is gewijzigd, maar er is geen rollback in v1.
- **Cirkel- / donut- / radardiagrammen** — al gedekt door bestaande widgets waar nuttig.
- **Cross-bron joins** — elke widget leest precies één gegevensbron. Operators die joinende gegevens willen, vragen om een nieuwe gegevensbronklasse.
- **Auteur-gedefinieerde aangepaste gegevensbronnen via UI** — alleen via PHP geregistreerde bronnen in v1.
- **Per-rij drilldown-links van een aangepaste widget-tabel → recorddetailpagina** — v1 levert read-only; klikbare rijen volgen in v2.

## Een nieuwe gegevensbron toevoegen (ontwikkelaarstaak)

Een plugin-auteur kan extra bronnen registreren door `\TT\Modules\CustomWidgets\Domain\CustomDataSource` te implementeren en `CustomDataSourceRegistry::register()` aan te roepen vanuit een `boot()`-hook:

```php
add_action( 'init', function () {
    \TT\Modules\CustomWidgets\CustomDataSourceRegistry::register( new MyCustomSource() );
}, 25 );
```

De interface declareert vijf methoden: `id()` (snake_case stabiele id die wordt gebruikt als de `data_source_id` foreign key), `label()` (vertaalbaar picker-label), `columns()` (lijst van `[key, label, kind]`), `filters()` (lijst van `[key, label, kind, options?]`), `fetch( $user_id, $filters, $column_keys, $limit )`, `aggregations()` (lijst van `[key, label, kind, column?]`).

Bronnen moeten ook `requiredCap(): string` implementeren zodat de bron-cap-overerving van de renderer in werking treedt. De interface vereist het niet (additief na Phase 1), maar elke standaardbron heeft het. Een bron zonder cap retourneert de lege string.

Binnen `fetch()` MOET de bron scopen op `\TT\Infrastructure\Tenancy\CurrentClub::id()` en demo-modus-scope toepassen. De registry kan dat niet afdwingen — die weet niet welke `tt_*` tabel de bron leest.

## Feature flag

De hele module is opt-in via `tt_custom_widgets_enabled`. Vanaf v3.109.7 (Phase 6 sluit #0078) blijft de vlag standaard **uit** zodat bestaande installaties bij de volgende upgrade niet verrast worden door een nieuwe beheerderspagina; zet hem per club aan met:

```
wp option update tt_custom_widgets_enabled 1
```

…of zet dezelfde sleutel op `tt_config` per club. Zodra de vlag aanstaat, verschijnt de beheerderspagina op TalentTrack → Aangepaste widgets, registreren de REST-routes zich en krijgt het editor-palet de *Aangepaste widget*-tegel.
