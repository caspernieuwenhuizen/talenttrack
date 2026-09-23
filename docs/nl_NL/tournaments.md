---
title: Toernooien
group: planning
summary: 'Dagen met meerdere wedstrijden: één selectie, één speeltijddoel, één planner.'
audience: [user]
views: [tournaments, tournament-match]
module: TT\Modules\Tournaments\TournamentsModule
order: 30
---

# Toernooien

Een **toernooi** in TalentTrack is een container voor een set wedstrijden die je op één dag of weekend speelt, met een gedeelde selectie en gedeelde speeltijd-doelen. De toernooiplanner is gebouwd om één vraag te beantwoorden die de coach elk weekend stelt:

> *Over deze wedstrijden heen: wie speelt welke positie wanneer, hoe staat dat tegenover de rest, en wie heb ik nog niet in de basis gehad?*

## Wie dit ziet

Hoofdtrainers, assistent-trainers en teammanagers zien en draaien de toernooien van de teams waaraan ze zijn toegewezen — de hele planner, inclusief er een aanmaken. Het Hoofd opleiding en de academiebeheerders zien elk toernooi in de academie.

De selectie van een toernooi kan uit meer dan één team komen, en de toegang volgt de hele verzameling, niet alleen het ankerteam. Dus:

- Je opent en plant een toernooi zodra **één** van de teams van jou is.
- Je verwijdert het alleen als **alle** teams van jou zijn. Verwijderen haalt de wedstrijddag weg bij elke selectie erin, dus als er een team bij zit dat jij niet begeleidt, weigert TalentTrack het verwijderen en zegt waarom. Het Hoofd opleiding of een academiebeheerder kan het wel verwijderen.

Spelers en ouders zien de toernooiplanner niet.

## Een toernooi aanmaken

1. Open de tegel **Toernooien** en tik op **+ Nieuw toernooi**.
2. De wizard begeleidt je door vijf stappen:
 - **Basis** — naam, anker-team, startdatum, optionele einddatum. Het formaat van het toernooi (7v7 / 9v9 / 11v11) wordt automatisch afgeleid uit de leeftijdsgroep van het anker-team; geen handmatige formaat-keuze.
 - **Formatie** — kies een standaardformatie (bijv. `1-3-4-3`) uit een raster van radiokaarten. Elke kaart toont een klein dotglyph van de formatievorm. Per wedstrijd later overschrijven kan.
 - **Selectie** — vink de spelers uit het anker-team aan, en tik per speler chips voor de **specifieke posities** die hij/zij kan spelen: KP · CV · LV · RV · DM · CM · AM · LB · RB · SP. Voorkeuren van de speler vormen het startpunt. Stagespelers verschijnen in de lijst met een `Trial`-label maar zijn standaard niet aangevinkt — tik op hun regel om ze toe te voegen. Een live teller boven de lijst leest "X in selectie · Y niet gekozen".
 - **Wedstrijden** — elke wedstrijd is een eigen kaart met een volgnummercirkel en een live kopregel ("vs Den Helder JO13", "Finale", "Nieuwe wedstrijd — vul de tegenstander hieronder in"). Velden: label, tegenstander, niveau, formatie-override, duur, wisselmomenten. Wissels gebruiken een chip-editor: typ een minuut en druk op Enter of komma om een chip toe te voegen; Backspace vanuit een leeg veld verwijdert de laatste chip; tik × om er één te verwijderen. De hint "Waarden moeten 1–N zijn" werkt live mee terwijl je de duur verandert. Tik **+ Voeg nog een wedstrijd toe** om een lege kaart toe te voegen; **Verwijderen** laat de kaart vallen en hernummert de rest.
 - **Bevestigen** — één kaart per voorgaande stap met een **Bewerk**-link rechtsboven die je terugbrengt naar die stap (alles wat je hebt ingevuld blijft bewaard). Tik daarna **Toernooi aanmaken**.
3. Je komt op de detailpagina van het toernooi terecht.

### Een wedstrijd toevoegen na aanmaken

De detailpagina toont een **+ Wedstrijd toevoegen**-knop naast **Bewerk**. Die opent een los formulier (hetzelfde raster en dezelfde chip-editor als de Wedstrijden-stap van de wizard) met één extra keuzemenu: **Positie in volgorde**. Kies "Invoegen aan het einde" of een "Invoegen voor wedstrijd N"-optie om de nieuwe wedstrijd op de juiste plek in te schuiven; bestaande wedstrijden schuiven hun `sequence` op.

## Wisselmomenten — wat ze betekenen

