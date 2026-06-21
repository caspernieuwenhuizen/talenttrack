<!-- audience: user -->

# Activiteiten en aanwezigheid

Een **activiteit** is alles wat in de agenda staat — een training, een wedstrijd of een ander evenement (team-buildingdag, clubvergadering, …). Bij elke activiteit registreer je wie er aanwezig was.

## Het activiteitenoverzicht (v4.7.0)

De tegel **Activiteiten** opent een lijst die per datum is gegroepeerd. De groepen lopen van boven naar beneden:

- **⚠ Vereist aandacht** — voorbije activiteiten die nog op Gepland staan. Ze zijn nooit op Voltooid of Geannuleerd gezet, dus de coach is ze uit het oog verloren. Oranje gemarkeerd zodat ze opvallen.
- **Vandaag** — wat er voor vandaag op de planning staat.
- **Deze week** — de rest van deze kalenderweek (tot en met zondag).
- **Volgende week** — maandag → zondag van de week erna.
- **Later deze maand** — alles na volgende week, tot en met het einde van de huidige maand.
- **Later** — alles na het einde van de maand.

Lege groepen tonen geen kop — als er volgende week niets gepland staat, verschijnt de kop "Volgende week" simpelweg niet.

Elke rij is een kaart: een datumbadge links (maand + dag, blauw voor vandaag en oranje voor "Vereist aandacht"), de titel van de activiteit in het midden met een kleurgecodeerde type-pill (Training blauw, Wedstrijd rood, Oefen geel, Overig grijs) en een chevron rechts. Tik ergens op de kaart om de detailpagina te openen.

### Voorbije activiteiten

Voorbije activiteiten (Voltooid of Geannuleerd) zijn boven aan de lijst vastgepind als één knop — `N voorbije activiteiten verborgen · Toon ▼`. Tik om uit te klappen; tik nogmaals om in te klappen. De stand wordt bewaard in de URL als `?include_past=1`, zodat een gedeelde link dezelfde weergave toont als degene die hem deelde.

Voorbije **geplande** activiteiten (nog niet afgerond) staan NIET in deze ingeklapte groep — die verschijnen in de groep **Vereist aandacht** boven Vandaag, omdat ze een signaal zijn waarop de coach nog moet handelen.

### Filters

Boven de lijst staan twee filters:

- **Team** — beperk tot één team. Standaard staan alle teams aan waar de coach toegang toe heeft.
- **Type** — beperk tot één activiteittype (Training / Wedstrijd / Oefen / Overig / elk eigen type dat je academie heeft toegevoegd).

Beide filters blijven bewaard in de URL (`?team_id=N&activity_type_key=match`), zodat deep-links vanaf het dashboard op dezelfde gefilterde weergave landen.

## De activiteitdetailpagina

Door op een kaart te tikken open je de detailpagina van de activiteit, opgebouwd uit kaarten zodat elk geregistreerd detail in één oogopslag zichtbaar is. De pagina past zich aan tussen een **training** en een **wedstrijddag**:

- **Hero** — een typegekleurd icoontje, de titel en een subregel met `datum · tijd · team · locatie`. Bij een wedstrijddag waarvan beide teams bekend zijn leest de titel `Jouw team vs Tegenstander` en toont de subregel de aftraptijd en of het thuis of uit is. De pillen onder de titel tonen het type (plus het wedstrijdsubtype of het Overig-label) en de status. Bewerken, Opkomst registreren en de overige acties staan in de paginakop erboven.
- **Feitenbalk** — vier snelle feiten. Een training toont Datum · Tijd · Type · Status; een wedstrijddag toont Tegenstander · Thuis/Uit · Aftrap · Opstelling. Feiten zonder waarde worden weggelaten.
- **Kaarten** — alleen kaarten met inhoud verschijnen, zodat de pagina overzichtelijk blijft:
  - **Gekoppelde spelprincipes** — de geoefende principes als kleurgecodeerde O/A/V-pillen, elk met een link naar de methodiekverkenner.
  - **Notities** — de vrije tekstnotities van de activiteit.
  - **Opstelling** (wedstrijddag) — de basiself en de bank, elke speler met rugnummer en de gespeelde positie (met terugval op de voorkeurspositie).
  - **Verwachte opkomst** — de geplande selectie (zie hieronder).
  - **Opkomst** (afgeronde activiteiten) — een verdeelbalk met legenda over Aanwezig / Afwezig / Te laat / Met kennisgeving / Geblesseerd (plus eventuele eigen statussen), met de kop `X / Y aanwezig (Z%)` die linkt naar het opkomstformulier. Een melding waarschuwt wanneer selectiespelers nog geen opkomstregel hebben.
  - **Toernooi** — voor toernooi-activiteiten het gekoppelde toernooi met data en aantal wedstrijden.
- **Auditvoettekst** — wie de activiteit heeft aangemaakt en als laatste gewijzigd.

De pagina leest prettig op een telefoon: de kaarten stapelen in één kolom en verbreden naar twee kolommen op een tablet of desktop.

## Een activiteit aanmaken

