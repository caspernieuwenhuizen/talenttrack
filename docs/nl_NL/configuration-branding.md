---
title: Configuratie & branding
group: configuration
summary: Academie-naam, logo, beoordelingsschaal, kleurenpalet, referentietabellen.
audience: [admin]
views: [configuration]
capability: tt_view_branding
order: 20
---

# Configuratie & branding

Op de pagina **Configuratie** leven de identiteit van je academie en de operationele knoppen van de plugin.

## Tegellanding

Wanneer je **Configuratie** opent zonder een `?tab=`-parameter krijg je een tegelraster, gegroepeerd op onderwerp — Keuzelijsten & naslag­gegevens, Branding & weergave, Autorisatie, Systeem, Aangepaste gegevens en Spelers & bulkacties. Elke tegel opent ofwel een tab op deze pagina (de historische 14 keuzelijst-, branding- en systeem­tabs) ofwel een bestaande top-level admin­pagina (Custom Fields, Evaluatie­categorieën, Autorisatie­matrix, Modules, etc.). Oude `?page=tt-config&tab=<slug>`-bladwijzers blijven werken.

De tabstrip bovenaan is verdwenen; gebruik vanuit elk tabblad de **← Configuratie**-link in de paginatitel om terug naar het tegelraster te gaan.

## Vormgeving-scherm

Op de frontend-Configuratieweergave zijn de voormalige tegels **Branding** en **Thema & lettertypen** samengevoegd tot één **Vormgeving**-ingang — zo staan alle merkkleuren op één plek in plaats van verdeeld over twee tegels. Vormgeving openen toont één pagina met gestapelde secties:

- **Identiteit** — academienaam, clubafkorting, logo.
- **Kleuren** — primair, secundair en het volledige accent-/statuspalet (accent, gevaar, waarschuwing, succes, info, focusrand), allemaal bij elkaar.
- **Typografie** — display- en bodylettertype.
- **Thema-isolatie** — een informatieve melding dat TalentTrack altijd volledig geïsoleerd van het actieve WordPress-thema wordt weergegeven (alleen-lezen; er is geen schakelaar).
- **Geavanceerd** — een link naar de Aangepaste CSS-editor.

Er zijn geen configuratiesleutels gewijzigd en er is geen datamigratie — bestaande waarden worden ongewijzigd weergegeven. Opslaan + Annuleren staan onderaan de pagina. Oude `?config_sub=branding` / `?config_sub=theme`-deeplinks komen nog steeds uit bij het Vormgeving-scherm.

## Tegelweergave

Het Vormgeving-scherm krijgt een keuzelijst **Tegelweergave** waarmee je de grootte en kolomdichtheid instelt van de tegels in de hele plugin — het dashboardtegelraster, Configuratie, de Rapporten-launcher en de tegels onder "Teamontwikkeling" bij Teams — op één plek, academy-breed.

| Voorinstelling | Effect |
| --- | --- |
| **Compact** | Compactere tegels, meer kolommen per rij — past meer op het scherm. |
| **Comfortabel** (standaard) | De standaard tegelgrootte van vóór deze release. |
| **Ruim** | Grotere, ruimere tegels met minder kolommen per rij. |

De instelling wordt academy-breed opgeslagen onder de configuratiesleutel `tile_appearance` en geldt voor alle tegeloppervlakken tegelijk — er zijn geen overrides per scherm. Alle voorinstellingen herschikken responsief: op een telefoon valt het raster terug naar één kolom, en Ruim veroorzaakt nooit horizontaal scrollen.

Eén gedeelde standaard bepaalt nu de grootte en lay-out van elke tegel (`TileGridStandard`), zodat de oppervlakken er onderling identiek uitzien, ongeacht de gekozen voorinstelling.

**Tegelschaal:** het oudere numerieke **Tegelschaal**-percentage (in te stellen op de wp-admin Configuratiepagina) blijft werken — het wordt als extra vermenigvuldiger bovenop de gekozen voorinstelling toegepast, zodat bestaande aanpassingen hun effect behouden. Als Tegelschaal op 100% blijft staan, bepaalt de voorinstelling alleen de tegelgrootte.

### Tegelindeling

Naast de groottekeuze (nu **Tegelgrootte** genoemd) krijgt het Vormgeving-scherm een aparte **Tegelindeling**-keuzelijst. Indeling en grootte zijn onafhankelijke assen — elke combinatie is geldig (bijvoorbeeld Ruim + Gestapeld).