Het aantal minuten na de aftrap waarop een wissel plaatsvindt. Ze bepalen hoeveel **periodes** de wedstrijd heeft: `N wisselmomenten → N+1 periodes`.

- Een wedstrijd van 20 min met `[10]` → twee periodes van elk 10 minuten.
- Een wedstrijd van 60 min met `[20, 40]` → drie periodes van 20 minuten.
- Een wedstrijd van 30 min met `[]` (leeg) → één periode; geen wissels tijdens de wedstrijd.

## De planner-detailweergave

De detailweergave van een toernooi toont:

- **Feiten-strip** — team, data, standaardformatie, selectiegrootte, aantal wedstrijden.
- **Wedstrijden** — één kaart per wedstrijd. Tik op **Open planner grid** om de opstellingsgrid te openen.
- **Speeltijd-ticker** — vastgepinde strook onderaan op mobiel, rechter-zijbalk op desktop. Altijd zichtbaar. Eén kaartje per speler met:
 - De minuten, gespeeld en gepland uit elkaar gehouden: *0 gespeeld + 40 gepland / 35 min*. Zodra een speler het veld op is geweest loopt het eerste getal op; een speler zonder openstaande planning leest gewoon *40 gespeeld / 35 min*. Vóór de eerste aftrap staat de hele selectie op **0 gespeeld**, en dat is precies de bedoeling — het geplande deel is het plan, niet de registratie.
 - Een groen/oranje/rode balk voor die twee samen: gespeeld als het volle deel, gepland er vervaagd achteraan. De kleur volgt gespeeld **plus** gepland t.o.v. het gelijke-verdeling-doel, zodat de ticker vóór de aftrap als planner bruikbaar blijft in plaats van de hele selectie rood te kleuren.
 - ⚡ aantal basisplaatsen.
 - 🏆 aantal volledige wedstrijden.
 - Sorteer: **Standaard / Minste minuten / Minste basisplaatsen / Geen volledige wedstrijd** zodat onderbedeelde spelers bovenaan komen.

## Per-wedstrijd planner

De grid laat één rij zien per formatie-slot (`GK`, `RB`, `CB`, …), één kolom per periode.

- Tik op een spelerchip — hij krijgt een gele omranding.
- Tik op een andere chip of leeg vakje — de twee plekken wisselen.
- Tik nogmaals op dezelfde chip om de selectie ongedaan te maken.

De bank-rij onderaan verzamelt spelers die niet op het veld staan in een periode. Verplaats een speler van bank naar veld op dezelfde manier.

**Eligibility-waarschuwingen**: een speler in een slot waarvoor hij niet eligible is krijgt een oranje stip. Het is een waarschuwing, geen blokkade — coach beslist.

## Auto-balanceren

De knop **Auto-balanceren** op elke wedstrijdkaart voert een greedy-toewijzing uit op basis van:

- Eligibility (alleen spelers met passende positietypen).
- Eerlijke verdeling (speler met het grootste tekort aan minuten krijgt voorrang).
- Verdeling basisplaatsen (in periode 0 krijgen spelers met de minste basisplaatsen voorrang).
- Geen-bank-twee-keer-op-rij (een speler die in de vorige periode op de bank zat, zakt in de ranking).

Auto-balanceren is een **startpunt**, geen optimizer. Verslepen en tweaken kan altijd.

### Auto-balanceren uitzetten

Auto-balanceren is een per-academie-schakelaar (**Toernooi-auto-balanceren**)
op de Modules-beheerpagina, standaard aan. Zet je hem uit, dan verdwijnt de
knop Auto-balanceren van elke wedstrijdkaart; de per-wedstrijd planner en het
handmatig wisselen blijven precies werken zoals voorheen. Sommige Hoofden
Opleiding plannen speelminuten liever volledig met de hand — zo halen ze de
snelkoppeling weg zonder de planner te verliezen.

## Niveau tegenstander

Elke wedstrijd heeft een niveau — standaard **zwakker / gelijkwaardig / sterker / veel sterker**. De pill op de wedstrijdkaart is kleur-gecodeerd groen → grijs → oranje → rood, zodat je in één oogopslag ziet waar je sterkste opstelling nodig is. De pill neemt de kleur van het niveau zelf over: geef je een niveau bij Configuratie een andere kleur, dan verandert de pill mee. De tekst wisselt tussen donker en licht, zodat hij leesbaar blijft op elke kleur die je kiest.

Een wedstrijd kan alleen een niveau dragen dat in de lijst staat. Stuurt een import of een koppeling iets anders, dan wordt dat geweigerd met een melding die de toegestane niveaus noemt, in plaats van een woord op te slaan dat de planner daarna letterlijk toont. Het niveau leeg laten mag nog steeds — dat leest als "niet vastgelegd".

