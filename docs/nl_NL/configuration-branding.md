<!-- audience: admin -->

# Configuratie & branding

Op de pagina **Configuratie** leven de identiteit van je academie en de operationele knoppen van de plugin.

## Tegellanding (v3.28.0+)

Wanneer je **Configuratie** opent zonder een `?tab=`-parameter krijg je een tegelraster, gegroepeerd op onderwerp — Lookups & naslag­gegevens, Branding & weergave, Autorisatie, Systeem, Aangepaste gegevens en Spelers & bulkacties. Elke tegel opent ofwel een tab op deze pagina (de historische 14 lookup-, branding- en systeem­tabs) ofwel een bestaande top-level admin­pagina (Custom Fields, Evaluatie­categorieën, Autorisatie­matrix, Modules, etc.). Oude `?page=tt-config&tab=<slug>`-bladwijzers blijven werken.

De tabstrip bovenaan is verdwenen; gebruik vanuit elk tabblad de **← Configuratie**-link in de paginatitel om terug naar het tegelraster te gaan.

## Vormgeving-scherm (v4.26.13+)

Op de frontend-Configuratieweergave zijn de voormalige tegels **Branding** en **Thema & lettertypen** samengevoegd tot één **Vormgeving**-ingang — zo staan alle merkkleuren op één plek in plaats van verdeeld over twee tegels. Vormgeving openen toont één pagina met gestapelde secties:

- **Identiteit** — academienaam, clubafkorting, logo.
- **Kleuren** — primair, secundair en het volledige accent-/statuspalet (accent, gevaar, waarschuwing, succes, info, focusrand), allemaal bij elkaar.
- **Typografie** — display- en bodylettertype.
- **Thema-isolatie** — een informatieve melding dat TalentTrack altijd volledig geïsoleerd van het actieve WordPress-thema wordt weergegeven (alleen-lezen; er is geen schakelaar).
- **Geavanceerd** — een link naar de Aangepaste CSS-editor.

Er zijn geen configuratiesleutels gewijzigd en er is geen datamigratie — bestaande waarden worden ongewijzigd weergegeven. Opslaan + Annuleren staan onderaan de pagina. Oude `?config_sub=branding` / `?config_sub=theme`-deeplinks komen nog steeds uit bij het Vormgeving-scherm.

## Tegelweergave (v4.33.0+)

Het Vormgeving-scherm krijgt een keuzelijst **Tegelweergave** waarmee je de grootte en kolomdichtheid instelt van de tegels in de hele plugin — het dashboardtegelraster, Configuratie, de Rapporten-launcher en de tegels onder "Teamontwikkeling" bij Teams — op één plek, academy-breed.

| Voorinstelling | Effect |
| --- | --- |
| **Compact** | Compactere tegels, meer kolommen per rij — past meer op het scherm. |
| **Comfortabel** (standaard) | De standaard tegelgrootte van vóór deze release. |
| **Ruim** | Grotere, ruimere tegels met minder kolommen per rij. |

De instelling wordt academy-breed opgeslagen onder de configuratiesleutel `tile_appearance` en geldt voor alle tegeloppervlakken tegelijk — er zijn geen overrides per scherm. Alle voorinstellingen herschikken responsief: op een telefoon valt het raster terug naar één kolom, en Ruim veroorzaakt nooit horizontaal scrollen.

Eén gedeelde standaard bepaalt nu de grootte en lay-out van elke tegel (`TileGridStandard`), zodat de oppervlakken er onderling identiek uitzien, ongeacht de gekozen voorinstelling.

**Tegelschaal:** het oudere numerieke **Tegelschaal**-percentage (in te stellen op de wp-admin Configuratiepagina) blijft werken — het wordt als extra vermenigvuldiger bovenop de gekozen voorinstelling toegepast, zodat bestaande aanpassingen hun effect behouden. Als Tegelschaal op 100% blijft staan, bepaalt de voorinstelling alleen de tegelgrootte.

### Tegelindeling (v4.35.0+)