| Indeling | Effect |
| --- | --- |
| **Rij (icoon links van de titel)** (standaard) | Het icoon staat links, met de titel en beschrijving ernaast gestapeld. Dit is de indeling van vóór deze release — geen visuele wijziging bij het bijwerken. |
| **Gestapeld (icoon + titel, beschrijving eronder)** | Het icoon en de titel delen de eerste regel; de beschrijving beslaat de volledige tegelbreedte eronder. Het icoon is zo groot dat het ongeveer twee titelregels beslaat, zodat een lange titel naast het icoon naar een tweede regel loopt in plaats van de tegel breder te maken — de tegel houdt zijn standaardbreedte. |

De indeling geldt overal waar een tegel een icoon toont: het dashboardtegelraster altijd, en de tegels van Configuratie / Rapporten / Modules wanneer een tegel een icoon heeft. Tegels zonder icoon hebben geen eerste regel om te delen en zien er in beide indelingen hetzelfde uit. Wordt academy-breed opgeslagen onder de configuratiesleutel `tile_layout`; standaard `row`.

### Tegelkleurschema

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

## Volledig-canvas app & thema-isolatie (verplicht,)

TalentTrack wordt altijd weergegeven als volledig-canvas app, volledig geïsoleerd van het actieve WordPress-thema. Er is **geen uitschakeloptie** — volledige isolatie is het uitgangspunt. Op de pagina waarop de shortcode `[talenttrack_dashboard]` staat:

- worden de kop, voettekst, zijbalk, menu's en widgets van het thema niet weergegeven (canvas-overname, sinds v4.34.0); en
- wordt **elke niet-TalentTrack-stylesheet verwijderd voordat de pagina wordt getekend**, zodat de `style.css` van het thema (en de CSS van andere plugins) het palet, de typografie of de indeling van TalentTrack niet kan overschrijven.

De WordPress-beheerbalk blijft zichtbaar voor ingelogde medewerkers (het is een WordPress-element, geen thema-omlijsting, en geeft met één klik toegang tot wp-admin), en de door de operator gekozen Google Fonts blijven laden. Al het andere van het thema wordt weggelaten.

Eerdere versies (v4.34.0–v4.45.25) boden een selectievakje **Volledig-canvas app** en een **Theme-overerving**-schakelaar waarmee een academy de styling aan het WP-thema kon overlaten. Beide zijn in v4.45.26 verwijderd omdat ze in strijd waren met volledige visuele onafhankelijkheid: een thema dat specificiteitsgevechten won, kon het palet vergiftigen. Pas TalentTrack aan via de secties **Kleuren**, **Typografie** en **Logo** van Vormgeving, of via **Aangepaste CSS** — niet via het thema.

Het canvas neemt alleen de pagina over waarop de shortcode `[talenttrack_dashboard]` staat; elke andere pagina op de site wordt zoals gebruikelijk via het thema weergegeven. Afdruk- en exportpagina's (wedstrijdvoorbereiding, PDP, methodiek) blijven ongemoeid — die worden al als losstaande documenten zonder omlijsting weergegeven.

## Configuratiesecties in de frontend

Configuratie groepeert de instellingen **naar de taak die je voor ogen hebt**, niet naar de module die ze toevallig beheert. Er zijn tien secties, altijd in deze volgorde, en elke sectie begint met de vraag die ze beantwoordt:

