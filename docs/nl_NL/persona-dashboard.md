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

De drag-and-drop-editor voor academiebeheerders volgt in **Sprint 2** van deze epic. Sprint 1 levert de catalogus + de zeven standaardtemplates.

Zodra de editor er is, kan een beheerder:
- Widgets binnen een persona herordenen.
- Widgets vergroten of verkleinen tussen Small / Medium / Large / Extra-large.
- Een KPI-kaart toevoegen uit de catalogus van 25 KPI's.
- Een widget verbergen die niet bij jouw academie past.
- Het label van een tegel overschrijven (bijv. "My card" → "Mijn pas").
- Een persona terugzetten naar de standaardlay-out.

Tot dat moment krijgt iedere academie de standaardtemplates.

## Databronnen

Elke KPI wordt live berekend op basis van de data van jouw academie. KPI's die afhankelijk zijn van nog niet uitgerolde features (bijv. de status-traffic light uit `#0057`, POP-planningvensters uit `#0054`) tonen een placeholder-streepje (`—`) tot die landen.

## REST API

De geresolveerde lay-out voor elke persona wordt als JSON ontsloten voor toekomstige SaaS-clients:

```
GET /wp-json/talenttrack/v1/personas/{slug}/template
```

Een ingelogde gebruiker kan templates lezen voor persona's waarvoor hij in aanmerking komt; gebruikers met de capability `tt_edit_persona_templates` kunnen elke template lezen (gebruikt door de Preview-as-persona-functie van de editor).

## Verder lezen

- Wisselen van rol in het gebruikersmenu: [Toegangsbeheer](?page=tt-docs&topic=access-control)
- Volledige tegelcatalogus: [Coachdashboard](?page=tt-docs&topic=coach-dashboard)
