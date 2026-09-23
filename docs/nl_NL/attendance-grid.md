---
title: Aanwezigheidsraster
group: match-day
summary: Leg de aanwezigheid van een hele periode vast in één overzichtelijk raster.
audience: [user]
views: [attendance-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: attendance_grid
order: 40
---

# Aanwezigheidsraster

Het **aanwezigheidsraster** is een snelle manier om de aanwezigheid van een
hele periode in één scherm vast te leggen — het alternatief voor de
stap-voor-stap aanwezigheidswizard op de desktop. Het werkt zoals het
Excel-overzicht van een trainer: één rij per speler, één kolom per activiteit,
in elke cel een status.

Open het via **Activiteiten → Aanwezigheidsraster**, of met de knop
**Aanwezigheidsraster** op de pagina van een activiteit (die het raster op die
activiteit opent). Die knop verschijnt bij een activiteit waarvoor er iets
vast te leggen valt — zie *Activiteiten die nog niet geweest zijn* hieronder.
Je hebt rechten nodig om activiteiten te bewerken. Het is gemaakt voor een
desktop of laptop; op een telefoon is de begeleide wizard handiger.

## Wat je ziet

- **Rijen zijn je spelers** — de actieve selectie van het gekozen team. Elke
 speler is altijd een rij, ook voor een activiteit waarvoor nog niemand is
 vastgelegd.
- **Kolommen zijn activiteiten** — trainingen en wedstrijden in de gekozen
 periode, oudste links. De kolommen groeien met het seizoen; de
 periodefilter bepaalt hoeveel je er ziet.
- **Elke cel is een status.** Kies er één uit de keuzelijst:
 - **Aanwezig**, **Laat**, **Afwezig**, **Geoorloofd**, **Blessure**.
 - De cel toont een korte letter; de keuzelijst toont het hele woord.
- De kolom **Aanwezig %** rechts laat snel zien hoe vaak een speler in de
 getoonde periode aanwezig was.

## Terugkijken naar een vorig seizoen

Elke zomer schuiven selecties door, en een presentielijst schuift niet mee.
Kies je dus een periode van vóór de laatste teamwissel, dan toont het raster
ook de spelers die toen in de selectie zaten en er nu niet meer bij horen. Ze
staan **onder** de huidige selectie, gedempt, met het team waarin ze nu zitten
naast hun naam — *"Uit de selectie — nu JO12-1"*.

Die rijen zijn **alleen-lezen**: je ziet elke vastgelegde status, maar er is
geen keuzelijst om er één te wijzigen. Aanwezigheid kun je alleen vastleggen
voor een speler die in de selectie zit, en een bewerking aanbieden die toch
geweigerd wordt is slechter dan hem niet aanbieden. Een notitie onder het
raster zegt dat ook. Wil je een vastlegging van een vertrokken speler
corrigeren, doe dat dan op het aanwezigheidsformulier van de activiteit zelf.

Daarom klopt de activiteitenlijst van het team nu ook met zichzelf bij zo'n
activiteit: de aantallen op de kaart ("20 / 20 vastgelegd", het
aanwezigheidspercentage) tellen de presentielijst zoals die is vastgelegd, en
niet de spelers die vandaag in het team zitten.

## Aanwezigheid vastleggen

1. Kies het **team**, de **periode** (een snelknop of een eigen datumbereik)
 en beperk eventueel tot **alleen training** of **alleen wedstrijden**.
2. Zet in elke cel een status. Gebruik **"allen aanwezig"** boven een kolom om
 een hele sessie in één klik op aanwezig te zetten en corrigeer daarna de
 uitzonderingen.
3. Klik op **Opslaan**. De teller laat zien hoeveel wijzigingen klaarstaan;
 bewerkte cellen krijgen een rand tot je opslaat. Met **Annuleren** verlaat
 je het scherm zonder op te slaan.

Het raster legt dezelfde aanwezigheid vast als de rapporten en de wizard,
zodat de rapporten Aanwezigheid en Minuten blijven kloppen met wat je hier
invoert.

## Activiteiten die nog niet geweest zijn

**Aanwezig** en **Laat** kun je pas vastleggen op of na de dag van de
activiteit. Een speler kan niet bij een training zijn geweest die nog niet
heeft plaatsgevonden, dus het raster weigert die cellen op een activiteit in
de toekomst. Al het andere wordt opgeslagen, de geweigerde cellen krijgen een
rode rand en de opslagbalk zegt hoeveel er niet zijn opgeslagen en waarom.

Een **afwezigheid** kun je wél vooraf vastleggen: **Afwezig**, **Geoorloofd**
of **Blessure** voor een speler van wie je al weet dat die volgende week niet
komt. Het raster toont komende activiteiten alleen als er al zo'n markering
op staat, zodat je die kunt zien en wissen. Een komende training waarop nog
niets is vastgelegd verschijnt nog niet. Wie er komt plan je in de geplande
selectie op de activiteit zelf.

Dezelfde regel geldt als je aanwezigheid vastlegt op het formulier van de
activiteit zelf. "Vandaag" is de datum van de academie, niet die van de
server.

Omdat er dan nog niets vast te leggen valt, volgen de knoppen die het raster
op één activiteit openen dezelfde regel. Zolang er niets op de training van
volgende week staat, zie je **Aanwezigheidsraster** niet op de pagina van de
activiteit of op de kaart in de lijst, en verschijnt **Aanwezigheid
registreren** niet als je die activiteit afrondt. Ze komen terug zodra er een
afwezigheid vooraf op staat, en op de dag zelf. Wie er komt plan je tot die
tijd in de geplande selectie op de activiteit.

## Als de begeleide wizard uit staat

Een academie die liever met overzichten werkt, kan de begeleide aanwezigheids-
en beoordelingswizard uitzetten via **Instellingen → Wizards**. Het raster is
dan de belangrijkste manier om aanwezigheid in te voeren, en de knoppen bij een
activiteit volgen mee:

- **Aanwezigheid registreren** bij een activiteit (en op de kaart in de
 activiteitenlijst) opent dit raster op de kolom van die activiteit in plaats
 van de wizard. Dat is dezelfde knop die **Activiteit voltooien** heet als de
 wizard aan staat — hernoemd, zodat hij niet meer belooft dan hij doet.
- **Aanwezigheid registreren** op je dashboard opent het raster voor de activiteit
 die erbij staat.
- **Markeren als afgerond** verschijnt bij een geplande activiteit, maar voor
 sessies die al geweest zijn heb je die knop zelden nodig. Opslaan in het
 raster **markeert elke geplande activiteit uit het verleden waarin je iets
 invulde als voltooid**. Vastleggen wie er was, is de uitspraak dat de sessie
 heeft plaatsgevonden, en de aanwezigheidsrapporten tellen alleen voltooide
 activiteiten — een invoer die de activiteit op *Gepland* laat staan, zou de
 cijfers dus nooit bereiken.

 **Je krijgt het altijd eerst te zien.** Een kolom van een sessie uit het
 verleden die nog gepland staat, heeft een oranje streep in de kop, en bij
 **Opslaan** opent een venster dat elke activiteit noemt waarvan de status
 verandert, uitlegt waarom en wacht op je keuze: **Opslaan en op voltooid
 zetten** gaat door, **Terug naar het raster** schrijft helemaal niets weg.
 Verandert een opslag geen enkele status, dan verschijnt er geen venster.
 Daarna meldt de opslagbalk hoeveel activiteiten zijn omgezet.

 Twee gevallen worden zo nooit voltooid: een activiteit met een datum in de
 **toekomst** (een bekende afmelding voor volgende week vooraf vastleggen is
 geen uitspraak dat volgende week geweest is) en een activiteit die je op
 **Geannuleerd** hebt gezet. Een voltooide activiteit kun je later altijd
 heropenen.

Staat de wizard aan, dan verandert er niets: afronden loopt via de begeleide
flow, die de activiteit in de laatste stap op afgerond zet.

## Uitschakelen

Een beheerder kan het raster verbergen via **Instellingen → Functies →
Aanwezigheidsraster**. Als het uit staat, verdwijnt de rasterknop en kan de
pagina niet worden geopend. De aanwezigheidswizard blijft gewoon werken.

Staan het raster én de wizard uit, dan pas je de aanwezigheid van een activiteit
aan op het bewerkformulier van die activiteit, zodra hij is afgerond.