| Sectie | De vraag | Wat erin staat |
| --- | --- | --- |
| Academie | Hoe heet de academie, hoe ziet ze eruit en hoe loopt het seizoen? | Algemeen, Uiterlijk, Eigen CSS, Seizoenen, Vakanties, Seizoensovergang, Standaarddashboard, Dashboardindelingen, Eigen widgets, Installatie |
| Spelers & teams | Welke gegevens houden we bij per speler en team? | Eigen velden, Profielkaarten, Methodiek spelersstatus, Functionele rollen, Stagetrajecten, Briefsjablonen, Spelers importeren (CSV) |
| Ontwikkeling & evaluaties | Hoe beoordelen en ontwikkelen we spelers? | Evaluatiecategorieën, Categoriegewichten, Beoordelingsschaal, POP-cyclusblokken, POP-voorbereidingsvragen, Tests beheren, Chemie-instellingen, Methodiek-vocabulaire |
| Activiteiten & wedstrijden | Hoe plannen en registreren we trainingen en wedstrijden? | Wedstrijdminuten, VCT-configuratie, Spond, Strava |
| Keuzelijsten | Welke waarden staan in de lijsten? | Alle keuzelijsten, Aanwezigheidsstatussen, Voetbalvorm, Taalcontrole keuzelijsten |
| Mensen & toegang | Wie mag wat zien en doen? | Accounts & toegang, Uitnodigingen, Toegangsmatrix, Tweestapsverificatie verplichten |
| Berichten & taken | Wat sturen we naar wie, en welke taken lopen automatisch? | Berichten, Workflow-sjablonen, Vertalingen |
| Gegevens | Hoe halen we gegevens binnen en houden we ze veilig? | Back-ups, Configuratie exporteren, Prullenbak, Importgeschiedenis, Mediabewaring |
| Functies & pakket | Wat staat aan, en wat zit in ons pakket? | Modules, Functies, Wizards, Pakket en beperkingen |
| Systeem | Voor de beheerder: logboeken en techniek. | Auditlog, Berichtenlogboek, Databrowser, Migraties, wp-admin-menu's, wp-admin openen |

De regels die het overzichtelijk houden:

- **Elke instelling staat er één keer.** Er is één toegangsmatrix, en Beoordelingsschaal en Voetbalvorm hebben elk één plek. Een inrichtingsscherm staat er niet nog een keer omdat het ook een tegel is.
- **Je ziet alleen wat je mag openen.** De sectie Systeem is alleen voor beheerders van de academie, net als de toegangsmatrix; een technisch directeur ziet geen van beide.
- **Een kleine sectie schuift op naar de volgende.** Als je in een sectie minder dan drie onderdelen mag openen, komen die bij de sectie erna, zodat niemand een sectie met één kaart ziet.
- **Uitgeschakelde modules blijven vindbaar voor een beheerder.** Hun instellingen staan onder Functies & pakket, gemarkeerd als *uit*, en leiden naar de pagina Modules waar je de module weer aanzet. Anderen zien ze niet.

Het vak **Zoek een instelling** bovenaan filtert terwijl je typt. Het zoekt in de naam, de beschrijving en een lijst extra woorden per instelling, zonder op hoofdletters of accenten te letten. *logo* vindt Uiterlijk, *weging* (*weighting*) vindt Categoriegewichten, *spond* vindt Spond en *2fa* vindt de verplichte tweestapsverificatie. Als niets overeenkomt, staat dat op de pagina. Escape maakt het vak leeg.

**Tweestapsverificatie verplichten** (Mensen & toegang) opent in de app. Vink de rollen aan die tweestapsverificatie moeten instellen en kies Opslaan, of Annuleren om terug te gaan naar Configuratie. Bij elke rol staat hoeveel accounts het nog niet hebben ingesteld, als aantal en nooit met namen, zodat je ziet hoeveel mensen een wijziging raakt voordat je opslaat. Je hebt het academiebrede recht nodig om instellingen te wijzigen, en elke wijziging komt in het auditlog. Andermans tweede factor herstellen na een blokkade blijft in wp-admin.

Onderdelen die in wp-admin openen hebben een externe-link-markering zodat de contextwissel verwacht is; frontend-onderdelen niet.

*Ontwikkelaars:* de onderdelen staan in `config/settings_entries.php` en worden gelezen door `\TT\Shared\Configuration\SettingsRegistry`, dat de startpagina, `GET /talenttrack/v1/settings-entries` (zie de REST API-referentie) en zoeken allemaal gebruiken. Elk onderdeel voor een scherm in deze repository staat in dat bestand, omdat `tools/check-settings-entries.php` alleen het bestand leest; `SettingsRegistry::register()` is voor code buiten deze repository, zoals een add-on-plugin. Onderdelen die via de filter `tt_config_tile_groups` worden aangeleverd verschijnen nog steeds, onder Academie, tenzij een onderdeel al naar hetzelfde scherm leidt; de filter is verouderd. `tools/check-settings-entries.php` laat de build falen als een `?config_sub=`-formulier, een inrichtingstegel in een instellingssectie of een instellingsscherm van de module Configuratie geen onderdeel heeft, tenzij `not_entries` uitlegt waarom.

## Tabbladen

### Algemeen