Naast de groottekeuze (nu **Tegelgrootte** genoemd) krijgt het Vormgeving-scherm een aparte **Tegelindeling**-keuzelijst. Indeling en grootte zijn onafhankelijke assen — elke combinatie is geldig (bijvoorbeeld Ruim + Gestapeld).

| Indeling | Effect |
| --- | --- |
| **Rij (icoon links van de titel)** (standaard) | Het icoon staat links, met de titel en beschrijving ernaast gestapeld. Dit is de indeling van vóór deze release — geen visuele wijziging bij het bijwerken. |
| **Gestapeld (icoon + titel, beschrijving eronder)** | Het icoon en de titel delen de eerste regel; de beschrijving beslaat de volledige tegelbreedte eronder. Het icoon is zo groot dat het ongeveer twee titelregels beslaat, zodat een lange titel naast het icoon naar een tweede regel loopt in plaats van de tegel breder te maken — de tegel houdt zijn standaardbreedte. |

De indeling geldt overal waar een tegel een icoon toont: het dashboardtegelraster altijd, en de tegels van Configuratie / Rapporten / Modules wanneer een tegel een icoon heeft. Tegels zonder icoon hebben geen eerste regel om te delen en zien er in beide indelingen hetzelfde uit. Wordt academy-breed opgeslagen onder de configuratiesleutel `tile_layout`; standaard `row`.

### Tegelkleurschema (v4.46.0+)

Naast **Tegelgrootte** en **Tegelindeling** krijgt het Vormgeving-scherm een keuzelijst **Tegelkleurschema**. Kleur is een derde onafhankelijke as — het verkleurt de dashboardtegels (rand, vulling en accent) zonder de grootte of indeling te wijzigen, dus elke combinatie is geldig. Het schema geldt voor het dashboardtegelraster (`.tt-ftile`).

| Schema | Effect |
| --- | --- |
| **Standaard** | Witte vulling, dunne grijze rand, een groen merkaccent van 3px aan de linkerkant en een vage groene tint bij hover. |
| **Merkrand** | Witte vulling met een volledige merkgroene rand van 1,5px. |
| **Gouden bovenrand** (standaard) | Een volledige merkgroene rand plus een gouden bovenrand van 3px, een echo van de gouden onderlijn van de dashboardbalk. |
| **Zachte groene vulling** | Een lichte merkgroene tintvulling met een groene rand; de icoonchip wordt wit. |
| **Effen groen** | Tegels komen overeen met de bovenbalk — donkergroene vulling, gouden onderaccent, witte tekst en een doorschijnende icoonchip. |
| **Linker accent** | Witte vulling met een dikke groene linkerrand van 4px die bij hover goud wordt. |

Wordt academy-breed opgeslagen onder de configuratiesleutel `tile_style`; standaard `gold-topped`. De kleuren komen uit de merktokens (`--tt-primary`, `--tt-secondary`), dus ze volgen automatisch de Primaire/Secundaire kleurkeuze van je academie.

## Volledig-canvas app & thema-isolatie (verplicht, v4.45.26+)

