<!-- audience: user -->

# Teamchemie

Het **Teamchemie**-bord beantwoordt de vragen die elke academiecoach informeel stelt:

- Past deze opstelling bij hoe het team speelt?
- Past het bij hoe we *willen* spelen?
- Wat is de diepte op elke positie?
- Als een speler wisselt van rol, wordt het team dan beter?

Elke score op het bord is **uitlegbaar**. Houd de muis boven elk getal om de exacte beoordelingen en wegingen te zien die de score produceerden. Geen black-box-uitvoer.

## Waar te vinden

Coaches en hoofd-academie zien een **Teamchemie**-tegel in de *Performance*-groep op het dashboard. Kies een team en het bord opent. Read-only observers zien hetzelfde bord maar kunnen geen koppelingen bewerken.

Een tweede oppervlak is per speler: elk spelerprofiel heeft een **Beste posities**-kaart die de top drie posities van de speler in de huidige formatie van het team toont, opnieuw met onderbouwing bij hoveren.

## Wat staat er op het bord

### Veld

Een gekanteld voetbalveld met de elf slots voor de gekozen formatie van het team. Elke slot toont de speler die het beste past en de fit-score op een schaal van 0–5.

- **Groene score** ≥ 4.0 — sterke fit
- **Oranje score** 3.0–4.0 — werkbare fit
- **Rode score** < 3.0 — fit-gat

Als dezelfde speler op twee slots de beste zou zijn, wordt hij op de hoger scorende slot geplaatst en pakt de tweede keuze de andere slot.

### Chemie­opbouw

Onder het veld staat de samengestelde chemiescore met een vierdelige opbouw:

- **Formatiefit** (65%) — gemiddelde van de fit-scores van de voorgestelde basiself
- **Stijlfit** (20%) — hoe de selectie aansluit bij de mix van balbezit / counter / press
- **Diepte** (15%) — zachte ondergrens voor slots zonder twee capabele backups
- **Koppel­bonus** (additief, gemaximeerd op +0,5) — coach-gemarkeerde koppels waarbij beide spelers in de voorgestelde basiself staan

### Dieptestaat

Een rij per slot met de top drie kandidaten en hun scores. Nuttig voor vragen als "wie speelt als onze basisspeler niet beschikbaar is".

### Koppelingen

Coach-gemarkeerde "deze twee altijd samen opstellen"-koppels. De optionele notitie geeft context die de score niet kan vastleggen (bv. "communicatief verdedigend duo"). Koppelingen tellen alleen mee als beide spelers in de voorgestelde basiself staan.

## Configuratie

- **Formatie** — stel het actieve sjabloon per team in (admin of hoofd-academie). Vier sjablonen worden meegeleverd: Neutraal / Balbezit / Counter / Press-heavy 4-3-3. Allemaal 4-3-3; de wegingen per slot verschillen. Aangepaste sjablonen kunnen vandaag via de REST API worden toegevoegd; een admin-UI volgt later.
- **Stijlmix** — schuiven voor balbezit, counter en press. De drie gewichten moeten samen 100 zijn.
- **Zijvoorkeur** — instelbaar op het spelerprofiel (links / rechts / centrum). Voegt ±0,2 toe aan fit-scores bij overeenkomst / mismatch tegen een zijgebonden slot.

## Cache + herberekening

Fit-scores per speler worden 24 uur gecachet. Het opslaan van een evaluatie voor een speler vernietigt die cache van de speler, zodat het bord altijd de nieuwste beoordelingen weerspiegelt binnen enkele minuten. Geen handmatige verversing nodig.

## Vertrouw de onderbouwing

Als een score niet matcht met je gevoel, houd de muis erboven. Elke bijdrage staat er meteen: beoordeling × weging per categorie, plus de zij-modifier. De getallen zijn een gereedschap; de coach beslist.
