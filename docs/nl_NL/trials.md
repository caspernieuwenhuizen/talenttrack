<!-- audience: user -->

# Stagedossiers

Een **stagedossier** is een gestructureerde manier om gedurende 2–6 weken naar een potentiële speler te kijken en die periode af te sluiten met een duidelijk, goed gecommuniceerd besluit. Alles wat anders verspreid raakt over Excel en e-mail komt hier samen: wie er stage loopt, op welk traject, wie hem of haar ziet, wat de input is, wat het besluit is, en de brief die naar de ouders gaat.

## Wie ziet wat

- **Hoofd opleiding / Clubbeheer** — volledig beheer. Dossiers openen, verlengen, besluiten, archiveren. Trajecten en briefsjablonen aanpassen. Staf-input vrijgeven.
- **Coaches die zijn toegewezen aan een dossier** — zien het overzicht plus de uitvoering, en geven op het tabblad **Staf-input** hun eigen input. Andere inputs zien ze pas nadat het hoofd opleiding heeft vrijgegeven.
- **Overige coaches** — zien het dossier niet, tenzij ze zijn toegewezen.

## Hoe het werkt

### 1. Open een dossier

Klik op de tegel **Stagedossiers** en kies *Nieuw stagedossier*. Selecteer de speler (of maak deze eerst aan), kies een traject (Standaard / Scout / Keeper, of een door de club toegevoegd traject), zet de start- en einddatum en wijs eventueel direct staf toe. De status van de speler wordt automatisch op **Stage** gezet.

### 2. De stage volgen

Het tabblad **Uitvoering** bundelt alles wat tijdens de stageperiode plaatsvindt — sessies waar de speler bij was, geschreven evaluaties, doelen die zijn aangemaakt of bijgewerkt, plus een korte synthese (rolling rating, aantal evaluaties). Niets wordt gedupliceerd; de gegevens blijven op hun gebruikelijke plek staan, dit tabblad filtert alleen op het stagevenster.

Moet de periode verlengd worden, dan vraagt **Stage verlengen** op het Overzicht-tabblad om een nieuwe einddatum en een verplichte motivatie. Elke verlenging wordt vastgelegd met wie, wanneer en waarom.

### 3. Verzamel staf-input

Elke toegewezen coach heeft een eigen invoerformulier op **Staf-input**. Hij geeft een algemene beoordeling en aantekeningen op, slaat op als concept en dient definitief in zodra de input klaar is. Een coach ziet alleen de eigen concept-input totdat het hoofd opleiding **Inputs vrijgeven aan toegewezen staf** klikt — zo voorkom je groupthink tijdens de periode en deelt iedereen zijn beeld pas als iedereen heeft ingediend.

Het systeem stuurt vriendelijke herinneringen aan stafleden die nog niet hebben ingediend naarmate de stage afloopt (7 dagen vooraf, 3 dagen vooraf, op de einddatum).

### 4. Beslissen

Op het tabblad **Beslissing** kiest het hoofd opleiding één van drie uitkomsten:

- **Aanbieden** — een plek aanbieden. Speler-status → Actief.
- **Afwijzen (definitief)** — dit seizoen geen plek. Speler-status → Gearchiveerd.
- **Afwijzen (met aanmoediging)** — dit seizoen geen plek, maar een warme uitnodiging om volgend jaar opnieuw te proberen. Het formulier vraagt om enkele zinnen over sterke punten en groeimogelijkheden; die komen rechtstreeks in de aanmoedigingsbrief.

Het beslissingsformulier vereist een interne motivatie van minimaal 30 tekens.

### 5. Brief genereren

Bij het vastleggen van een besluit wordt de brief automatisch gegenereerd. Het tabblad **Brief** toont de brief in de pagina en biedt een afdrukweergave aan. Drie sjablonen worden meegeleverd:

- **Aanbod** — warm welkom, vervolgstappen, eventueel een acceptatiestrook op pagina 2 als de club die functie aan heeft staan.
- **Afwijzen (definitief)** — respectvol en duidelijk.
- **Afwijzen (met aanmoediging)** — benoemt wat positief opviel en waar nog aan gewerkt mag worden, met een expliciete uitnodiging voor een nieuwe stage.

