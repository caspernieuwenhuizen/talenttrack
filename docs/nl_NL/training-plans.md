---
title: Trainingsplannen
group: planning
summary: Bouw een herbruikbare training uit blokken en draai die bij een activiteit.
audience: [user]
views: [training-plan, training-plans, training-run, training-coverage, training-photo]
module: TT\Modules\Training\TrainingModule
order: 20
---

# Trainingsplannen

Een **trainingsplan** is de inhoud van één training: de blokken die je draait,
op volgorde, met per blok een duur. Het staat op zichzelf en niet vast aan één
datum, zodat je het opnieuw kunt gebruiken, kunt aanpassen voor een ander team
of kunt bewaren als clubsjabloon.

Open de tegel **Training** in de groep **Planning & tactiek**.

## Plannen en sjablonen

De lijst bevat twee soorten records:

- Een **teamplan** hoort bij één team. Dat is het normale geval: de training
 die je voor dinsdag maakt.
- Een **clubsjabloon** hoort bij geen enkel team. Het is een startvorm —
 "standaard MD-3, 75 minuten" — die elke trainer kan kopiëren en aanpassen.

Filter ertussen met het keuzemenu **Soort**. De rest van de lijst werkt zoals
de andere lijsten in TalentTrack: zoeken op naam, sorteren op kolom en met de
statuspillen wisselen tussen **Actief** en **Gearchiveerd**.

## Wat er in een plan staat

Open een plan en je ziet:

- **De kerngetallen** — totale duur, aantal blokken, of het een teamplan of
 een clubsjabloon is, en het thema waarop het werkt.
- **De tijdbalk** — een verhoudingsbalk die laat zien hoe de training over de
 blokken verdeeld is, met een kleur per bloktype. Diezelfde zes kleuren
 worden overal in de Training-module gebruikt, zodat je de vorm van een
 training in één oogopslag herkent.
- **De blokken** — elk op volgorde, met type, duur, de oefening waaruit het
 put, de organisatie en de coachpunten.
- **Uitvoeringen van dit plan** — elke training waarvoor dit plan echt is
 gebruikt.

## Een plan aanpassen verandert nooit de geschiedenis

Dit is het onderdeel dat je moet begrijpen, want het is precies wat de
trainingsgegevens betrouwbaar maakt.

Koppel je een plan aan een training, dan maakt die **uitvoering** een eigen,
blijvende kopie van de blokken zoals ze die dag waren. Daarna kun je het plan
hernoemen, de duur van een blok wijzigen, een blok verwijderen of toevoegen,
of het plan helemaal archiveren — en de training die al geweest is laat nog
steeds zien wat er werkelijk in zat.

Een niveau lager geldt hetzelfde: een blok verwijst naar een specifieke versie
van een oefening. Pas je de oefening in de bibliotheek aan, dan ontstaat er een
nieuwe versie en blijft elk plan dat de oude gebruikte ongemoeid.

Je kunt een plan dus rustig blijven verbeteren zonder je eigen geschiedenis te
beschadigen.

## Archiveren

Archiveren haalt een plan uit de actieve lijst. Het raakt de al uitgevoerde
trainingen **niet** — een plan dat verdwijnt mag nooit een training meenemen
die er echt geweest is. Zet het statusfilter op **Gearchiveerd** om het terug
te vinden.

Wordt een team verwijderd, dan verdwijnen zijn plannen niet mee. Ze verliezen
hun team en worden clubbreed, zodat het werk van een trainer een
seizoensovergang overleeft.

## Een plan maken

Druk op **Nieuw plan**. Vier korte vragen, dan een kant-en-klare training:

1. **Wanneer** — het team en de datum. De leeftijdscategorie, het aantal dagen
 tot de eerstvolgende wedstrijd en waar je in het seizoen zit worden voor je
 afgeleid.
2. **Thema** — waar de training over gaat. Bij elke optie staat hoeveel
 oefeningen je bibliotheek ervoor heeft, zodat je nooit een richting op
 wordt gestuurd waar niets achter zit.
3. **Vorm** — hoe lang, en hoeveel spelers je verwacht. Dat aantal komt uit de
 recente opkomst van dit team en niet uit de selectielijst, want een
 selectie van zestien zet zelden zestien spelers op het veld. Pas het aan
 wanneer je het beter weet — een schoolreisje staat niet in de data.