TalentTrack wordt altijd weergegeven als volledig-canvas app, volledig geïsoleerd van het actieve WordPress-thema. Er is **geen uitschakeloptie** — volledige isolatie is het uitgangspunt (#1728). Op de pagina waarop de shortcode `[talenttrack_dashboard]` staat:

- worden de kop, voettekst, zijbalk, menu's en widgets van het thema niet weergegeven (canvas-overname, sinds v4.34.0); en
- wordt **elke niet-TalentTrack-stylesheet verwijderd voordat de pagina wordt getekend**, zodat de `style.css` van het thema (en de CSS van andere plugins) het palet, de typografie of de indeling van TalentTrack niet kan overschrijven.

De WordPress-beheerbalk blijft zichtbaar voor ingelogde medewerkers (het is een WordPress-element, geen thema-omlijsting, en geeft met één klik toegang tot wp-admin), en de door de operator gekozen Google Fonts blijven laden. Al het andere van het thema wordt weggelaten.

Eerdere versies (v4.34.0–v4.45.25) boden een selectievakje **Volledig-canvas app** en een **Theme-overerving**-schakelaar waarmee een academy de styling aan het WP-thema kon overlaten. Beide zijn in v4.45.26 verwijderd omdat ze in strijd waren met volledige visuele onafhankelijkheid: een thema dat specificiteitsgevechten won, kon het palet vergiftigen. Pas TalentTrack aan via de secties **Kleuren**, **Typografie** en **Logo** van Vormgeving, of via **Aangepaste CSS** — niet via het thema.

Het canvas neemt alleen de pagina over waarop de shortcode `[talenttrack_dashboard]` staat; elke andere pagina op de site wordt zoals gebruikelijk via het thema weergegeven. Afdruk- en exportpagina's (wedstrijdvoorbereiding, PDP, methodiek) blijven ongemoeid — die worden al als losstaande documenten zonder omlijsting weergegeven.

## Configuratiesecties in de frontend (v4.26.16+)

De frontend-Configuratielandingspagina groepeert de tegels in doelgerichte secties in plaats van één plat raster; een sectie zonder zichtbare (toegestane) tegels toont geen kop:

- **Vormgeving** — het samengevoegde Vormgeving-scherm + Aangepaste CSS.
- **Dashboard** — Standaarddashboard, plus de via een filter aangedragen tegels Dashboard-indelingen / Aangepaste widgets.
- **Gegevens & vocabulaires** — Lookups, Beoordelingsschaal, Spelers-CSV-import, Lookup canonieke-taalcontrole.
- **Methodiek & cycli** — POP-cyclusblokken, Seizoenen, Methodiek spelerstatus en de VCT-configuratietegels.
- **Integraties** — Spond.
- **Systeem** — Algemeen, Functieschakelaars, Back-ups, Vertalingen, Auditlog, Setup-wizard, wp-admin-menu's, Modules.

Tegels die in wp-admin openen (Spond, Functieschakelaars, Back-ups, Vertalingen, Auditlog, Setup-wizard) hebben een externe-link-markering zodat de contextwissel verwacht is; de frontend-tegels niet. De weergave loopt via de gedeelde `FrontendSectionedTileGrid`.

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

## Gecureerde stijlen

*Toegevoegd in v3.8.0; theme-overerving verwijderd in v4.45.26.* Het Vormgeving-scherm laat een club het dashboard branden — lettertypen en een volledig semantisch kleurpalet — zonder CSS te schrijven. TalentTrack wordt altijd volledig geïsoleerd van het actieve WordPress-thema weergegeven (zie *Volledig-canvas app & thema-isolatie* hierboven), dus branding gebeurt volledig via deze velden (of via Aangepaste CSS), nooit door styling aan het thema over te laten.

### Display-lettertype / Body-lettertype

Twee dropdowns met gecureerde [Google Fonts](https://fonts.google.com/) families.

- **Display**-kandidaten zijn condensed / sportief (Oswald, Bebas Neue, Anton, Barlow Condensed…) — gebruikt voor koppen, tegeltitels en de nummers op spelerkaarten.
- **Body**-kandidaten zijn rustige sans-serifs plus enkele serifs (Inter, Manrope, DM Sans, Source Serif 4…) — gebruikt voor paragrafen, tabellen en formulierlabels.

Bovenaan elke dropdown staat één niet-Google-optie:

- **(Systeemstandaard)** — geen Google Fonts-aanvraag; valt terug op de standaard fontstack van TalentTrack.

Als minstens één dropdown een gecureerde familie kiest, laadt de plugin één gecombineerde Google Fonts-aanvraag (display + body samen, met de gewichten die TalentTrack daadwerkelijk gebruikt). Google Fonts is de enige externe stylesheet die de canvas-isolatie overleeft.

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

Een veld leeg laten herstelt de standaard­token uit de stylesheet van de plugin. De door de operator gekozen kleuren worden als `:root` custom properties geïnjecteerd en kunnen — doordat canvas-modus de CSS van het actieve thema verwijdert — door niets worden overschreven.

### Achterwaartse compatibiliteit

De bestaande velden **Primaire kleur** en **Secundaire kleur** blijven ongewijzigd werken. De nieuwe velden zijn additief — installaties die ze niet aanraken, zien geen visueel verschil.
