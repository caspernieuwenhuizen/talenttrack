<!-- audience: admin -->

# Configuratie & branding

Op de pagina **Configuratie** leven de identiteit van je academie en de operationele knoppen van de plugin.

## Tegellanding (v3.28.0+)

Wanneer je **Configuratie** opent zonder een `?tab=`-parameter krijg je een tegelraster, gegroepeerd op onderwerp — Lookups & naslag­gegevens, Branding & weergave, Autorisatie, Systeem, Aangepaste gegevens en Spelers & bulkacties. Elke tegel opent ofwel een tab op deze pagina (de historische 14 lookup-, branding- en systeem­tabs) ofwel een bestaande top-level admin­pagina (Custom Fields, Evaluatie­categorieën, Autorisatie­matrix, Modules, etc.). Oude `?page=tt-config&tab=<slug>`-bladwijzers blijven werken.

De tabstrip bovenaan is verdwenen; gebruik vanuit elk tabblad de **← Configuratie**-link in de paginatitel om terug naar het tegelraster te gaan.

## Tabbladen

### Algemeen

- **Naam academie** — gebruikt door de hele plugin heen en in printbare rapporten
- **Logo-URL** — getoond in de header van het frontend-dashboard en de printuitvoer
- **Primaire kleur** — tegelaccenten, grafieklijnen, kerncijfers
- **Maximum beoordelingsschaal** — standaard 5; je kunt overschakelen naar 10 als je coaches liever een 1–10-schaal gebruiken

### Lookups

Elk lookup-tabblad (Positie, Leeftijdscategorie, Voet-optie, Doelstatus, Doelprioriteit, Aanwezigheidsstatus) is een eenvoudige lijst die je kunt bewerken, slepen om te herordenen en uitbreiden met nieuwe items.

**Vertalingen** — elk bewerkformulier van een lookup heeft een blok Vertalingen met één rij per geïnstalleerde sitetaal. Vul de vertaalde Naam (en optioneel de omschrijving) in om te bepalen wat je Nederlandse gebruikers zien, zonder een plug-in-update uit te brengen. Laat een regel leeg om terug te vallen op de canonieke Naam en een eventuele vertaling die de plug-in in zijn `.po`-bestand meelevert. Eigen toevoegingen (bijv. een aangepaste positie "Keeper-Libero") kun je nu vertalen zonder de code aan te passen.

### Evaluatietypes

Verschillende smaken evaluatie: Training, Wedstrijd, Toernooi. Wedstrijdtypes kunnen gemarkeerd worden als "Vereist wedstrijddetails", waarna coaches tegenstander, competitie, uitslag, thuis/uit en gespeelde minuten moeten invullen bij het maken van een wedstrijdevaluatie.

### Toggles

Feature-toggles voor zaken als de printmodule, bepaalde frontend-secties, audit trail. Zet ze aan/uit naar behoefte.

### Audit

Een alleen-lezen logboek van configuratiewijzigingen voor verantwoording.

## Slepen om te herordenen

Lookup-lijsten ondersteunen slepen-om-te-herordenen (v2.19.0). Pak de greep ⋮⋮ op een rij en sleep. De volgorde wordt automatisch opgeslagen en meteen overal in de plugin in de dropdowns verwerkt.

## Theme-overerving & gecureerde stijlen

*Toegevoegd in v3.8.0.* Het tabblad Branding heeft een tweede sectie waarmee het dashboard kan aansluiten op het bestaande WordPress-thema van een club, zonder CSS te schrijven of een eigen thema te bouwen.

### WP-themastijlen overerven (toggle)

Wanneer AAN, laat het dashboard vier dingen over aan het omliggende WP-thema:

- Lettertypen voor body en koppen
- **Link**-kleur
- Kleur van **koppen**
- Standaard submit-/primaire **knopstijl**

Wanneer UIT gebruikt het dashboard de eigen TalentTrack-standaarden — net als vóór deze versie.