4. **Voorstel** — het concept. Ga terug en verander wat je wilt; er wordt
 niets opgeslagen tot jij dat zegt.
5. **Controle** — aan welke open doelen van je spelers deze training werkt, en
 daarna opslaan.

### Wat de generator wel en niet doet

- **Elke oefening komt uit je eigen bibliotheek.** Er wordt niets verzonnen.
- **Niets gaat boven het intensiteitsplafond van de leeftijdscategorie.** Een
 JO13-training stelt nooit een oefening voor die zwaarder is dan JO13 toestaat.
- **Dezelfde antwoorden geven altijd dezelfde training.** Er wordt niet geloot.
- **Een oefening komt nooit twee keer voor in één training.**
- **Heeft je bibliotheek niets passends voor een onderdeel, dan blijft dat blok
 leeg en staat erbij waarom** — in plaats van het op te vullen met iets dat
 er niet bij hoort.

### Voor welke leeftijdscategorieën de generator een concept maakt

Een concept maken vraagt om een **leeftijdsprofiel**: de maximale trainingsduur
en het intensiteitsplafond voor die categorie. Zonder profiel is er niets veiligs
om binnen te plannen, dus de generator stopt in plaats van te gokken. Er zijn
twee verschillende redenen om te stoppen, met een verschillende betekenis:

- **De jongste categorieën hebben bewust geen belastingmodel.** Op die leeftijd
  wordt trainingsbelasting niet in getallen gepland. De generator maakt voor hen
  nooit een concept, en zegt dat ook zo — in plaats van te suggereren dat er een
  instelling ontbreekt. Bouw de training zelf; het plan houdt hem net als elke
  andere.
- **Een categorie boven dat bereik heeft nog geen profiel.** Dat is wél op te
  lossen. Wie het recht op VCT-configuratie heeft — normaal gesproken de Hoofd
  Opleiding — voegt het profiel toe onder **VCT-configuratie → Leeftijdsprofielen**,
  en vanaf dan werkt het concept voor die teams.

Standaard loopt het gemodelleerde bereik van JO10 tot en met JO14. JO15 en hoger
toevoegen is een beslissing over de belastingplafonds van je eigen academie, en
juist daarom zijn die getallen van jou en worden ze niet meegeleverd.

### Waarom de ene training beter bij je spelers past dan de andere

De generator geeft voorrang aan oefeningen die een principe trainen waar je
spelers open doelen op hebben: een oefening die zes van hen nodig hebben wint
van een oefening waar niemand aan werkt.

Daarvoor moeten twee dingen gekoppeld zijn: oefeningen met de principes die ze
trainen (het veld **traint welke principes** in de bibliotheek, automatisch
ingevuld voor oefeningen die al een thema hadden) en doelen die een principe
noemen. Zolang de doelen van een selectie geen principes noemen, zegt de
controlestap dat gewoon — in plaats van een zelfverzekerde nul te tonen.

## Een plan aanpassen

De generator geeft je een concept; jouw vakmanschap maakt er een training van.
Open een plan en klik op **Blokken bewerken**.

### Volgorde veranderen

Elk blok heeft een **↑**- en een **↓**-knop. Dat is de gewone manier om de
volgorde te veranderen: op elk schermformaat, en met het toetsenbord — tab
naar een knop en druk op Enter. Op een breed scherm kun je een blok ook
verslepen aan het handvat rechts, maar dat hoeft nooit.

Er wordt niets weggeschreven tot je op **Plan opslaan** klikt, dus schuiven
kost je niets tot je het vastlegt.

### De duur van een blok veranderen

Met **−** en **+** verandert een blok in stappen van vijf minuten. De tijdbalk
en het totaal erboven lopen mee, zodat je de opbouw van de training ziet
veranderen in plaats van zelf te rekenen.

Het totaal is wat het plan wordt. Er is geen streefduur die gehaald moet
worden — heb je het veld een uur, bouw dan een uur.

### Een oefening vervangen

**Oefening vervangen** opent je bibliotheek. Op een telefoon schuift die van
onderen omhoog, binnen bereik van je duim; op een desktop verschijnt hij
rechtsonder.

