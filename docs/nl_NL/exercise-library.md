<!-- audience: user, admin -->

# Oefeningenbibliotheek

Alle oefeningen waaruit de club een training kan opbouwen staan op één plek.
Open de tegel **Training** en kies **Oefeningen**.

Tot voor kort had TalentTrack twee catalogi die elkaar niet konden zien: de
algemene oefeningenbibliotheek en de conditionele oefeningen van VCT. Die zijn
nu samengevoegd. Een VCT-oefening heeft het label **Uit VCT**; de oefeningen
die met TalentTrack meekomen zijn gemarkeerd als **Meegeleverd**.

## Een oefening vinden

Zoek op naam, code of omschrijving, en verfijn met de filters:

- **Categorie** — warming-up, rondo, positiespel, partijvorm, enzovoort.
- **Zichtbaar voor** — hele club, één team, of alleen jij.
- **Intensiteit** — 1 (herstel) tot en met 5 (maximaal), voor de oefeningen
  die een band hebben.
- **Status** — actief of gearchiveerd.

## Zelf een oefening toevoegen

Open **+ Nieuwe oefening**. Alleen de naam is verplicht; de rest helpt de
generator later een goede keuze te maken, dus vul in wat je weet:

| Veld | Wat het doet |
| --- | --- |
| Categorie | Groepeert de oefening en vertelt de generator in welk blok hij past. |
| Gebruikelijke duur | De standaardlengte zodra hij in een plan komt. |
| Kleinste / grootste groep | Filtert de oefening weg bij een verkeerde groepsgrootte. |
| Intensiteit | 1–5. Wordt gebruikt om een training binnen het leeftijdsplafond te houden. |
| Omschrijving | Organisatie, regels, scoreafspraken. |
| URL van diagramafbeelding | Een afbeelding van de opstelling. |

### Wie ziet wat je toevoegt

**Een nieuwe oefening hoort bij je eigen team en je kunt hem meteen in je
plannen gebruiken.** Er hoeft niets goedgekeurd te worden.

Of de *rest van de club* hem ook krijgt is een aparte beslissing, genomen door
het hoofd opleidingen. Jouw team gebruikt hem hoe dan ook.

Je kunt een oefening ook op **Alleen ikzelf** zetten zolang je er nog aan werkt.

## Een oefening clubbreed maken

Ben je hoofd opleidingen of academy-beheerder, dan toont de bibliotheek het
paneel **Toegevoegd door teams**: de oefeningen die trainers voor hun eigen
team hebben gemaakt, met hoeveel plannen elk ervan al gebruiken. Druk op
**Clubbreed maken** bij de oefeningen die bij de methodiek van de academie
passen.

Zodra een oefening clubbreed is, verschijnt hij bij elk team en komt hij in de
vijver waaruit de trainingsgenerator kiest.

## Bewerken

Een oefening bewerken maakt een **nieuwe versie** in plaats van de oude te
overschrijven. Plannen en trainingen die de vorige versie al gebruikten blijven
die precies zo tonen. Daardoor kun je een oefening blijven verbeteren zonder je
eigen geschiedenis te herschrijven.

Oefeningen met het label **Meegeleverd** of **Uit VCT** kun je hier niet
bewerken. Maak er een kopie van om een eigen versie te krijgen.

## Wie mag wat

| | Bekijken | Toevoegen en bewerken | Clubbreed maken |
| --- | --- | --- | --- |
| Trainer / assistent-trainer | ja | ja | nee |
| Hoofd opleidingen | ja | ja | ja |
| Academy-beheerder | ja | ja | ja |

Het recht om te bepalen waar de hele club uit traint volgt het recht om de
methodiek van de academie te bewerken, omdat de clubbrede bibliotheek daar deel
van uitmaakt.

## Voor beheerders

Vóór deze release dekte de VCT-rechtcode `tt_vct_admin_library` drie dingen af:
de oefeningencatalogus, de VCT-leeftijdsprofielen en de macroblokken. Nu er één
bibliotheek is, is de **bibliotheek** verhuisd naar het oefeningenrecht dat
trainers al hadden, en houdt de rest een recht dat alleen het hoofd opleidingen
heeft, onder een duidelijkere naam: `tt_vct_admin_config`.

Niemand heeft bij die verhuizing rechten gekregen of verloren. Met name de
**leeftijdsprofielen**, die de leeftijdsveilige intensiteitsplafonds voor
O10–O14 bepalen, blijven voorbehouden aan het hoofd opleidingen en de
academy-beheerder.
