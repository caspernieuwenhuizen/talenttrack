<!-- audience: user, admin -->

# Persona-dashboards

Iedere gebruiker van de academie landt op een dashboard dat is afgestemd op zijn persona — Speler, Ouder, Hoofdtrainer, Assistent-trainer, Teammanager, Head of Development, Scout, Academiebeheerder, of Read-only waarnemer. Het dashboard beantwoordt in het eerste scherm de vraag die voor die gebruiker telt: *Waar sta ik nu? Wat is er nieuw? Wat is het volgende?*

Deze pagina beschrijft wat je per persona kunt verwachten, hoe je wisselt als je meerdere rollen hebt, en (voor academiebeheerders) hoe de standaardlay-out per persona is opgebouwd.

## Wat zie je per persona

| Persona | Hero | Belangrijkste paneel | Wat blijft staan |
| - | - | - | - |
| **Speler** | FIFA-achtige ratingcard met je overall + positie | Notitie van je trainer (als die er is) | Mijn reis, Mijn kaart, Mijn team, Mijn evaluaties, Mijn activiteiten, Mijn doelen, Mijn POP, Mijn profiel |
| **Ouder** | Kind-schakelaar + "sinds je laatste bezoek"-overzicht | POP wachtend op jouw akkoord | De kaart van mijn kind, evaluaties, activiteiten, POP |
| **Hoofdtrainer / Assistent-trainer** | Vandaag / Eerstvolgende met aanwezigheids- en evaluatie-knoppen | Werkstroomtaken + recente evaluaties-rail | Activiteiten, evaluaties, doelen, spelers, teams, POP, methodiek, mijn taken |
| **Teammanager** | Vandaag / Eerstvolgende | (standaard geen) | Activiteiten, mijn teams, spelers, mijn taken |
| **Head of Development** | KPI-strip (actieve spelers, evaluaties deze maand, opkomstpercentage, open trials, openstaande POP-verdicten, doel-voltooiingspercentage) | Trials die een besluit nodig hebben (tabel) | Trials, POP, spelers, methodiek, takendashboard, evaluaties, ratingcards, vergelijken |
| **Scout** | Toegewezen-spelers-grid (je primaire werksurface) | Recente rapporten | Mijn rapporten, mijn toegewezen spelers |
| **Academiebeheerder** | Systeemstatus-strip (back-up, uitnodigingen, licentie, modules) | Recente audit-gebeurtenissen (tabel) | Configuratie, autorisatie, gebruiksstatistieken, audit log, uitnodigingen, migraties, hulp, methodiek |
| **Read-only waarnemer** | KPI-strip in alleen-lezen-modus | (geen) | (geen bewerk-acties; alleen methodiek + KPI's) |

## Wisselen tussen persona's

Heb je meerdere rollen op de academie — bijvoorbeeld hoofdtrainer én ouder van een speler — dan verschijnt boven het dashboard een kleine **Bekijken als**-pillenbalk. Tik op een andere pil om de landingstemplate te wisselen. De keuze blijft bewaard tussen sessies; via dezelfde balk kun je altijd terug.

De pillenbalk verschijnt alleen als er meer dan één persona voor jouw account herleidbaar is. De meeste gebruikers zien één landingspagina en hoeven niet te wisselen.

## Wat is een "widget"?

Elk blok op het dashboard is een widget. Er zijn 14 widget-types: navigatietegel, KPI-kaart, KPI-strip, actie-kaart, snelle-acties-paneel, info-kaart, takenlijst-paneel, datatabel, mini-spelerslijst, ratingcard-hero, vandaag-eerstvolgende-hero, kind-schakelaar-met-overzicht, systeemstatus-strip en toegewezen-spelers-grid.

Widgets hebben vier formaten — Small, Medium, Large, Extra-large — en klikken vast op een 12-koloms grid op desktop, 6 koloms op tablet en één mobile-priority-gesorteerde kolom op mobiel.

## Een persona-dashboard aanpassen

Open *TalentTrack → Dashboard-lay-outs* in wp-admin. De pagina is afgeschermd met de capability `tt_edit_persona_templates` — standaard toegekend aan beheerders en academiebeheerders, optioneel aan Head of Development.

De editor heeft drie panelen:

- **Links — Palet.** Twee tabbladen: *Widgets* (de 14 widget-types) en *KPI's* (de 25 KPI's gegroepeerd per Academiebreed / Coach / Speler & ouder). Sleep een paletitem op het canvas, of focus het en druk op Enter.
- **Midden — Canvas.** Een 12-koloms bento-grid met een hero-band en een takenband erboven. Elke geplaatste widget toont label, databron, formaat-badge en een verwijder-knop (×). Klik een widget om hem te selecteren.
- **Rechts — Eigenschappen.** Wanneer een widget geselecteerd is kun je formaat (S/M/L/XL — alleen ondersteunde formaten zijn klikbaar), databron (KPI-keuzelijst voor KPI-kaarten, vrije tekst voor tegels), persona-label-overschrijving, mobiele prioriteit en mobiele zichtbaarheid aanpassen.