De lijst is **gesorteerd op hoeveel open spelerdoelen van dit team elke
oefening bedient**, en bij elke regel staat dat aantal. Een oefening die zes
spelers bedient waar je selectie echt aan werkt, staat boven een oefening die
niemand bedient — en je ziet waarom.

Met zoeken beperk je de lijst op naam of code.

### Toevoegen, verwijderen en coachpunten

**Blok toevoegen** zet een leeg blok onderaan — kies het soort en vervang er
dan een oefening in. Een blok zonder oefening mag: een nabespreking of een
doorloopmoment heeft geen oefening nodig.

Elk blok heeft een veld voor **coachpunten**: wat je die avond wilt zeggen.

### Spelerdoelen die dit plan raakt

Naast de blokken (eronder op een telefoon) staat het paneel dat de hele module
de moeite waard maakt: **welke spelers dit plan echt bedient, met naam**.

Spelers met een open doel op een principe dat het plan traint, staan er
afzonderlijk in. Daaronder staan ook de spelers met een open doel dat het plan
juist **niet** raakt — want dat is de lijst waar je vóór dinsdag nog iets mee
kunt.

Het paneel loopt mee met elke keer dat je opslaat, dus je kunt een blok
vervangen en meteen zien wie erbij komt of wegvalt.

Staat er dat er niets is om mee te vergelijken, dan heeft nog geen speler in
de selectie een open doel dat aan een principe hangt. Zie hierboven bij
*Waarom de ene training beter bij je spelers past dan de andere*.

### Een plan hergebruiken

Onder de blokkenlijst staan twee knoppen:

- **Opslaan als clubsjabloon** maakt een clubbrede kopie zonder team — de
 training die goed werkte wordt een vertrekpunt waar iedereen op kan
 voortbouwen.
- **Kopiëren naar een nieuw plan** maakt een zelfstandige kopie voor hetzelfde
 team; de snelste route naar de training van volgende week.

Allebei kopiëren ze het **opgeslagen** plan, dus sla je wijzigingen eerst op —
de knoppen zeggen het als je dat nog niet hebt gedaan.

Een kopie staat echt op zichzelf: als je die later aanpast, verandert het plan
waar je hem van kopieerde niet.

## Een plan uitvoeren

Een plan wordt een training zodra je het aan een training koppelt.

### Koppelen

Open de training in de agenda en klik op **Deze training uitvoeren**. Kies het
plan en klik op **Plan koppelen**.

Het plan wordt **op de training gekopieerd zoals het op dat moment is**. Alles
wat daarna volgt leest die kopie, dus als je het plan later aanpast — ook
dezelfde avond nog — verandert er niets aan wat de training heeft vastgelegd.

Hangt er al een plan aan, dan staat er **Ga verder met deze training** en ga je direct
naartoe. Twee keer koppelen is geen fout en overschrijft de eerste kopie
nooit: een training die al geweest is houdt zijn eigen registratie.

### De weergave langs de lijn

Dit is het scherm dat je op het veld vasthoudt, en het is daarom anders
gebouwd dan de rest van TalentTrack: donker, één blok tegelijk, grote knoppen
onderin waar je duim al is.

- **Start de training** als je begint. Het eerste blok opent met een klok.
- De klok loopt **op**, tegen de geplande duur van het blok. Er gaat niets
 vanzelf verder — jij bepaalt wanneer een blok klaar is.
- **Uitlopen mag.** Het scherm kleurt oranje en zegt hoeveel je uitloopt en
 wat er wordt vastgelegd als je nu afrondt. Het zeurt niet en het houdt je
 niet tegen.
- **Blok afronden** legt vast hoe lang het werkelijk duurde en opent het
 volgende.
- **Blok overslaan** legt vast dat het niet is gedaan. Het blok blijft in het
 plan staan; alleen deze training legt de overslag vast.
- **‹ en ›** verplaatsen je tussen blokken zonder iets vast te leggen, om
 vooruit te kijken.
- **Training afronden** sluit de sessie. Blokken die je niet hebt gedaan
 worden vastgelegd als overgeslagen.

Aan het eind zie je de totalen: werkelijk getrainde minuten tegen geplande
minuten, hoeveel blokken je hebt gedaan en hoeveel je hebt overgeslagen.