Het niveau verschijnt overal met zijn vertaalde label — de pill op de wedstrijdkaart, de keuzelijst op het formulier "Wedstrijd toevoegen", de wedstrijdstap van de wizard en de samenvatting in de wizard. Hernoem je een niveau bij Configuratie → Niveaus tegenstander, dan volgt het nieuwe label op alle vier; wat er bij de wedstrijd is opgeslagen verandert niet, dus bestaande wedstrijden houden hun niveau.

De auto-balancer weegt **niet** automatisch op niveau tegenstander. Dat is de beoordeling van de coach; de tool laat de data zien, jij beslist via handmatige wissels.

## Waarden die de planner weigert

Twee dingen die een toernooi kan meekrijgen worden bij binnenkomst
gecontroleerd in plaats van stilletjes weggelaten, omdat de planner er niet mee
kan werken en een coach het alleen zou merken aan een grid dat er verkeerd
uitzag.

**Posities.** Een selectieregel mag `GK · CB · LB · RB · DM · CM · AM · LW · RW
· ST` bevatten. Al het andere — `DF`, `MF`, `FW`, een typefout — wordt geweigerd
met een melding die de code noemt én de codes die wél mogen; er wordt niets
opgeslagen. Voorheen verdween zo'n code zonder bericht: een selectie die als
`GK / DF / MF` werd verstuurd, werd opgeslagen als alleen `GK`, en
auto-balanceren vulde daarna alleen de keeperplek en zette de rest zonder
minuten op de bank. De oudere `DEF` / `MID` / `FWD` werken nog en lezen als
`CB` / `CM` / `ST`.

**Formaties.** De standaardformatie van een toernooi en de eigen formatie van
een wedstrijd moeten er een zijn die de academie daadwerkelijk heeft bij
Configuratie → Toernooiformaties. Een onbekende formatie wordt geweigerd, met de
lijst van formaties die er wél zijn. Bij een wedstrijd leeg laten mag nog
steeds — dat betekent "gebruik die van het toernooi".

Speelt jouw leeftijdsgroep een vorm die niet in de standaardlijst staat, voeg
die dan bij Configuratie → Toernooiformaties toe met zijn slotlabels; daarna
wordt hij overal geaccepteerd, planner inbegrepen.

## Aftrap en afsluiten van een wedstrijd

- **Aftrap** — promoveert de geplande wedstrijd tot een echte activiteit. De wedstrijd verschijnt op de player journey en op de wedstrijdlijst van het team.
- **Wedstrijd afsluiten** — zet de afsluit-timestamp, synct de basisopstelling van periode 0 naar **aanwezigheid**: iedereen die startte wordt gemarkeerd als `start` met zijn positie uit periode 0; gewisselde spelers als `bench`. Gespeelde minuten gaan van "verwacht" naar "gespeeld" in de ticker.

Je kunt **Afsluiten** zonder eerst expliciet aftrap te geven — het systeem doet de aftrap automatisch.

## De uitslag van elke wedstrijd vastleggen

Elke wedstrijd in het programma heeft twee vakjes: **Wij** en **Zij**. Typ de
doelpunten in; ze worden opgeslagen zodra je het vakje verlaat.

**Een toernooi heeft geen enkele uitslag.** Een toernooidag zijn meerdere
wedstrijden, en één uitslag kan dat niet beschrijven — daarom krijgt een
toernooi niet de Uitslag-kaart die een competitiewedstrijd heeft, en geen
uitslagvakjes in het minutenraster. De uitslag hoort bij de wedstrijd, dus daar
leg je hem vast.

**Een vakje leeg laten legt geen uitslag vast**, geen 0–0. Een wedstrijd die je
nog niet gespeeld hebt, of waar niemand een uitslag van invulde, leest als
gespeeld-zonder-uitslag en niet als doelpuntloos gelijkspel.

**Een uitslag slaat de uitslag op en verder niets.** Typen in een van beide
vakjes laat de tegenstander, het niveau, de aftraptijd, de speelduur en de
wisselmomenten van de wedstrijd precies zoals ze waren. Andersom geldt
hetzelfde: een wedstrijd korter maken behoudt de wisselmomenten die binnen de
nieuwe speelduur passen in plaats van ze te wissen.

