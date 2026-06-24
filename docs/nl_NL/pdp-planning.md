<!-- audience: user -->

# POP-planningsvensters + HO-dashboard

De tegel **POP-planning** toont het hoofd opleidingen een matrix per team per blok: hoeveel gesprekken zijn ingepland in hun venster van drie weken, en hoeveel hebben een vastgelegd resultaat zodra het venster gesloten is.

## Hoe het werkt

Elk blok van de POP-cyclus (start / midden / eind, afhankelijk van de cyclusgrootte) heeft een **planningsvenster**. Standaard is dat `scheduled_at ± 10 dagen` (21 dagen totaal); beheerders kunnen de lengte aanpassen via de instelling `pdp_planning_window_days`. Het venster wordt afgekapt op de seizoensgrenzen.

Coaches plannen een gespreksdatum binnen het venster vanuit het dossierdetail. Het planningsdashboard van de HO bij **POP-planning** (`?tt_view=pdp-planning`) toont de matrix:

- **Rijen** = teams in het geselecteerde seizoen.
- **Kolommen** = blokindex (1, 2, 3 …). Het aantal kolommen volgt het **geconfigureerde** aantal blokken voor het seizoen (Configuratie → POP-blokken), niet de bloknummers die toevallig in opgeslagen gesprekken voorkomen. Draagt een oud of seed-gesprek een hoger bloknummer dan de huidige configuratie, dan verbreedt dat de matrix niet langer — alleen de geconfigureerde blokken worden getoond. (Heeft een seizoen geen blokken geconfigureerd, dan valt de matrix terug op het hoogste blok dat in de data voorkomt.)
- **Cellen** = `<gepland-in-venster>/<selectiegrootte> · <uitgevoerd>/<gepland>` zodra het venster verstreken is.
- **Kleur** — groen als planning binnen het venster overeenkomt met de selectie; oranje bij gedeeltelijk; rood als het venster gesloten is zonder voldoende uitgevoerde gesprekken.

Klik op een cel om door te klikken naar de onderliggende POP-dossiers, gefilterd op team + blok.

## Rechten

- `tt_view_pdp` — zie het dashboard. Geldt voor coaches en HO.
- De tegel **POP-planning** is zichtbaar voor elke rol met `tt_view_pdp`; HO's zijn de primaire doelgroep.
