<!-- audience: user -->

# Doelen

Een **doel** is iets waar een speler aan werkt — bijvoorbeeld "nauwkeurigheid van de zwakke voet verbeteren" of "altijd op tijd zijn voor de training". Doelen vormen de verhalende kant van spelersontwikkeling, naast de numerieke beoordelingen.

## Wat staat er op een doel

- De **speler** voor wie het doel is.
- Een korte **titel**.
- Een **omschrijving** met meer detail, oefeningen of coachingnotities.
- Een **status** (Niet gestart, In uitvoering, Behaald, Gestopt).
- Een **prioriteit** (Laag, Gemiddeld, Hoog).
- Een optionele **streefdatum**.

## Een doel toevoegen

1. Open de tegel **Doelen**.
2. Kies de speler.
3. Vul titel, omschrijving, status en prioriteit in en optioneel een streefdatum.
4. Opslaan.

## Voortgang volgen

Werk de status en omschrijving in de loop van de tijd bij naarmate de speler vordert. Het **Status**-filter op de doelenlijst groepeert doelen in **Actief**, **Behaald** en **Gemist**, en staat standaard op Actief zodat de lijst opent met wat er nog loopt. Met het aparte **Archief**-filter (Actief / Gearchiveerd, standaard Actief) vind je gearchiveerde doelen terug.

## Wie ziet wat

- Spelers zien hun eigen doelen.
- Coaches zien de doelen van spelers in de teams die zij coachen.
- Beheerders zien alle doelen.

## Methodologiekoppeling (v3.79.0)

Doelen kunnen nu worden gekoppeld aan een methodologie-principe en/of één voetbalhandeling vanuit zowel het publieke doel-formulier als het wp-admin-formulier. De koppeling is optioneel maar maakt rapportage per principe mogelijk op het persona-dashboard (de nieuwe Doelen-per-principe-widget toont actieve en afgeronde doelen per principe; een Doelen-gekoppeld-aan-principe KPI volgt de dekking over de afgelopen 90 dagen).

## Door spelers gemaakte doelen met goedkeuring (v3.79.0)

Als jouw installatie spelers de doelen-bewerken-rechten geeft, krijgt een door een speler aangemaakt doel de status **Wacht op goedkeuring**. De hoofdcoach van de speler kan goedkeuren (status wordt In afwachting) of afwijzen (Geannuleerd) via de bestaande statusdropdown. Andere coaches kunnen niet goedkeuren — alleen de hoofdcoach van de speler, hetzelfde vertrouwenspatroon als bij PDP-ondertekening.

## Voortgang en bewijslast (#1717)

Elk doel kan een **voortgangspercentage** en **bewijslast** dragen. Op het
bewerkformulier van het doel:

- **Voortgang (%)** — een waarde van 0–100 die de coach instelt; dit stuurt
  de voortgangsbalk op de POP-kaart van de speler. Laat leeg om de balk te
  verbergen.
- **Bewijslast (beoordelingen)** — vink de beoordelingen van de speler aan die
  het doel onderbouwen. Elke gekoppelde beoordeling verschijnt op de
  POP-kaart als een scorechip (*Beoordeling 12 mrt · 6.5*), op basis van de
  datum en de gemiddelde score van de beoordeling.

Bewijslast wordt los van de methodiekkoppelingen van het doel opgeslagen, dus
de twee zitten elkaar niet in de weg.
