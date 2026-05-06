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

Een proportioneel correct voetbalveld (105 m × 68 m verhouding, alle standaardlijnen getekend) met de elf slots voor de gekozen formatie van het team. Elke slot toont de speler die het beste past en de fit-score op een schaal van 0–5.

- **Groene score** ≥ 4.0 — sterke fit
- **Oranje score** 3.0–4.0 — werkbare fit
- **Rode score** < 3.0 — fit-gat
- **Grijze "?"** — de voorgestelde speler heeft nog geen evaluaties, dus we kunnen geen fit-score berekenen; de slot wordt alleen op basis van beschikbaarheid ingevuld
- **Streepje "—"** — de selectie is kleiner dan deze formatie nodig heeft; geen speler beschikbaar voor deze slot

Als dezelfde speler op twee slots de beste zou zijn, wordt hij op de hoger scorende slot geplaatst en pakt de tweede keuze de andere slot. Het bord hergebruikt geen speler meer over meerdere slots wanneer de selectie te klein is — lege slots worden expliciet getoond zodat je de leemte kunt zien.

Het veld wordt standaard plat weergegeven. Klik op *Wissel naar isometrische weergave* onder het veld voor de gekantelde v1-look — een CSS-only schakelaar die via een URL-parameter blijft hangen.

### Lijnchemie

Gekleurde lijnen lopen tussen aangrenzende slots in de formatie, zoals in FIFA Ultimate Team. Elke lijn krijgt een score op basis van drie signalen:

- **Door coach gemarkeerd duo** (+2) — de twee spelers staan in de koppellijst van het team (sterkste signaal)
- **Zelfde linie** (+1) — beide slots zitten in dezelfde band: keeper / verdediging / middenveld / aanval
- **Voorkeursbeen-fit** (+1) — beide voorkeursbenen (links / rechts) passen bij de zijde van hun slot; een mismatch (rechtsbenig in linksback, etc.) trekt 1 af

De per-duo-score wordt geknepen tot 0–3 en gebucket:

- **Groen** (2,0–3,0) — sterke fit
- **Oranje** (1,0–2,0) — werkbaar
- **Rood** (0–1,0) — zwakke fit
- **Grijs** — neutrale lijn voor lege slots (niet gescoord)

Beweeg over een lijn voor de uitsplitsing — de signalen die meetelden en de resulterende score.

De kop boven het veld leest *Lijnchemie: N / 100*, berekend als `som(duo-scores) / (gescoorde duo's × 3) × 100`. Dit staat los van de samengestelde score eronder — de samenstelling meet *past deze basiself bij de speelstijl van het team?*; de lijnchemie meet *passen deze elf bezetters bij elkaar?*

### Samengestelde chemie­opbouw

Onder het veld staat de samengestelde chemiescore met een vierdelige opbouw:

- **Formatiefit** (65%) — gemiddelde van de fit-scores van de voorgestelde basiself
- **Stijlfit** (20%) — hoe de selectie aansluit bij de mix van balbezit / counter / press
- **Diepte** (15%) — zachte ondergrens voor slots zonder twee capabele backups
- **Koppel­bonus** (additief, gemaximeerd op +0,5) — coach-gemarkeerde koppels waarbij beide spelers in de voorgestelde basiself staan

### Dieptestaat

Een rij per slot met de top drie kandidaten en hun scores. Nuttig voor vragen als "wie speelt als onze basisspeler niet beschikbaar is".

### Koppelingen

Coach-gemarkeerde "deze twee altijd samen opstellen"-koppels. De optionele notitie geeft context die de score niet kan vastleggen (bv. "communicatief verdedigend duo"). Koppelingen tellen alleen mee als beide spelers in de voorgestelde basiself staan.

## Wanneer het bord "Nog niet genoeg evaluaties" zegt

De samengestelde score, formatiefit, stijlfit en diepte tonen **"?"** totdat ten minste 40% van de selectie minimaal één gewaardeerde hoofdcategorie heeft (technisch / tactisch / fysiek / mentaal). Dat is de drempel waaronder de wiskunde getallen produceert die betekenisvol *lijken* maar dat niet zijn — een selectie van 12 met één gewaardeerde speler zou 0,42 tonen en je zou dat als chemie-score behandelen, terwijl er gewoon te weinig data is.

Het bord rendert het veld nog steeds in deze leeg-staat — slots worden alleen op basis van beschikbaarheid ingevuld en gemarkeerd met "?" — zodat je de vorm en de gaten ziet. Beoordeel een paar spelers extra en de scores lichten op.

## Configuratie

- **Formatie** — kies via de dropdown boven het veld om een andere vorm te bekijken. Zeven sjablonen worden out-of-the-box meegeleverd:
  - **4-3-3 in vier speelstijl-varianten**: Neutraal / Balbezit / Counter / Press-heavy. Zelfde vorm, verschillende slot-wegingen.
  - **4-4-2 (Neutraal)**, **3-5-2 (Neutraal)**, **4-2-3-1 (Neutraal)** — andere vormen voor teams die geen 4-3-3 spelen.

  De picker is een *probeer-dit-preview*. Om de standaardformatie van een team in te stellen, gebruik je de team-bewerken-pagina (admin of hoofd-academie). Aangepaste sjablonen kunnen vandaag via de REST API worden toegevoegd; een admin-UI volgt later.
- **Stijlmix** — schuiven voor balbezit, counter en press. De drie gewichten moeten samen 100 zijn.
- **Zijvoorkeur** — instelbaar op het spelerprofiel (links / rechts / centrum). Voegt ±0,2 toe aan fit-scores bij overeenkomst / mismatch tegen een zijgebonden slot.

## Cache + herberekening

Fit-scores per speler worden 24 uur gecachet. Het opslaan van een evaluatie voor een speler vernietigt die cache van de speler, zodat het bord altijd de nieuwste beoordelingen weerspiegelt binnen enkele minuten. Geen handmatige verversing nodig.

## Vertrouw de onderbouwing

Als een score niet matcht met je gevoel, houd de muis erboven. Elke bijdrage staat er meteen: beoordeling × weging per categorie, plus de zij-modifier. De getallen zijn een gereedschap; de coach beslist.