1. Open de tegel **Activiteiten**.
2. Kies het **type** uit de keuzelijst. Vijf types staan standaard klaar (Training, Wedstrijd, Toernooi, Bespreking, Overig) en je academie kan ze hernoemen of nieuwe toevoegen.
3. Kies de **status** — Gepland, Voltooid of Geannuleerd. Nieuwe activiteiten staan standaard op Gepland; zet hem op Voltooid zodra de activiteit is geweest, of op Geannuleerd als hij niet doorging.
4. Bij een **wedstrijd**: kies optioneel het subtype (Oefen, Beker, Competitie).
5. Bij **Overig**: geef het een korte omschrijving.
6. Kies het team, stel de datum in en voeg eventueel een locatie en notities toe.
7. Opslaan. De spelerslijst wordt automatisch gevuld vanuit het team.
8. Markeer iedere speler als Aanwezig, Afwezig, Te laat of Afgemeld. Zet er een notitie bij waar handig.

In het overzicht zie je het type als een gekleurde pill, zodat trainingen, wedstrijden, toernooien, besprekingen en overige activiteiten in één oogopslag te onderscheiden zijn.

## Verwachte opkomst

Bij het aanmaken van een activiteit kies je welke spelers worden verwacht — de selectiestap staat standaard op het hele team, en je vinkt iedereen uit van wie je al weet dat die er niet is. Die keuze is de **geplande selectie** van de activiteit.

Open de detailpagina van een activiteit en je ziet een paneel **Verwachte opkomst** met die spelers (gasten worden gemarkeerd) en het aantal in de kop, zodat je vóór de sessie weet wie je kunt verwachten. Het paneel verschijnt niet als je bij het aanmaken voor "Aanwezigheid later instellen" koos. Wie daadwerkelijk kwam, markeer je nog steeds op het bewerkformulier (of via de wizard Aanwezigheid markeren) — de geplande selectie is wat je verwachtte, de gemarkeerde aanwezigheid is wat er gebeurde.

## Waarom het type ertoe doet

Elk activiteittype kan gekoppeld worden aan een workflow-sjabloon dat afgaat zodra je een activiteit van dat type opslaat. Standaard:

- **Wedstrijd** maakt een evaluatietaak per speler in de inbox van de coach.
- **Training** en **Overig** maken geen automatische taak.

Je beheerder kan via **Configuratie → Activiteittypes** wijzigen welk sjabloon bij welk type hoort, of een nieuw type toevoegen en daar een sjabloon aan koppelen. De standaardtypes kunnen niet verwijderd worden omdat de evaluatietaak afhankelijk is van het bestaan van **Wedstrijd**.

## Status en bron

Naast het type heeft elke activiteit twee extra velden:

- **Status** — waar de activiteit zich in de levenscyclus bevindt. **Gepland** is de standaard bij nieuwe activiteiten; zet hem op **Voltooid** zodra de activiteit is geweest zodat rapportages en KPI's hem als historisch behandelen, of op **Geannuleerd** als hij niet doorging. De lijst statuswaarden is uitbreidbaar via **Configuratie → Lookups** (lookup-type `activity_status`).
- **Bron** — wie of wat de activiteit heeft aangemaakt. **Handmatig** voor activiteiten die in de app zijn gemaakt, **Gegenereerd** voor activiteiten van de demo-data-generator, en **Spond** voor activiteiten die uit een Spond-agenda zijn gesynchroniseerd (zodra de integratie aanstaat). De bron wordt automatisch gezet, niet handmatig op het formulier. Net als status is de lijst bronnen uitbreidbaar.

De 90-daagse rollup die het Hoofd Opleidingen gebruikt toont één regel per actief type — hernoem of voeg types toe en de rollup volgt automatisch.

## Wie het heeft aangemaakt en gewijzigd

Onderaan het detailpaneel van een activiteit staat een kleine regel: **Aangemaakt door** wie de activiteit heeft toegevoegd en op welke datum, en **Laatst gewijzigd door** wie het laatst heeft bewerkt. Dit wordt vanaf nu automatisch vastgelegd — activiteiten die vóór deze toevoeging zijn aangemaakt tonen hier niets (er is geen historie om in te vullen), en de regel verschijnt pas zodra er een auteur bekend is.

## Gasten

Je kunt spelers van buiten de selectie toevoegen aan een activiteit — bijvoorbeeld een speler die je leent van een ander team voor een oefenwedstrijd, of een proefspeler.

Er zijn twee soorten gasten:

- **Gekoppelde gast** — een bestaande speler van een ander team. Zoek op naam en kies hem of haar. Een evaluatie die je schrijft komt op het profiel van die speler.
- **Anonieme gast** — alleen een naam, nog geen record. Handig voor een eenmalige proefspeler. Je kunt hem later via **Promoveren naar speler** omzetten naar een echte speler.

Open de activiteit, scroll naar het kopje **Gasten**, klik **+ Gast toevoegen**, vul de velden in en klik **Toevoegen**.

Gasten tellen niet mee in de teamstatistieken — aanwezigheidspercentages en het podium gebruiken alleen de selectie.

## Opruimen

Je kunt een activiteit archiveren om oude seizoenen op te ruimen zonder de historie kwijt te raken.

## Geoefende principes (v3.79.0)

Elke activiteit kan worden gekoppeld aan één of meer methodologie-principes, zodat rapporten kunnen aangeven "hoe vaak hebben we deze periode aan principe X gewerkt?" De multiselect Geoefende principes verschijnt op zowel de publieke Activiteit-pagina als het wp-admin-formulier. De koppeling is optioneel.

## Gastenpaneel in admin (v3.79.0)

De wp-admin Activiteit-pagina toont nu een alleen-lezen lijst met gast-aanwezigen die zijn vastgelegd. Voeg gasten toe of verwijder ze via de publieke Activiteit-pagina; het admin-paneel blijft synchroon.
