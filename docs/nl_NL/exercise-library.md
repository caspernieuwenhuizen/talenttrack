---
title: Oefeningenbibliotheek
group: performance
summary: Elke oefening waaruit de club een training kan opbouwen, op één plek.
audience: [user, admin]
views: [exercises]
module: TT\Modules\Exercises\ExercisesModule
order: 95
---

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
- **Intensiteit** — 1 tot en met 7, voor de oefeningen die een band hebben.
- **Status** — actief of gearchiveerd.

## Zelf een oefening toevoegen

Open **Oefening toevoegen**. Alleen de naam is verplicht; de rest helpt de
generator later een goede keuze te maken, dus vul in wat je weet:

| Veld | Wat het doet |
| --- | --- |
| Categorie | Groepeert de oefening en vertelt de generator in welk blok hij past. |
| Gebruikelijke duur | De standaardlengte zodra hij in een plan komt. |
| Kleinste / grootste groep | Filtert de oefening weg bij een verkeerde groepsgrootte. |
| Intensiteit | 1–7. Wordt gebruikt om een training binnen het leeftijdsplafond te houden. |
| Omschrijving | Organisatie, regels, scoreafspraken. |
| URL van diagramafbeelding | Een afbeelding van de opstelling. |

### Wat de intensiteitsbanden betekenen

De schaal loopt van **1 tot en met 7**. Het loont om consequent te scoren: dit
getal wordt vergeleken met het leeftijdsplafond, dus een oefening die te laag
staat glipt langs een waarschuwing die had moeten afgaan.

| Band | Waar het voor staat |
| --- | --- |
| 1–2 | Herstel en techniek. Nauwelijks fysieke belasting. |
| 3–4 | Rustig doorwerken. Een rondo of positiespel op een behapbaar tempo. |
| 5 | Een normaal trainingsblok — de intensiteit waar het grootste deel van een training op zit. |
| 6 | Echt veeleisend. Pressingvormen, herhaalde sprints, partijspel op vol tempo. |
| 7 | Zo zwaar als een leeftijdsgroep in de academie ooit zou moeten gaan. |

Hoger dan 7 bestaat niet. Het hoogste plafond dat een leeftijdsprofiel kent is 7
(JO13 en JO14; JO10 stopt bij 3), dus een hoger getal zou een training
beschrijven die geen enkele groep mag doen.

### Wie ziet wat je toevoegt

**Een nieuwe oefening hoort bij je eigen team en je kunt hem meteen in je
plannen gebruiken.** Er hoeft niets goedgekeurd te worden.

Of de *rest van de club* hem ook krijgt is een aparte beslissing, genomen door
het hoofd opleidingen. Jouw team gebruikt hem hoe dan ook.

Je kunt een oefening ook op **Alleen ikzelf** zetten zolang je er nog aan werkt.

### Veel oefeningen in één keer

Staan de oefeningen van de club al in een spreadsheet, dan hoef je ze niet over
te typen. Boven **Oefening toevoegen** staat een link naar **Oefeningen
importeren uit CSV**.

Sla de spreadsheet op als `.csv`-bestand met een kopregel die de kolommen
benoemt en upload het. Er wordt nog niets opgeslagen: je krijgt eerst een
controlescherm te zien met elke rij die een probleem heeft, en waarom. Pas als
je op **Oefeningen importeren** drukt, wordt er iets weggeschreven.

Een rij die mislukt houdt de rest niet tegen. Elke goede rij wordt opgeslagen en
de mislukte rijen krijg je terug als bestand dat je kunt corrigeren en opnieuw
uploaden — de reden staat er als extra kolom bij, zodat je ze in de spreadsheet
kunt herstellen.

Welke kolommen het bestand mag bevatten staat op het importscherm zelf onder
**Toegestane kolommen**. Drie dingen zijn goed om vooraf te weten:

- **Vul `principle_codes` in waar je kunt.** Dat is de kolom die bepaalt of de
  oefening bruikbaar is. Een oefening zonder principes kan nog steeds gekozen
  worden voor een training, maar de planner kan er nooit de *voorkeur* aan
  geven — een grote bibliotheek zonder principes gedraagt zich dus als een lege.
  Scheid meerdere codes met een puntkomma.
- **Een getal buiten zijn bereik laat die rij mislukken.** Het wordt niet naar
  het bereik toe afgerond. Is een kolom op de verkeerde schaal ingevuld, dan
  hoor je dat, in plaats van dat elke rij stilletjes wordt aangepast.
- **Oefeningen komen binnen bij je eigen team**, precies zoals wanneer je er met
  de hand één toevoegt. Clubbreed publiceren blijft een beslissing van het hoofd
  opleiding.

Heeft je spreadsheet een kolom **organisation**, dan wordt die achter de
omschrijving gezet — hetzelfde veld dat het formulier aanbiedt. Voor
**coachpunten** per oefening is geen plek: die horen bij een blok in een
trainingsplan, omdat dezelfde oefening anders gecoacht wordt afhankelijk van
waar de training voor dient.

## De bibliotheek classificeren

