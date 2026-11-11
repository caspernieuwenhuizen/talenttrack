<!-- audience: admin, developer -->

# Visueel thema

De kleuren, hoeken en kopletter van TalentTrack zijn een instelling. De
academie kiest een standaard en iedereen kan zijn eigen keuze maken — dezelfde
opzet als bij de [navigatie-indeling](frontend-shell.md).

Een thema verandert **alleen het uiterlijk**. Het verandert nooit wat iemand
kan zien of doen: er verschijnt of verdwijnt geen recht, geen veld en geen
knop door een thema.

## De thema's

**Standaard** — het groen en goud dat TalentTrack altijd heeft gehad. Dit is
de standaard en blijft onbeperkt beschikbaar.

**Federation** — een marineblauwe chroom met een gouden markering op de
sectie waar je bent. Strakkere hoeken (4px in plaats van 8px), een smalle
kopletter en een marineblauw getinte diepte, zodat schaduwen bij het palet
horen in plaats van er als grijze waas overheen te liggen.

**Leon Hutten** — het ontwerp van Leon Hutten Talenten Academie, gebouwd op
het clubembleem. De zwarte band uit het embleem wordt de kopbalk en de
navigatiekolom; de diagonale streep van cyaan naar marineblauw sluit de
kopbalk en het merkblok af. Knoppen en links krijgen het diepe blauw, cyaan
markeert de sectie waar je bent, en het hele thema staat in Open Sans. De
hoeken zijn bijna haaks (3px).

**Clubrood** — traditioneel clubrood, zwart en gedempt goud, met Barlow
Condensed voor de koppen. Klassieke shirtkleuren; de smalle koppen lezen als
een wedstrijdprogramma.

**Oranje** — marineblauw en Hollands oranje, in Archivo. De energie van het
nationale elftal, met het accent met het meeste contrast van de reeks.

**Tactiekbord** — leisteengrijs en krijtmint, in IBM Plex Sans. Koel en
analytisch; past bij academies die werken vanuit data en methodiek.

**Bordeaux** — wijnrood en zand, met Source Serif 4 voor de koppen en Source
Sans 3 voor de rest. Redactioneel en rustig; de schreefkoppen geven rapporten
een gedrukt gevoel.

**Polder** — diep petrol en koraal, in DM Sans. Warm en toegankelijk; past goed
bij jongere leeftijdsgroepen en bij wat ouders zien.

In deze thema's krijgen de kopbalk en de navigatiekolom de diepste kleur van
het thema, en markeert het accent de sectie waar je bent. Waar het accent te
licht is om als tekst op wit te lezen, staan woorden in die kleur in een
donkerdere tint ervan.

### De letters zitten in de plugin

De letters die deze thema's gebruiken, zitten in de plugin zelf. Een pagina
vraagt ze nooit op bij Google Fonts of een andere letterdienst, en een letter
wordt alleen gedownload zolang zijn thema aanstaat. Tot hij binnen is, staat de
tekst in een vergelijkbare systeemletter, zodat niets erop hoeft te wachten.
Leon Hutten is de enige uitzondering: dat thema laadt Open Sans nog via Google
Fonts.

## Een thema vervangt je kleurinstellingen

Zolang een thema actief is levert het het **complete** kleurenschema — de
merkbalk, knoppen, links, statuskleuren en de kopletter. De kleur- en
lettervelden onder Configuratie → Uiterlijk hebben geen effect totdat het
thema weer op Standaard staat. Het paneel Kleuren meldt dat wanneer er een
thema aanstaat.

Dat is bewust. Eén clubkleur doorlaten leverde precies het probleem op dat het
moest voorkomen: een groene merkbalk boven een marineblauwe zijbalk, met twee
paletten die om hetzelfde scherm vechten.

**Je identiteit blijft zichtbaar.** Je logo en academienaam zijn opmaak, geen
kleuren, en verschijnen in elk thema. Zet je het thema terug op Standaard, dan
staan je kleuren er weer precies zoals je ze had — er wordt niets
overschreven, dus je hoeft niets opnieuw in te vullen.

### Eigen CSS blijft wel gelden

Heb je regels geschreven onder Configuratie → Eigen CSS, dan gelden die nog
steeds bovenop een thema. Dat is een uitweg die je zelf met de hand hebt
gekozen, dus een thema gooit hem niet stilzwijgend weg — maar het is ook de
plek waar de vormgeving van een thema kan breken, dus kijk daar als een thema
er niet uitziet zoals je verwacht.

## Een thema kiezen

**Voor de hele academie** — Configuratie → Uiterlijk → *Visueel thema*.
Iedereen die zelf niets heeft gekozen volgt deze instelling.

**Voor jezelf** — Mijn instellingen → *Thema*. Kies een thema om het vast te
zetten, of *Gebruik de academiestandaard* om de academie weer te volgen — ook
wanneer een beheerder die later wijzigt.

Wijzigingen zijn zichtbaar bij de volgende paginalading.

## Terugdraaien