> **Wedstrijden waarvan je eerder een uitslag vastlegde, moet je
> controleren.** Het opslaan van een uitslag wiste voorheen de tegenstander,
> het niveau, de aftraptijd en de notities, maakte de wisselmomenten leeg en
> zette de speelduur terug op 20 minuten. Die wedstrijden moet je met de hand
> opnieuw invullen — de waarden zijn niet uit de wedstrijd zelf terug te
> halen. Bij een wedstrijd waarvoor je aftrap hebt gegeven staan de
> tegenstander, de formatie en de aftraptijd nog op de wedstrijdactiviteit die
> daarbij is aangemaakt; dat is de snelste plek om ze terug te lezen. In de
> release-notitie in `CHANGES.md` staat om welke releases het gaat.

Er is bewust **geen dagtotaal**. De balans van het team laat toernooien
helemaal buiten beschouwing, dus een doelpunten voor/tegen over de dag zou
nergens gelezen worden. Verandert dat, dan is het zo toegevoegd.

**Doelpunten tellen nog steeds voor de speler.** Een doelpunt op een toernooi
kwam altijd al via de kolom `G` van het minutenraster in het dossier van de
maker terecht, en dat blijft zo — die helft hing nooit van deze vakjes af.

## Het toernooidossier van één speler

De planner beantwoordt de vraag "hoe heb ik deze zaterdag verdeeld over
zestien kinderen". Het tabblad **Toernooien** op het spelersdossier
beantwoordt de andere helft: hoe een seizoen aan zaterdagen is verlopen voor
één van hen.

Open een speler en kies **Toernooien**. Je ziet wat eraan komt, de
kerncijfers — minuten, basisplaatsen op het aantal wedstrijden, hele
wedstrijden, minuten tegen sterkere tegenstanders — en daaronder elk
toernooi waarvoor de speler in de selectie zat, elk uitklapbaar naar de
wedstrijden: de tegenstander en zijn niveau, de uitslag, of de speler
begon, inviel of erbuiten bleef, hoeveel van de minuten hij kreeg en waar
hij speelde.

**Elk toernooi wordt afgezet tegen het eigen minutendoel van die speler**,
het doel dat op de toernooiselectie is ingesteld — nooit tegen een
selectiegemiddelde. De minuten van een ploeggenoot gaan deze speler niet
aan, en het dossier van een kind is de verkeerde plek om te leren hoeveel
meer een ander speelde. Alleen een tekort krijgt kleur, en de getallen staan
naast de balk, zodat niets op de pagina van kleur afhangt.

**Waar de minuten vandaan komen staat op de pagina.** Ze volgen het
rotatieplan van de afgeronde wedstrijden. Er is geen registratie per
wedstrijd van wat er werkelijk gespeeld is — de aanwezigheid van een
toernooidag is één totaal voor die dag — en zodra een wedstrijd is afgerond
zet de planner de opstelling vast, dus het plan van een afgeronde wedstrijd
*is* de gebruikte rotatie. Minuten die achteraf in het minutenoverzicht zijn
ingevoerd staan op het tabblad **Activiteiten**, en de twee worden nooit bij
elkaar opgeteld.

Een wedstrijd zonder vastgelegde uitslag zegt **geen uitslag**, niet 0-0.
Een doelpuntloos gelijkspel en een wedstrijd die niemand heeft ingevoerd
zijn verschillende feiten over het seizoen van een kind.

Een speler die nooit in een toernooiselectie heeft gezeten ziet een regel
die dat zegt, en zijn tabblad draagt geen teller. Het tabblad verschijnt
wel: een trainer die kijkt of iedereen aan spelen toekomt, moet "nooit
geselecteerd" kunnen zien in plaats van het stilletjes te missen.

**Wie het ziet.** Een trainer voor de spelers van zijn eigen teams, een Head
of Development of beheerder voor iedereen, de speler voor zijn eigen
dossier, en een ouder voor zijn kind — tenzij de speler het onderdeel
**toernooien** in zijn deelinstellingen heeft uitgezet, dan krijgt de ouder
een melding die dat zegt. Hetzelfde antwoord staat op de API, op
`GET /players/{id}/tournaments`.

## Wie kan dit zien

In v1 zijn de Toernooien-tegel, de planner en elk REST-endpoint alleen toegankelijk voor de **Academy Admin**. Coach, Head of Development, Scout, Speler en Ouder zien de functie niet.

## Op je abonnement

Toernooien zijn een **Pro**-functie. Op Standard blijft elk toernooi dat de club draaide te bekijken — wedstrijden, selecties, totalen — en is aanmaken of wijzigen vergrendeld. Automatisch balanceren staat daar los van: een Standard-club plant het schema met de hand. Zie [Licentie en account](license-and-account.md).
