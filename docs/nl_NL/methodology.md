# Methodologie

In de Methodologie-bibliotheek leeft het coachingsraamwerk van je academie in TalentTrack: het raamwerk per club, spelprincipes, formaties + positiekaarten, spelhervattingen, de clubvisie en de catalogus voetbalhandelingen. Coaches gebruiken deze als naslag tijdens sessieplanning en speelgesprekken.

## Waar je hem vindt

- **wp-admin**: TalentTrack → Methodology (Performance-groep). Voetbalhandelingen heeft een eigen ingang onder TalentTrack.
- **Frontend**: de Methodology-tegel onder Performance.

## Zes tabbladen

1. **Raamwerk** — de methodologische intro per club: inleiding, het voetbalmodel op hoofdlijnen, voetbalhandelingen, de vier fasen van aanvallen en verdedigen, leerdoelen, factoren van invloed en reflectie / toekomst. Elke sectie kan illustraties dragen.
2. **Spelprincipes** — gecodeerde principes zoals AO-01 (opbouw), VS-02 (storen). Elk bevat toelichting, sturing op teamniveau, sturing per linie (Aanvallers / Middenvelders / Verdedigers / Keeper), een formatiediagram en een primaire illustratie.
3. **Formaties & posities** — de formatievisual + rolkaarten per rugnummer. Elke positiekaart toont aanvallende en verdedigende taken plus een optioneel diagram.
4. **Spelhervattingen** — corners, vrije trappen (direct + voorzet), penalty's, inworpen. Aanvallend + verdedigend, met illustraties.
5. **Visie** — het overkoepelende record van de club: gekozen formatie, speelstijl, speelwijze en belangrijke spelerseigenschappen.
6. **Voetbalhandelingen** — de catalogus voetbalhandelingen (aannemen, passen, dribbelen, schieten, koppen — plus vrijlopen, knijpen, jagen, dekken en ondersteunende handelingen zoals spelinzicht / communicatie).

## Twee bronnen van inhoud

- **Geleverd** door TalentTrack. Standaard alleen-lezen — clubs kunnen het niet wijzigen of breken. Plugin-updates kunnen nieuwe geleverde inhoud toevoegen.
- **Club-eigen** inhoud, aangemaakt in wp-admin door clubbeheerders. Verschijnt naast geleverde inhoud in de bibliotheek.

Om vanuit geleverde inhoud te starten zonder het origineel aan te raken: klik **Klonen & bewerken** — je krijgt een club-eigen kopie om aan te passen; de geleverde regel blijft ongewijzigd.

## Hoe het aansluit op de rest van TalentTrack

- **Doelen**: een doel kan optioneel aan één principe en aan één voetbalhandeling worden gekoppeld. Gebruik een of beide om ontwikkelingsdoelen concreet te maken — "deze speler werkt aan AO-02 (opbouw via de zijkanten)" of "deze speler verbetert dribbelen".
- **Sessies**: een sessie kan meerdere principes opnoemen die worden geoefend. Coaches zien in één oogopslag welke principes ze in een week behandelen.
- **Teamplannen (#0006, toekomstig)**: zodra teamplanning wordt geleverd, leest die direct uit principes.

## Diagrammen en afbeeldingen toevoegen

Elke entiteit in de bibliotheek (spelprincipe, spelhervatting, positie, visie, raamwerk-introductie, fase, leerdoel, factor van invloed, voetbalhandeling) heeft op de bewerkpagina een sectie "Diagrammen en afbeeldingen". Klik op **Afbeelding kiezen…** om de WordPress mediabibliotheek te openen, een afbeelding te uploaden of te selecteren; bij opslaan wordt een record aangemaakt in `tt_methodology_assets`. De eerste afbeelding wordt de primaire (de hero), bijkomende kunnen worden toegevoegd, voorzien van een NL/EN bijschrift, gepromoveerd of gearchiveerd.

De plugin levert diagrammen aan vanuit het oorspronkelijke methodologiedocument; ze worden automatisch gekoppeld aan de bijbehorende geleverde entiteit. Wil je een geleverde diagram vervangen door je eigen versie? Archiveer het geleverde beeld en voeg je eigen toe — het formulier houdt beide totdat je de ongewenste archiveert.

## Meertalige inhoud

Elk catalogusveld wordt opgeslagen als JSON per taal. Vandaag is de bibliotheek geleverd in het Nederlands (de brontaal van het methodologiedocument) met Engelse vertalingen op geleverde rijen; club-eigen inhoud ondersteunt beide. De bibliotheek toont in de taal van de bezoeker en valt terug op NL → EN → leeg als een vertaling ontbreekt.