Het thema op **Standaard** zetten is een volledige terugdraaiing. Het
stijlblad van het thema wordt dan helemaal niet geladen en er komt geen
themaklasse in de pagina, dus elk scherm rendert precies zoals vóór het thema
bestond. Er is niets op te ruimen en niets te migreren.

---

## Voor ontwikkelaars

`ThemePreference` (`src/Shared/Frontend/ThemePreference.php`) bepaalt het
thema, volgens hetzelfde patroon als `ShellPreference`:

| Onderwerp | Waar |
| --- | --- |
| Academiestandaard | `tt_config`-sleutel `tt_frontend_theme` (club-gebonden) |
| Persoonlijke voorkeur | gebruikersmeta `tt_frontend_theme`; `inherit` volgt de club |
| Volgorde | voorkeur → academiestandaard → `default` |
| Wortelklasse | `ThemePreference::rootClass()`, `''` bij het standaardthema |
| Stijlblad | `ThemePreference::styleFile()`, `''` bij het standaardthema |

Lees altijd via de resolver, nooit rechtstreeks de configuratiesleutel — dat
ene knooppunt maakt een latere SaaS-migratie één vervanging in plaats van een
zoektocht door alle views.

### Hoe een thema is opgebouwd

Een thema is een **tokenlaag**, geen tweede set schermen:

1. `assets/css/tokens.css` declareert alle standaardtokens op `.tt-root`: een
   omhullend element dat het dashboard direct om `.tt-dashboard` heen zet, en
   de body-klasse. Dat element is er altijd, dus de tokens werken wat het
   WordPress-thema ook doet en of de module voor eigen CSS nu aan staat of
   niet.
2. Het themablad declareert een deel daarvan opnieuw op `.tt-dashboard`, een
   dichterbij liggende voorouder, zodat elk vlak binnen het dashboard de
   themawaarde erft zonder één regel per view.
3. Wat een token niet kan uitdrukken — de marineblauwe balk van de app-shell,
   die niet uit een andere `--tt-paper` kan komen zonder elke kaart donker te
   maken — is een klein aantal expliciete regels op de bestaande klassen van
   de shell.

### Volgorde van de lagen

Er zijn drie ontwerplagen, en op een pagina gelden er hoogstens twee:

1. **`tokens.css`**: de ene set standaardwaarden, op `.tt-root`. Elk token dat
   het product leest staat hier, ook de merkkleuren en de lettertypen.
2. **Een thema**, als er een gekozen is. Het bepaalt het hele uiterlijk en
   declareert zijn tokens opnieuw op `.tt-dashboard`. Onder een thema wordt
   de Huisstijl niet uitgestuurd.
3. **De Huisstijl**, alleen op het standaardontwerp. De kleuren en
   lettertypen die de academie koos, hangen aan `tokens.css` zelf
   (`BrandStyles::attachVars()`), als een `.tt-root`-regel direct na de
   standaardwaarden, dus ze winnen op volgorde. Een kleur telt alleen als
   gekozen wanneer hij afwijkt van de standaardwaarde in het
   Huisstijl-formulier; een opgeslagen `#000000` voor een statuskleur (gevaar,
   waarschuwing, succes, info) telt als niet gekozen. Een kleur die niet
   gekozen is, houdt zijn waarde uit `tokens.css`.

Daarbovenop komt de **eigen CSS** van de academie (de module voor eigen CSS
en de visuele editor daarvan). Die schrijft eigen `.tt-root { --tt-…: … }`-regels
en heeft bedoeld het laatste woord.

Geen enkel stylesheet van een scherm declareert eigen tokens.
`frontend-admin.css` declareerde vroeger een tweede set op `.tt-dashboard`, die
binnen het dashboard zowel `tokens.css` als de Huisstijl overtroefde; het
declareert er nu geen meer. `public.css` ook niet; dat herhaalde vroeger een
paar standaardwaarden uit `tokens.css`.

Eén token staat in `tokens.css` op zowel `.tt-dashboard` als `.tt-root`: de
focusring, `--tt-focus`. Die is afgeleid van `--tt-primary`, en een thema zet
zijn primaire kleur op `.tt-dashboard`, dus de ring moet daar ook gedeclareerd
zijn om die te volgen. De kleur overschrijf je via `--tt-focus-color`.
`BrandStyles` zet die alleen voor een primaire Huisstijlkleur die te licht is
om 3:1 tegen de pagina te halen.

Een thema **moet** de merktokens declareren (`--tt-primary`,
`--tt-primary-rgb`, `--tt-secondary`, `--tt-secondary-rgb` en de afgeleide
`--tt-primary-deep` / `-ink` / `-hover`). Anders schijnen de waarden van het
standaardontwerp uit `tokens.css` erdoorheen.

Het onderdrukken zit in `BrandStyles` en niet in het themablad, omdat die
methode een stuk of twaalf tokens uitstuurt en het van de ingevulde
Huisstijl-velden afhangt welke daarvan verschijnen. Eén voorwaarde op de enige
plek waar ze geschreven worden is beter dan een bewegend doel overtroeven.