Werkbalk:

- **Persona-keuzelijst** — wisselt het canvas naar een andere persona-lay-out. Niet-opgeslagen wijzigingen vragen om bevestiging.
- **Ongedaan maken / Opnieuw** — tot 50 stappen. `Ctrl+Z` / `Ctrl+Shift+Z` werken ook.
- **Mobiele preview** — laat het canvas inklappen tot 360 px in prioriteitsvolgorde zodat je ziet hoe de lay-out stapelt op telefoon.
- **Terugzetten naar standaard** — vervangt de lay-out door de TalentTrack-standaard. Bevestiging vereist.
- **Concept opslaan** — bewaart je werk-in-uitvoering zonder live te gaan.
- **Publiceren** — zet de huidige lay-out live voor iedereen met deze persona, bij hun volgende paginalaad. Het bevestigingsvenster toont het aantal gebruikers dat geraakt wordt.

### Toetsenbord

De editor is volledig met toetsenbord te bedienen:

- Tab door paletitems, canvas-widgets en werkbalkknoppen.
- Op een canvas-widget: **spatie** om te pakken, **pijltjestoetsen** om te verplaatsen (Links/Rechts = 3 kolommen, Omhoog/Omlaag = 1 rij), **spatie** om los te laten. **Escape** annuleert.
- **Delete** of **Backspace** op een gefocuste widget verwijdert hem.
- Elke verplaatsing wordt aangekondigd in de live status-regio voor schermlezers.

### Audit

Elke opslag of publicatie schrijft een audit-log-regel (`persona_template_published`, `persona_template_draft`, of `persona_template_reset`) zodat je terug kunt vinden wie welke persona-lay-out heeft veranderd en wanneer.

### Wat de editor (nog) niet doet

- **Per gebruiker overschrijven.** Een gebruiker kan zijn eigen dashboard niet aanpassen — alleen academiebeheerders zetten de lay-out per persona.
- **Eigen KPI's schrijven.** De 25-KPI-catalogus is gesloten; je kunt er elke uitkiezen, maar geen nieuwe query schrijven.
- **Apart mobiel ontwerpen.** De mobiele preview is alleen-lezen — je stelt prioriteit en zichtbaarheid in op elke widget; de inklapvolgorde wordt daaruit berekend.

## Databronnen

Elke KPI wordt live berekend op basis van de data van jouw academie. KPI's die afhankelijk zijn van nog niet uitgerolde features (bijv. de status-traffic light uit `#0057`, POP-planningvensters uit `#0054`) tonen een placeholder-streepje (`—`) tot die landen.

## REST API

De geresolveerde lay-out voor elke persona wordt als JSON ontsloten voor toekomstige SaaS-clients:

```
GET    /wp-json/talenttrack/v1/personas/{slug}/template          lezen
PUT    /wp-json/talenttrack/v1/personas/{slug}/template          concept opslaan
DELETE /wp-json/talenttrack/v1/personas/{slug}/template          terugzetten naar standaard
POST   /wp-json/talenttrack/v1/personas/{slug}/template/publish  concept publiceren
POST   /wp-json/talenttrack/v1/me/active-persona                 actieve persona instellen
DELETE /wp-json/talenttrack/v1/me/active-persona                 actieve persona resetten
```

Een ingelogde gebruiker kan templates lezen voor persona's waarvoor hij in aanmerking komt; de write-endpoints vereisen `tt_edit_persona_templates`.

## Verder lezen

- Wisselen van rol in het gebruikersmenu: [Toegangsbeheer](?page=tt-docs&topic=access-control)
- Volledige tegelcatalogus: [Coachdashboard](?page=tt-docs&topic=coach-dashboard)