**Dit scherm heeft verbinding nodig.** Val je halverwege weg, dan zegt het dat
het opslaan mislukt is in plaats van te doen alsof het gelukt is. Offline
werken komt apart.

### De papieren versie

Klik op **Afdrukken** bij een plan voor een A4'tje dat je mee kunt nemen: elk
blok met begintijd, duur, organisatie en coachpunten, bij een normale training
op één pagina.

Heeft een speler in het team een groeispurtplafond dat lager ligt dan het
zwaarste blok in het plan, dan staat dat er met naam bij — degene die het
papier vasthoudt is degene die er iets mee moet.

Het blad is het plan, niet de registratie. Wat je werkelijk doet wordt
vastgelegd bij de training.

### Als het bereik wegvalt

Juist op het veld is het bereik het slechtst, dus het veldscherm werkt door
zonder. Tik een blok af, sla er een over of schrijf een observatie zonder
streepjes: het wordt op de telefoon bewaard in plaats van verloren te gaan.
Bovenin staat hoeveel wijzigingen wachten — *"2 wijzigingen wachten op
bereik"* — en ze versturen zichzelf zodra je weer verbinding hebt.

Het overleeft het vergrendelen van de telefoon, wisselen van app en het
herladen van de pagina. Naar binnen lopen kost je niets.

Twee dingen om te weten:

- **De pagina openen kost nog steeds bereik.** Beschermd is een training die
 al loopt; je kunt er niet vanaf nul een starten zonder verbinding.
- **Niets wordt dubbel vastgelegd.** Als een wijziging wel verstuurd is maar
 het antwoord nooit terugkomt, probeert de telefoon het opnieuw — en die
 tweede poging komt op dezelfde registratie terecht in plaats van een
 duplicaat te maken. Dat is belangrijk, want deze getallen worden de
 trainingsminuten van elke speler.

Lukt het na het herverbinden nog steeds niet — omdat je zo lang weg bent
geweest dat je login is verlopen — dan blijft de wijziging in de wachtrij in
plaats van weggegooid te worden. Herlaad de pagina en hij gaat alsnog.

## Wat een speler werkelijk heeft geleerd

Hier werkt de rest van de module naartoe.

### Op het spelersdossier

Open een speler en kies het tabblad **Training**.

- **De kerngetallen** — getrainde minuten, hoeveel principes uit de methodiek
 zijn geraakt van het totaal, en wanneer er voor het laatst is getraind.
- **Minuten per principe** — elk principe uit je methodiek, met wat deze
 speler eraan heeft besteed. **De principes die nooit zijn getraind staan er
 ook bij, bovenaan, gemarkeerd.** Dat is met opzet: een lege regel is het
 nuttigste op de pagina, en een lijst die ze stilletjes zou weglaten ziet er
 compleet uit terwijl hij verbergt waarvoor je het tabblad opende.
- **Recente waarnemingen** — wat trainers tijdens trainingen over deze speler
 hebben genoteerd.

De minuten komen uit trainingen waar de speler ook echt bij was. Aanwezig en
te laat tellen mee; afgemeld, afwezig en geblesseerd niet. Een overgeslagen
blok telt niet mee, en een blok dat zevenentwintig minuten duurde telt voor
zevenentwintig — niet voor de tweeëntwintig die iemand in het plan zette.

Speelde een speler mee met een ander team, dan staan die minuten ook op zijn
of haar eigen dossier.

### Wie het kan zien

Trainers zien het voor spelers van hun eigen teams. De hoofd opleidingen en
academiebeheerders zien het voor iedereen. **Een ouder ziet alleen het eigen
kind** — en een speler kan het voor de ouder helemaal uitzetten, bij
*Mijn instellingen → wat je ouder kan zien*, naast evaluaties, doelen,
metingen en het PDP.

### Academiebreed: de dekkingsmatrix

Hoofd opleidingen en academiebeheerders krijgen op de Training-pagina de knop
**Dekking**: elk principe langs de zijkant, elk team bovenaan, en hoeveel
trainingen dat team eraan heeft besteed.

Alleen "nooit" is gemarkeerd. Vier tinten van bijna-goed verbergen juist het
enige waar je iets mee moet.