- **Naam academie** — gebruikt door de hele plugin heen, in printbare rapporten en op het briefhoofd van elke stagebrief. Laat je het leeg, dan vallen die schermen terug op de sitetitel van WordPress, en die benoemt de installatie in plaats van de academie
- **Logo-URL** — getoond in de header van het frontend-dashboard en de printuitvoer
- **Primaire kleur** — tegelaccenten, grafieklijnen, kerncijfers
- **Maximum beoordelingsschaal** — standaard 5; je kunt overschakelen naar 10 als je coaches liever een 1–10-schaal gebruiken

### Keuzelijsten

Elk keuzelijsttabblad (Positie, Leeftijdscategorie, Voet-optie, Doelstatus, Doelprioriteit, Aanwezigheidsstatus) is een eenvoudige lijst die je kunt bewerken, slepen om te herordenen en uitbreiden met nieuwe items.

**Vertalingen** — elk bewerkformulier van een keuzelijstwaarde heeft een blok Vertalingen met één rij per geïnstalleerde sitetaal. Vul de vertaalde Naam (en optioneel de omschrijving) in om te bepalen wat je Nederlandse gebruikers zien, zonder een plug-in-update uit te brengen. Laat een regel leeg om terug te vallen op de canonieke Naam en een eventuele vertaling die de plug-in in zijn `.po`-bestand meelevert. Eigen toevoegingen (bijv. een aangepaste positie "Keeper-Libero") kun je nu vertalen zonder de code aan te passen.

### Evaluatietypes

Verschillende smaken evaluatie: Training, Wedstrijd, Toernooi. Wedstrijdtypes kunnen gemarkeerd worden als "Vereist wedstrijddetails", waarna coaches tegenstander, competitie, uitslag, thuis/uit en gespeelde minuten moeten invullen bij het maken van een wedstrijdevaluatie.

### Toggles

Feature-toggles voor zaken als de printmodule, bepaalde frontend-secties, audit trail. Zet ze aan/uit naar behoefte.

### Audit

Een alleen-lezen logboek van configuratiewijzigingen voor verantwoording.

## Slepen om te herordenen

Keuzelijsten ondersteunen slepen-om-te-herordenen (v2.19.0). Pak de greep ⋮⋮ op een rij en sleep. De volgorde wordt automatisch opgeslagen en meteen overal in de plugin in de dropdowns verwerkt.

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
| Focus-ring­kleur | `--tt-focus-ring`, `--tt-focus-color` | De rand rond het actieve veld of de knop als iemand het toetsenbord gebruikt |

Een veld leeg laten herstelt de standaard­token uit de stylesheet van de plugin. De door de operator gekozen kleuren worden aan de ontwerptokens van de plugin op de pagina toegevoegd en kunnen — doordat canvas-modus de CSS van het actieve thema verwijdert — door niets worden overschreven.

**Alleen de kleuren die je wijzigt, worden opgeslagen.** Bij het opslaan bewaart het formulier een kleur alleen als die afwijkt van de kleur die het veld toont als er niets is gekozen; een kleur die op die standaard blijft staan, wordt opgeslagen als niet ingesteld en volgt dan de kleur van het ontwerp zelf. Bij elke kleur staat een knop **Terug naar standaard** die de standaardkleur terugzet in het veld; sla het formulier op om de kleur te wissen. Een gevaar-, waarschuwings-, succes- of infokleur die op zwart staat, telt ook als niet ingesteld, zodat meldingen nooit per ongeluk zwart worden. De accentkleur is de uitzondering: het standaardblauw is het accent dat het dashboard toont, dus die wordt bewaard zoals hij is.

**Focus-ringkleur.** Zonder focuskleur volgt de focusrand van het toetsenbord de primaire kleur. Het blauw dat het veld toont voordat je iets kiest (`#1e88e5`), telt als geen focuskleur. Een focuskleur die je instelt, neemt die plaats in, zolang hij goed afsteekt tegen de pagina (een contrast van minstens 3 op 1). Een vagere kleur wordt wel opgeslagen, maar het formulier waarschuwt dat hij slecht zichtbaar is als focusrand, en de rand blijft de primaire kleur volgen, zodat wie het toetsenbord gebruikt altijd ziet waar hij is. Onder een visueel thema bepaalt het thema de rand.

### Achterwaartse compatibiliteit

De bestaande velden **Primaire kleur** en **Secundaire kleur** blijven ongewijzigd werken. De nieuwe velden zijn additief — installaties die ze niet aanraken, zien geen visueel verschil.
