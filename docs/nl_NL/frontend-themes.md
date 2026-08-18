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

1. `assets/css/tokens.css` declareert de neutrale tokens op `.tt-root` (de
   body-klasse).
2. Het themablad declareert een deel daarvan opnieuw op `.tt-dashboard`, een
   dichterbij liggende voorouder, zodat elk vlak binnen het dashboard de
   themawaarde erft zonder één regel per view. Dat is hetzelfde
   cascade-argument dat `tokens.css` zelf over `BrandStyles` maakt.
3. Wat een token niet kan uitdrukken — de marineblauwe balk van de app-shell,
   die niet uit een andere `--tt-paper` kan komen zonder elke kaart donker te
   maken — is een klein aantal expliciete regels op de bestaande klassen van
   de shell.

Een thema **moet** de merktokens declareren (`--tt-primary`,
`--tt-primary-rgb`, `--tt-secondary`, `--tt-secondary-rgb` en de afgeleide
`--tt-primary-deep` / `-ink` / `-hover`). `BrandStyles::injectVars()` stopt
meteen zolang er een thema actief is, dus niets anders levert ze — en zo'n
veertig vlakken lezen `var(--tt-primary, #0b3d2e)` met het meegeleverde groen
als harde terugvalwaarde, en dát zouden die regels tekenen als het thema zweeg.

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

### Bekend gat

De bedoelde letter voor Federation is **Barlow Condensed** voor koppen en
**Barlow** voor de rest. Geen van beide wordt met de plugin meegeleverd, dus
het thema valt terug op de smalle letters die al op het besturingssysteem
staan (`Arial Narrow`, daarna `Segoe UI Condensed`) en uiteindelijk op de
bodyletter. Het meeleveren van de webfonts wordt apart bijgehouden — daar
hangt een licentie- en laadtijdafweging aan die het thema zelf niet heeft.