Past de tekst niet helemaal? Met **Briefsjablonen** (onder de groep Stagedossiers) past de club elk sjabloon per taal aan. De editor toont een legenda met alle beschikbare variabelen (`{player_first_name}`, `{trial_end_date}`, `{strengths_summary}`, …). Onbekende variabelen blijven letterlijk `{foo}` staan zodat ontbrekende stukken zichtbaar zijn in de voorbeeldweergave.

### 6. Het gesprek met de ouders

Het tabblad **Oudergesprek** opent een schoon, schermvullend beeld dat ontworpen is om op een laptop of tablet aan ouders te laten zien. Interne gegevens worden bewust weggelaten — geen individuele beoordelingen, geen aanwezigheidspercentages, geen interne motivatie. Wat wél te zien is: foto, naam en leeftijd van de speler, de uitkomst en de brief, klaar om af te drukken of te mailen.

## Trajecten

Trajecten zijn sjablonen die de standaard stageduur bepalen. Drie worden meegeleverd (Standaard / Scout / Keeper) en clubs kunnen via **Stagetrajecten** eigen trajecten toevoegen. Bestaande dossiers blijven werken als een traject wordt gearchiveerd; alleen nieuwe dossiers zien gearchiveerde trajecten niet meer.

## Acceptatiestrook (optioneel)

Bij een aanbod kan de club een acceptatiestrook op pagina 2 van de brief meesturen. Onder **Briefsjablonen → Acceptatiestrook** zet je hem aan, kies je de antwoordtermijn (aantal dagen vanaf de briefdatum) en het retouradres. Komt de strook getekend retour, markeer dat dan op het tabblad **Beslissing**.

## Een stagedossier afsluiten

Een dossier blijft "open" — zichtbaar voor toegewezen staf, telt mee voor de werkvoorraad van de Hoofd Opleiding — totdat het ofwel **beslist** ofwel **gearchiveerd** is. Twee paden, twee verschillende bedoelingen:

### Beslissen (het normale pad)

Gebruik het tabblad **Beslissing** om een uitkomst vast te leggen (`Aannemen` / `Afwijzen (definitief)` / `Afwijzen (met aanmoediging)`) plus de verplichte motivatie van ≥ 30 tekens. Het vastleggen:

- Verandert de status van de speler (Aannemen → Actief, Afwijzen → Gearchiveerd).
- Genereert automatisch de bijpassende brief.
- Stempelt `decision_made_at` + `decision_made_by` voor het audittrail.

Gebruik dit altijd als je een inhoudelijk antwoord aan het gezin verschuldigd bent. De rest van het gesprek loopt via het tabblad Oudergesprek.

### Archiveren (het "geen antwoord nodig" pad)

Wanneer je het gezin geen formele beslissing schuldig bent — het gezin reageert niet meer, de speler is verhuisd, het dossier is per ongeluk geopend — sluit de actie **Dossier archiveren** het dossier zonder beslissingsregel en zonder brief te genereren. De actie staat in de pagina-koprij van de dossierpagina (rol manager / hoofd opleiding vereist). Het dossier blijft in de database staan (te vinden via de gearchiveerde-dossiers-lijst); het telt alleen niet meer als open werk.

Archiveer je een dossier waar wél een beslissing bij hoorde en blijkt het gezin tóch te willen praten, dan kan een admin het dossier weer activeren via de wp-admin lijst met stagedossiers.

## Bewaartermijn

Brieven worden bewaard met een vervaldatum van 2 jaar. Archiveren is de standaard — afwijzingsbrieven worden niet automatisch verwijderd omdat de club ze nodig kan hebben bij heroverwegingen of bezwaar. Een aparte AVG-verwijderfunctie regelt definitieve verwijdering op verzoek van de ouder.

## Lineaire dossierpagina + stagespelers op teampagina (v3.79.0)

De stagedossierdetailpagina was eerst opgesplitst in zes tabbladen (Overzicht / Uitvoering / Coachinput / Beslissing / Brief / Oudergesprek). Nu wordt alles op één lineaire pagina getoond met een vaste ankerstrip bovenaan — elke sectie is zichtbaar zonder route-wissel, wat een snelle review veel sneller maakt. Links naar oude `?tab=`-URL's scrollen automatisch naar het bijbehorende anker.

Stagespelers verschijnen nu ook op de teamdetailpagina onder een eigen subsectie **Stagespelers**. Eerder vielen ze uit de roster door de actieve-status-filter.