Wat de toggle **niet** raakt (bewust):

- Tier-styling van speler­kaarten (goud / zilver / brons blijft vastgezet — onderdeel van de productidentiteit)
- Randen en accenten van het tegelraster op het dashboard
- De `FrontendListTable`-component
- Witruimte, layout, structurele CSS

Eigenschappen die hierboven niet staan, "cascaden" doorgaans niet vanzelf in CSS (achtergrondkleuren, padding, randen) — de structurele CSS van de plugin houdt die met opzet consistent.

### Display-lettertype / Body-lettertype

Twee dropdowns met gecureerde [Google Fonts](https://fonts.google.com/) families.

- **Display**-kandidaten zijn condensed / sportief (Oswald, Bebas Neue, Anton, Barlow Condensed…) — gebruikt voor koppen, tegeltitels en de nummers op spelerkaarten.
- **Body**-kandidaten zijn rustige sans-serifs plus enkele serifs (Inter, Manrope, DM Sans, Source Serif 4…) — gebruikt voor paragrafen, tabellen en formulierlabels.

Bovenaan elke dropdown staan twee niet-Google-opties:

- **(Systeemstandaard)** — geen Google Fonts-aanvraag; valt terug op de standaard fontstack van TalentTrack.
- **(Overnemen van thema)** — alleen relevant wanneer de inherit-toggle hierboven AAN staat; anders gedraagt het zich als Systeemstandaard.

Als minstens één dropdown een gecureerde familie kiest, laadt de plugin één gecombineerde Google Fonts-aanvraag (display + body samen, met de gewichten die TalentTrack daadwerkelijk gebruikt).

### Kleurkiezers

Zes semantische kleuren, elk gekoppeld aan een `--tt-*` CSS custom property die door het hele dashboard heen gebruikt wordt:

| Veld | Token | Gebruikt voor |
| --- | --- | --- |
| Accentkleur | `--tt-accent` | Highlights, grafieken |
| Gevarenkleur | `--tt-danger` | Verwijderknoppen, fout-banners, validatiestatussen |
| Waarschuwingskleur | `--tt-warning` | Waarschuwings­banners, "Gedeeltelijk"-aanwezigheidspillen |
| Succes­kleur | `--tt-success` | Succes-banners, "Opgeslagen"-feedback, voltooide statussen |
| Info­kleur | `--tt-info` | Info-banners |
| Focus-ring­kleur | `--tt-focus-ring` | Toetsenbord­focus-omtrek |

Een veld leeg laten herstelt de standaard­token uit de stylesheet van de plugin.

### Eerlijk kader — wat "overerven" eigenlijk doet

Sommige CSS-eigenschappen erven van nature mee (font-family, color, link-kleur). Andere niet (background, padding, border-radius). Effect van de toggle:

- **Typografie**: volledige overerving.
- **Link-kleur**: volledige overerving.
- **Kop-kleur en -familie**: volledige overerving.
- **Knoppen**: best-effort. De knop-achtergrond en -kleur van de plugin worden teruggezet, maar de knopstijl van het host-thema neemt het pas over als zijn CSS dezelfde selectors raakt als de DOM van de plugin. De meeste thema's stijlen block-editor-knoppen (`.wp-block-button__link`) — die maken plugin-knoppen niet automatisch opnieuw op. Thema's die op het `<button>`-element zelf stijlen, krijgen volledige overerving.
- **Witruimte, randen, schaduwen**: niet overgeërfd — de structurele CSS van de plugin blijft staan.

Heb je een eigen thema dat `body .tt-dashboard { ... }`-overrides toevoegt (de child-thema-aanpak), dan blijven die het laatste woord houden — de toggle is de makkelijke route, maar de override-route blijft werken.

### Achterwaartse compatibiliteit

De bestaande velden **Primaire kleur** en **Secundaire kleur** blijven ongewijzigd werken. De nieuwe velden zijn additief — installaties die ze niet aanraken, zien geen visueel verschil.