### Een thema toevoegen

1. Voeg de waarde toe aan `ThemePreference::themes()` en een label aan
   `labels()`.
2. Voeg `assets/css/theme-<waarde>.css` toe. `styleFile()` leidt de
   bestandsnaam af, dus er hoeft niets anders aangepast te worden om het in te
   laden.
3. Voeg alleen een configuratiesleutel toe aan de lijst in
   `ConfigRestController` als je een nieuwe sleutel introduceert —
   `tt_frontend_theme` staat er al in.

Het inladen in `DashboardShortcode` neemt het stijlblad van de shell alleen als
afhankelijkheid op wanneer de shell dat ook werkelijk heeft ingeladen, zodat een
`classic`-installatie nooit een handle registreert die zij niet laadt.

Een thema dat een webletter wil gebruiken, moet die zelf inladen.
`BrandStyles::enqueueFonts()` stopt namelijk ook meteen zolang er een thema
actief is — de letterkeuze van de club geldt dan niet, en het overslaan van dat
verzoek scheelt de pagina een externe aanvraag waar niets mee gedaan wordt.

### Meegeleverde letters

Webletters worden meegeleverd onder `assets/fonts/<familie>/`: alleen families
onder de SIL OFL, de Latijnse subset, alleen de gewichten die een thema
gebruikt, met het `OFL.txt` van elke familie naast de bestanden.
`assets/fonts/README.md` noemt per familie de versie, de bron, de licentie en
de omvang. Een thema declareert zijn `@font-face`-regels bovenaan zijn eigen
stijlblad met `font-display: swap` en verwijst naar `../fonts/…`, zodat de
letter alleen laadt zolang dat thema actief is en de terugvalletters de opmaak
vasthouden zolang hij laadt.

| Thema | Families |
| --- | --- |
| `federation` | Barlow Condensed (koppen) |
| `clubrood` | Barlow Condensed (koppen) |
| `oranje` | Archivo |
| `tactiekbord` | IBM Plex Sans |
| `bordeaux` | Source Serif 4 (koppen), Source Sans 3 (tekst) |
| `polder` | DM Sans |

`leon-hutten` laadt Open Sans nog met een `@import` van Google Fonts bovenaan
zijn eigen stijlblad; die letter meeleveren is een aparte wijziging.

De koppen van Federation staan in de meegeleverde Barlow Condensed. De
lopende tekst blijft in de systeemletter: Barlow regular wordt niet
meegeleverd. De spelerskaart (`player-card.css`) laadt haar Oswald en Manrope
uit dezelfde map.

### Een ontwerppalet vertalen

De vijf paletten komen uit een ontwerp dat zijn kleuren noemt naar het
designsysteem. Elk stijlblad vertaalt ze op dezelfde manier:

| Ontwerp | Product |
| --- | --- |
| `--tt-green-deep` | de kopbalk en navigatiekolom (`--tt-theme-chrome`), `--tt-primary-deep` |
| `--tt-green` | `--tt-primary`, `--tt-primary-rgb`, `--tt-accent`, `--tt-link` |
| `--tt-green-soft` | `--tt-primary-ink` (hover van de primaire knop), `--tt-link-hover` |
| `--tt-gold` | `--tt-secondary`, `--tt-secondary-rgb`, `--tt-primary-hover`, de actieve markering |
| inkt, muted, lijn, papier, paper-soft, status | `--tt-ink`, `--tt-muted`, `--tt-line`, `--tt-paper`, `--tt-bg-soft`, de statustokens |

De hover-, ingedrukt-, lijn- en zachte inkttinten van de navigatiekolom zijn de
chroomkleur gemengd richting wit. Een ontwerpkleur die in een tekst- of
focusrol een contrastgrens mist, houdt haar waarde voor vlakken; die rol krijgt
dezelfde tint, stapsgewijs richting de diepe kleur van het thema (op lichte
vlakken) of richting wit (op donkere), tot hij de grens haalt. Vlakken die
`--tt-secondary` als tekst gebruiken, lezen in plaats daarvan
`--tt-theme-accent-text` (op papier) of `--tt-theme-accent-on-primary` (op de
primaire band). De focusring volgt `--tt-primary`; een thema zet geen
`--tt-focus-ring`. `ReportBrandColour::THEME_PRIMARY` bevat de primaire kleur
van elk thema, zodat rapporten het thema ook volgen.

`tests/php/ThemeContrastTest.php` controleert elk `theme-*.css` op de
contrastgrenzen: inkt op papier en op de zachte achtergrond, wit op de
primaire kleur en op de hover daarvan, inkt op de knophover, links en het
accent als tekst op 4,5:1; de focusring en de actieve markering in de
navigatiekolom, tegen de kolom en tegen de actieve regel, op 3:1. `tests/php/ThemePreferenceTest.php` controleert dat elk
thema zijn labels en stijlblad heeft, dat `default` geen stijlblad laadt en
geen klasse zet, en dat meegeleverde letters uit de plugin laden met `swap`.