Een oefening zonder principes wordt **nooit voorgesteld door de generator**, en
de tijd die eraan besteed wordt **telt niet mee voor wat je spelers geleerd
hebben**. Allebei die gevolgen zijn onzichtbaar vanuit de lijst, en juist daarom
blijft een ongeclassificeerde bibliotheek dat meestal.

Zodra er oefeningen wachten, zegt de bibliotheek hoeveel het er zijn en biedt
**Classificeren** aan. Dat scherm draait om één handeling: **vink meerdere
oefeningen aan, kies de principes die ze trainen, pas in één keer toe.** Een
geclassificeerde oefening heeft er ongeveer acht, dus dit één voor één doen zijn
honderden losse keren opslaan — een hele categorie selecteren en in één keer
toepassen is het verschil tussen een middag en veertien dagen.

Het scherm is gegroepeerd op categorie, omdat oefeningen van dezelfde soort
meestal dezelfde principes trainen. Met **Alles hierboven selecteren** pak je een
hele groep tegelijk.

| Keuze | Wat het doet |
| --- | --- |
| Toevoegen aan wat ze al hebben | Laat bestaande principes staan. De veilige standaard voor bulk. |
| Vervangen wat ze hebben | Wist en zet opnieuw. Alleen voor de methodiek waarin je werkt — principes van een andere methodiek blijven onaangeroerd. |
| Geen van toepassing | Markeert de oefening als bekeken, zonder principes. |

**"Geen van toepassing" is wat je in staat stelt klaar te komen.** Warming-ups,
cooling-downs en conditieoefeningen horen meestal geen tactisch principe te
dragen — een warming-up traint niet het opbouwen vanaf de keeper. Door ze te
markeren blijven ze uit de lijst, zodat de teller echt naar nul gaat in plaats
van je eeuwig dezelfde warming-ups te laten zien.

Het scherm noemt de methodiek waaraan het koppelt. Heeft jouw academie er
meerdere, dan worden principes aan de actieve gekoppeld.

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

## Een scène tekenen

Een **scène** is een klein bewegend diagram van de oefening: spelers,
tegenstanders, de bal, pionnen en doelen op een veld, met de bewegingen die je
ze wilt laten maken. Open een oefening en druk op **Scène tekenen**.

De editor is om één handeling heen gebouwd: **sleep een markering over het veld
en er wordt vastgelegd waar die markering op dat moment staat.** Spoel door
naar twee seconden, zet de linksback naar voren, en de linksback loopt nu in
die twee seconden naar voren. Meer is er niet nodig om een scène te laten
bewegen.

De rest van het scherm is er voor de momenten dat slepen niet is wat je wilt:

- **Markering toevoegen** — kies links een speler, tegenstander, keeper, bal,
 pion of doel en tik daarna op het veld. Het gereedschap blijft geselecteerd,
 dus je zet een heel elftal neer zonder telkens terug te gaan.
- **Lijnen** — kies een lijnsoort (pass, dribbel, loopactie, schot, druk
 zetten) en tik daarna op twee markeringen. De lijn wordt getrokken tussen de
 plek waar die twee markeringen op dat moment *staan*, dus hij klopt nog
 steeds als je er later één verplaatst.
- **De tijdlijn** — één rij per markering, één ruitje per vastgelegde positie.
 Tik op een ruitje om naar dat moment te springen; sleep het opzij om te
 veranderen wanneer het gebeurt.
- **Geselecteerde markering** — het rugnummer, de precieze positie, en knoppen
 om te dupliceren of te verwijderen.
- **Ongedaan maken** neemt de laatste wijziging terug, tot veertig stappen ver.
 Ctrl+Z werkt ook.

Met de pijltjestoetsen verplaats je de geselecteerde markering stap voor stap,
dus je kunt een scène ook zonder muis opbouwen. Er wordt niets bewaard tot je
op **Opslaan** drukt.

**Veld** en **Duur** bepalen waarop de scène wordt getekend en hoe lang die
loopt. Een rondo op een heel veld is zes spelers in een hoekje, dus kies het
halve veld of het vierkant als dat is wat je daadwerkelijk uitzet.

### Waar een scène terugkomt

Zodra de scène is opgeslagen, verschijnt dezelfde scène op drie plekken,
getekend door dezelfde code, zodat hij er altijd hetzelfde uitziet:

- op de **oefeningpagina**, met afspeelknoppen;
- in het **veldscherm** terwijl je de training draait;
- op het **geprinte A4** — als stilstaand beeld, want papier kan niet
 animeren. Dat beeld is het laatste frame van de scène.

Een oefening kan meer dan één scène bevatten; zo laat je een oefening met
fases zien. De eerste die je tekent is de scène die op die drie plekken
verschijnt; druk op **Scène toevoegen** voor de volgende.

Tekenen gaat het best op een tablet of desktop. Op een telefoon kun je een
scène bekijken en een markering verplaatsen, maar de tijdlijn wil meer ruimte
dan een telefoon biedt.

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

## Op je abonnement

De oefeningenbibliotheek is een **Pro**-functie. Op Standard blijft de bibliotheek volledig doorzoekbaar; oefeningen toevoegen, wijzigen en importeren zijn vergrendeld. Zie [Licentie en account](license-and-account.md).