### Een waarneming vastleggen

Tijdens een training toont de weergave langs de lijn iedereen die er is, met
een schaal onder de naam en een veld voor een notitie.

- Je hoeft niemand een cijfer te geven. Een notitie alleen is een volledige
 waarneming, en op een natte dinsdag is dat de normale gang van zaken.
- Tik nogmaals op een cijfer om het te wissen.
- De schaal is die van je eigen academie — het bereik en de stapgrootte die je
 voor evaluaties hebt ingesteld.
- Een cijfer buiten dat bereik wordt geweigerd, niet naar binnen afgerond.

Elke waarneming verschijnt meteen op de **Reis** van de speler, met de datum
van de training en niet van het moment waarop je het intypte.

### Wanneer de getallen bijwerken

Direct als je een training afrondt, voor de spelers die erbij waren. En elke
nacht volledig, wat een later aangepast plan opvangt, een oefening die een
ander principe kreeg, of aanwezigheid die de volgende ochtend is gecorrigeerd.

## Een uitgeschreven plan fotograferen

Staat dit aan bij jouw academie, dan maakt **Vanaf een foto** op de pagina met
trainingsplannen van een uitgeschreven blad een concept. Fotografeer het blad,
controleer wat er gelezen is, en druk op **Concept aanmaken**. Er wordt niets
opgeslagen tot je dat doet — sluit je de pagina tijdens het controleren, dan is
er geen plan en staat er nergens een foto.

Het controleren is waar dit scherm om draait, dus je ziet per regel hoe zeker
het is:

| | Wat het betekent |
| --- | --- |
| Groen | Met vertrouwen gekoppeld aan een oefening uit je bibliotheek. |
| Oranje | Lijkt op meer dan één oefening. Even naar kijken. |
| Rood | Helemaal niet herkend. |

Een niet-herkende regel blijft als los blok staan als je niets doet — en **dan
telt hij niet mee voor wat je spelers geleerd hebben**, want die telling wordt
opgebouwd uit gekoppelde oefeningen. Koppelen, of toevoegen aan de bibliotheek,
is wat de minuten op het spelersdossier laat landen.

Je kunt elke naam en duur aanpassen voordat je het concept aanmaakt, en een
regel weghalen die er eigenlijk niet stond.

### Waar de foto naartoe gaat

Dat staat op het scherm, naast de sluiterknop, vóórdat je hem maakt. De beheerder
van jouw academie bepaalt waar foto's naartoe gaan om gelezen te worden, en die
keuze wordt vastgelegd in de installatie in plaats van aangenomen — zolang die
keuze niet gemaakt is, gaat dit scherm niet open en zegt het dat ook. Namen van
spelers worden niet overgenomen in notities.

Heb je geen bereik, dan wordt er niets verstuurd en zegt het scherm dat.

### Buiten bereik

De foto blijft op je telefoon staan en wordt gelezen zodra je weer bereik hebt —
je hoeft hem niet opnieuw te maken. Hij blijft staan als je de pagina herlaadt
en als je je browser afsluit, en het scherm laat zien hoeveel er wachten. De
pagina met trainingsplannen doet dat ook, voor als je intussen bij de camera bent
weggelopen.

Zodra je weer verbinding hebt wordt de foto gelezen en kom je op hetzelfde
controlescherm als altijd. **Er wordt nooit een plan zonder jou aangemaakt** —
dat er niets wordt opgeslagen tot jij op **Concept aanmaken** drukt, geldt voor
een foto die heeft gewacht net zo goed als voor een foto met vol bereik.

Een wachtende foto staat op de telefoon en nergens anders. Hij wordt verwijderd
zodra hij gelezen en gecontroleerd is, en **na zeven dagen wordt hij verwijderd
of hij nu gelezen is of niet** — het scherm zegt het je als dat gebeurd is, zodat
je het blad opnieuw fotografeert in plaats van weken later te ontdekken dat de
training ontbreekt.

De pagina openen vraagt nog wel bereik. Het is de foto die kan wachten, niet de
app.

## Wat er nog niet is

Wat nog volgt:

- een whiteboard fotograferen in plaats van een blad papier, wat een ander
 soort uitlezen vraagt
