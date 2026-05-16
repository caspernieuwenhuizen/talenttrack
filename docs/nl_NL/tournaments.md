<!-- audience: user -->

# Toernooien

Een **toernooi** in TalentTrack is een container voor een set wedstrijden die je op één dag of weekend speelt, met een gedeelde selectie en gedeelde speeltijd-doelen. De toernooiplanner is gebouwd om één vraag te beantwoorden die de coach elk weekend stelt:

> *Over deze wedstrijden heen: wie speelt welke positie wanneer, hoe staat dat tegenover de rest, en wie heb ik nog niet in de basis gehad?*

Toernooien zijn in v1 alleen beschikbaar voor de Academy Admin. Coaches en Head of Development zien de functie niet totdat een vervolg-release ze toevoegt.

## Een toernooi aanmaken

1. Open de tegel **Toernooien** en tik op **+ Nieuw toernooi**.
2. De wizard begeleidt je door vijf stappen:
   - **Basis** — naam, anker-team, startdatum, optionele einddatum.
   - **Formatie** — kies een standaardformatie (bijv. `1-3-4-3`). Per wedstrijd later overschrijven kan.
   - **Selectie** — vink de spelers uit het anker-team aan, en kies per speler welke **positietypen** hij/zij kan spelen (Keeper, Verdediger, Middenvelder, Aanvaller). Voorkeuren van de speler vormen het startpunt.
   - **Wedstrijden** — voeg minstens één wedstrijd toe. Per wedstrijd: label of tegenstandernaam, niveau tegenstander, duur in minuten, en de wisselmomenten ("`10`" voor één wissel halverwege, "`20, 40, 60`" voor een 80-min wedstrijd met drie wissels).
   - **Bevestigen** — controleer het overzicht en **Aanmaken**.
3. Je komt op de detailpagina van het toernooi terecht.

## Wisselmomenten — wat ze betekenen

Het aantal minuten na de aftrap waarop een wissel plaatsvindt. Ze bepalen hoeveel **periodes** de wedstrijd heeft: `N wisselmomenten → N+1 periodes`.

- Een wedstrijd van 20 min met `[10]` → twee periodes van elk 10 minuten.
- Een wedstrijd van 60 min met `[20, 40]` → drie periodes van 20 minuten.
- Een wedstrijd van 30 min met `[]` (leeg) → één periode; geen wissels tijdens de wedstrijd.

## De planner-detailweergave

De detailweergave van een toernooi toont:

- **Feiten-strip** — team, data, standaardformatie, selectiegrootte, aantal wedstrijden.
- **Wedstrijden** — één kaart per wedstrijd. Tik op **Open planner grid** om de opstellingsgrid te openen.
- **Speeltijd-ticker** — vastgepinde strook onderaan op mobiel, rechter-zijbalk op desktop. Altijd zichtbaar. Eén kaartje per speler met:
  - Een groen/oranje/rode balk met gespeelde + ingeplande minuten t.o.v. het gelijke-verdeling-doel.
  - ⚡ aantal basisplaatsen.
  - 🏆 aantal volledige wedstrijden.
  - Sorteer: **Standaard / Minste minuten / Minste basisplaatsen / Geen volledige wedstrijd** zodat onderbedeelde spelers bovenaan komen.

## Per-wedstrijd planner

De grid laat één rij zien per formatie-slot (`GK`, `RB`, `CB`, …), één kolom per periode.

- Tik op een spelerchip — hij krijgt een gele omranding.
- Tik op een andere chip of leeg vakje — de twee plekken wisselen.
- Tik nogmaals op dezelfde chip om de selectie ongedaan te maken.

De bank-rij onderaan verzamelt spelers die niet op het veld staan in een periode. Verplaats een speler van bank naar veld op dezelfde manier.

**Eligibility-waarschuwingen**: een speler in een slot waarvoor hij niet eligible is krijgt een oranje stip. Het is een waarschuwing, geen blokkade — coach beslist.

## Auto-balanceren

De knop **Auto-balanceren** op elke wedstrijdkaart voert een greedy-toewijzing uit op basis van:

- Eligibility (alleen spelers met passende positietypen).
- Eerlijke verdeling (speler met het grootste tekort aan minuten krijgt voorrang).
- Verdeling basisplaatsen (in periode 0 krijgen spelers met de minste basisplaatsen voorrang).
- Geen-bank-twee-keer-op-rij (een speler die in de vorige periode op de bank zat, zakt in de ranking).

Auto-balanceren is een **startpunt**, geen optimizer. Verslepen en tweaken kan altijd.

## Niveau tegenstander

Elke wedstrijd heeft een niveau — standaard **zwakker / gelijkwaardig / sterker / veel sterker**. De pill op de wedstrijdkaart is kleur-gecodeerd groen → grijs → oranje → rood, zodat je in één oogopslag ziet waar je sterkste opstelling nodig is.

De auto-balancer weegt **niet** automatisch op niveau tegenstander. Dat is de beoordeling van de coach; de tool laat de data zien, jij beslist via handmatige wissels.

## Aftrap en afsluiten van een wedstrijd

- **Aftrap** — promoveert de geplande wedstrijd tot een echte activiteit. De wedstrijd verschijnt op de player journey en op de wedstrijdlijst van het team.
- **Wedstrijd afsluiten** — zet de afsluit-timestamp, synct de basisopstelling van periode 0 naar **aanwezigheid**: iedereen die startte wordt gemarkeerd als `start` met zijn positie uit periode 0; gewisselde spelers als `bench`. Gespeelde minuten gaan van "verwacht" naar "gespeeld" in de ticker.

Je kunt **Afsluiten** zonder eerst expliciet aftrap te geven — het systeem doet de aftrap automatisch.

## Wie kan dit zien

In v1 zijn de Toernooien-tegel, de planner en elk REST-endpoint alleen toegankelijk voor de **Academy Admin**. Coach, Head of Development, Scout, Speler en Ouder zien de functie niet.
