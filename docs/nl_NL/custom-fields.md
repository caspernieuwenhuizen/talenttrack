<!-- audience: admin -->

# Aangepaste velden

Wil je iets vastleggen dat TalentTrack standaard niet ondersteunt? Voeg een aangepast veld toe.

## Wat je kunt toevoegen

Aangepaste velden zijn extra gegevensvelden die je koppelt aan kernentiteiten:

- Spelers (bijv. "Shirtmaat", "Noodcontact", "E-mail ouder")
- Teams (bijv. "Thuiskleur", "Kitleverancier")
- Evaluaties (bijv. "Veldconditie", "Weer")

Elk veld heeft een type: tekst, getal, datum, selectie (met opties) of tekstvak.

## Een veld aanmaken

**Configuratie → Aangepaste velden → Nieuwe toevoegen**

1. Kies de entiteit (Speler / Team / Evaluatie).
2. Benoem het veld en geef het een slug (automatisch gegenereerd vanuit het label, pas aan indien nodig).
3. Kies het type.
4. Bij `select`: voer de opties één per regel in.
5. Markeer als verplicht indien nodig.
6. Opslaan.

Het veld verschijnt automatisch op het bewerkformulier van die entiteit op de juiste plek.

## Weergavevolgorde

Aangepaste velden worden per entiteit gesorteerd op hun `display_order`-waarde. Momenteel alleen aanpasbaar op het bewerkformulier; slepen-om-te-herordenen voor aangepaste velden staat op de backlog.

## Export

Aangepaste veldwaarden gaan mee met de entiteit in CSV-exports (indien beschikbaar) en zijn bevraagbaar via SQL.

## Archiveren

Gearchiveerde aangepaste velden verdwijnen uit formulieren, maar bestaande waarden op historische entiteiten blijven behouden.
