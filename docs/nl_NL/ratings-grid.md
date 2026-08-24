---
title: Beoordelingsraster
group: match-day
summary: Beoordeel een hele selectie vanuit één raster in plaats van speler voor speler.
audience: [user]
views: [ratings-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: ratings_grid
order: 50
---

# Beoordelingsraster

*Doelgroep: trainers, hoofden ontwikkeling, academiebeheerders.*

Het **beoordelingsraster** is de desktopmanier om na een sessie een hele selectie te beoordelen, zoals in een spreadsheet: de spelers staan onder elkaar, de categorieën waarop de activiteit wordt beoordeeld staan naast elkaar, en elke cel is één cijfer dat je zelf typt. Eén keer **Opslaan** legt alles vast.

Het is het derde invoerraster (na [aanwezigheid](attendance-grid.md) en speelminuten) en de tegenhanger van de stapsgewijze beoordelingswizard, die precies blijft zoals hij was — op een telefoon, langs het veld, is de wizard nog steeds het juiste gereedschap.

## Waarom dit raster per activiteit werkt

De rasters voor aanwezigheid en minuten tonen een hele *periode*: spelers tegenover veel activiteiten. Voor beoordelingen kan dat niet zonder detail te verliezen. Een beoordeling is geen enkel cijfer maar een cijfer per categorie, dus een raster van spelers × activiteiten zou meerdere cijfers in één cel moeten persen en je een berekend gemiddelde tonen in plaats van wat je typte.

Daarom zet het beoordelingsraster de activiteit vast en maakt het de *categorieën* tot kolommen. Elke cel blijft een echt cijfer, er wordt niets afgeleid en er is geen pop-upeditor. De afweging is bewust: je beoordeelt één sessie tegelijk — precies het moment waarop trainers toch beoordelen.

## Zo kom je er

Open de activiteit en klik op **Beoordelingsraster**. De knop verschijnt als je activiteiten mag bewerken, de activiteit een team heeft en het raster voor jouw academie aanstaat — en hij blijft zichtbaar of de wizards nu aan of uit staan, zodat een academie zonder wizards toch kan beoordelen.

## Gebruik

- **Kolommen** zijn de categorieën die het beoordelingstype van de activiteit voorschrijft. Schrijft dat type er geen voor, dan verschijnen alle actieve categorieën. Categorienamen staan er in je eigen taal, net als op het beoordelingsformulier; een categorie die nog niemand heeft vertaald houdt zijn oorspronkelijke naam.
- **Hoofdcategorieën voeren hun eigen blok kolommen aan.** De kop heeft twee rijen: bovenaan de hoofdcategorie, die alles eronder overspant, en daaronder de subcategorieën. Een hoofdcategorie die je rechtstreeks beoordeelt houdt zijn eigen kolom, met het kopje *Hoofdcijfer*, naast de subs — zo beoordeel je op hoofdniveau, op subniveau, of allebei.
- **Subcategorieën staan standaard dichtgeklapt.** Klik op de kop van een hoofdcategorie om de subs open te vouwen en klik nog eens om ze weer weg te vouwen; elke hoofdcategorie opent los van de andere, dus je gaat de diepte in waar het ertoe doet en houdt de rest compact. Een hoofdcategorie zonder subs is gewoon één kolom en valt niet open te klappen. Een hoofdcategorie die je niet rechtstreeks beoordeelt houdt dichtgeklapt een lege kolom over — daar staat de kop op, en er valt niets in te typen.
- **Openstaand werk blijft altijd zichtbaar.** Klap je een hoofdcategorie dicht terwijl daaronder nog cijfers openstaan, dan toont de kop er het aantal van en meldt de regel onder het raster het. Die cijfers worden gewoon meegenomen als je op Opslaan drukt. Een cijfer buiten de schaal klapt zijn hoofdcategorie vanzelf weer open, want opslaan kan pas als het klopt.
- **Al uitgewerkte beoordelingen openen uitgeklapt.** Heeft iemand voor deze activiteit al een subcategorie beoordeeld, dan staat die hoofdcategorie meteen open, zodat je de bestaande cijfers ziet in plaats van een dichtgeklapt raster dat ze verbergt.
- **Rijen** zijn de actieve spelers van het team.
- **Cijfers** volgen de schaal van je academie (standaard 5 tot 10 in stappen van een half punt). Een cijfer buiten de schaal, of een cijfer dat niet op een stap uitkomt, wordt meteen tijdens het typen gemarkeerd: de cel kleurt rood en de regel onder het raster vertelt wat wel mag. Opslaan blijft uitgeschakeld tot je het hebt aangepast, zodat een cijfer nooit stilletjes niet wordt opgeslagen.
- **Wat je typt wordt nooit stiekem aangepast.** Een 12 blijft een 12 op je scherm tot je hem zelf verandert — het raster maakt er geen 10 van en rondt een 7,3 niet ongemerkt af naar 7,5. Wordt er iets geweigerd, dan krijg je dat te zien en blijft de cel als niet-opgeslagen gemarkeerd.
- **Een lege cel betekent "niet beoordeeld".** Er wordt niets weggeschreven en een al vastgelegd cijfer wordt nooit gewist — wissen doe je op het beoordelingsformulier.
- **Gewijzigde cellen worden gemarkeerd** tot je op Opslaan drukt, zodat je ziet wat nog openstaat.
- **Toetsenbord**: pijltjes gaan van cel naar cel, Enter gaat een rij omlaag (zoals je één categorie door de selectie heen beoordeelt), Tab bereikt eerst Annuleren en dan Opslaan.
- **Opslaan is expliciet.** Er wordt niets weggeschreven tot je erop drukt, en Annuleren brengt je terug naar de activiteit zonder op te slaan.

Twee keer opslaan is veilig: het raster werkt de bestaande beoordeling van de speler voor die activiteit bij in plaats van een tweede aan te maken, dus cijfers stapelen zich nooit op.

## Wat het niet doet

- **Het vervangt het beoordelingsformulier niet.** Notities, spelersfeedback en alles buiten de categoriecijfers blijven daar.
- **Het berekent geen totaalcijfer.** Het gewogen totaal wordt zoals altijd bij het tonen berekend — het raster schrijft er nooit een weg.
- **Het is alleen voor desktop.** Onder 1024px verwijst het naar de wizard in plaats van een onbruikbare tabel op een telefoon te tonen.

## Uitschakelen

*Modules → Activiteiten → Beoordelingsraster.* Staat het uit, dan verdwijnt de knop, wordt de URL geweigerd en werken de wizard en het beoordelingsformulier ongewijzigd door.
