---
title: Persoonlijk Ontwikkelingsplan (POP)
group: performance
summary: Seizoens­gebonden ontwikkeldossiers, gespreks­cadans, eindeseizoens­oordeel.
audience: [user]
views: [pdp, my-pdp]
module: TT\Modules\Pdp\PdpModule
capability: tt_view_pdp
order: 60
---

# Persoonlijk Ontwikkelingsplan (POP)

Een **POP-dossier** is een seizoens­gebonden ontwikkelplan voor één speler. Het brengt samen wat anders verspreid raakt over evaluaties, doelen en losse aantekeningen — en geeft de academie een herhaalbare cadans: instelbare gespreks­momenten over het seizoen, polymorfe koppelingen tussen doelen en de methodische woordenlijst, en een doelbewust eindeseizoens­oordeel ondertekend door het hoofd academie.

## Wie ziet wat

- **Coaches** — volledige bewerking van POP-dossiers voor spelers in hun eigen teams. Tegel: **Performance → POP**.
- **Hoofd academie** — globale bewerking van alle dossiers plus exclusieve schrijftoegang tot het eindeseizoens­oordeel. Dat geldt ook voor de gesprekken in het dossier, voor spelers van elk team: een geplande datum verzetten, het verslag schrijven, aftekenen en het dossier printen.
- **Spelers** — alleen-lezen op het eigen dossier, gepresenteerd als een seizoenstijdlijn, plus een bewerkbare zelfreflectie voor het ene eerstvolgende geplande gesprek. Tegel: **Mijn → Mijn POP**.
- **Ouders / verzorgers** — alleen-lezen op het dossier van hun kind (na ondertekening) plus een per-gesprek bevestigings­knop.
- **Read-only observer** — alleen-lezen op alle dossiers; geen bewerking, geen bevestiging.

**De grens die je uit je hoofd moet kennen:** de **voorbereiding** van een trainer wordt gelezen door die trainer en het hoofd opleiding, en door niemand anders. Niet door de speler, niet door de ouders, niet door een read-only observer — op geen enkel scherm, niet in de print en in geen enkele export. Al het andere in een POP-dossier volgt de rij hierboven.

Dat is geen weergaveregel die een toekomstig scherm kan vergeten. De controle zit in de repository waar elke uitlezing doorheen gaat, dus een scherm dat er volgend jaar bij komt kan er niet omheen — en er is per scherm een test dat het dat ook niet doet.

## POP-overzicht: wie heeft dit seizoen een POP

De **POP**-tegel opent op één **spelergerichte lijst** voor het huidige seizoen in plaats van een kale lijst met dossiers. Het vertrekpunt is de speler (CLAUDE.md §1): elke speler die je traint wordt één keer getoond, met een duidelijke indicator of het POP **voor dit seizoen** al bestaat.

- Bestrijk je **meer dan één team** (of heb je globale scope), dan kies je eerst een team — *"Selecteer een team om de spelers te zien."* — zodat je afgebakend begint in plaats van alle spelers tegelijk te zien. Een coach met één team gaat direct naar de eigen selectie.
- Boven die keuze staat **POP-dekking per team**: één regel per team met het aantal spelers, hoeveel er een plan hebben, met hoeveel er daadwerkelijk een gesprek is gevoerd, hoeveel gesprekken er de komende vier weken staan, en hoeveel gesprekken een ouder heeft ondertekend. **Teams met de minste gevoerde gesprekken staan bovenaan**, want dat is het team dat je zoekt. Elke teamnaam is een link naar de selectie van dat team.
 - "Met een plan" en "Gesproken" staan als deel van het team, bijvoorbeeld *5 / 15*, met een dunne balk onder het getal. Een nul staat in grijs, zodat de getallen die er wel zijn opvallen; niets kleurt rood of oranje, want dit toont dekking en geen oordeel.
 - Spelers die in geen enkel team zitten, tellen mee op een regel **Geen team**. Die staat altijd onderaan en heeft geen link: daar zoek je spelers die nog niet ingedeeld zijn, het is geen team om achteraan te gaan.
 - "Met een plan" en "Gesproken" zijn verschillende vragen, en het gat ertussen is waar het om gaat: een team waar iedereen een dossier heeft en nog niemand aan tafel heeft gezeten leest als volledig gedekt op de samenvattingsregel, en is precies het team dat achterloopt.
 - De ouderkolom telt gesprekken die een **ouder heeft ondertekend**. Nergens wordt vastgelegd wie er in de kamer zat, dus dit is het dichtstbijzijnde feit dat het product heeft; het is geen presentielijst.
- Bovenaan staat een samenvattingsregel, bijvoorbeeld: *"14 van de 18 spelers hebben een POP voor het huidige seizoen (2025/26)."* De regel telt dezelfde spelers als de lijst eronder: een hoofd opleiding, of iemand anders die het POP van elke speler mag lezen, telt het hele team; een coach telt alleen de eigen spelers.
- Elke rij toont de **speler** (gekoppeld aan het spelerrecord), het **team** en een **POP dit seizoen**-status:
 - **Aangemaakt** — een groene *POP ✓*-pil, waar mogelijk met gespreksvoortgang (bijv. *POP ✓ 1/3*), die direct naar het dossier linkt.
 - **Niet gestart** — een grijze *Niet gestart*-pil plus een knop **POP aanmaken** die de aanmaakflow opent, voor­ingevuld voor die speler en dat team.
- **Seizoen** — heeft de academie meer dan één seizoen, dan staat boven de andere filters een rij seizoenspillen, standaard op het huidige seizoen. Kies een eerder seizoen om te zien wie toen een plan had: de samenvattingsregel, de dekking per team en de rijen volgen allemaal het gekozen seizoen, en de samenvatting luidt bijvoorbeeld *"12 van de 18 spelers hebben een POP voor seizoen 2024/25."* Een melding zegt naar welk seizoen je kijkt, en daar staat geen knop **POP aanmaken**, want een nieuw dossier opent altijd in het huidige seizoen. Bij de teamkeuze staat het seizoen als keuzelijst naast het team.
- **Filters** — teamkeuze + zoeken op speler, op dezelfde manier afgebakend als de rest van de app: coaches zien alleen spelers van hun eigen teams; beheerders en het hoofd opleiding zien iedereen. Dat geldt voor elk seizoen.
- **Alleen spelers zonder POP** — een schakelaar met één klik om iedereen te verbergen die al een dossier heeft, zodat je de gaten kunt wegwerken.
- Met de **⋯**-knop aan het eind van de filterrij (voor wie mag herstellen of verwijderen) schakel je de lijst naar de spelers van wie het POP voor het seizoen **gearchiveerd** is, met per rij **Herstellen** / permanent verwijderen. Dit vervangt het oude aparte tabblad Dossiers — gearchiveerde bestanden staan nu in dezelfde lijst.
- Klik op een gedekte rij om het POP-dossier van de speler te openen; klik op een ontbrekende rij om naar de aanmaakflow te springen.
- Het dossier zelf toont onder de status het **Seizoen**, zodat het plan van vorig seizoen en dat van dit seizoen niet door elkaar kunnen raken.

- **Wie nog geen gesprek heeft gehad** — `conducted=0` beperkt de lijst tot spelers zonder gevoerd gesprek, inclusief spelers die helemaal geen dossier hebben. Dat laatste is met opzet: een speler zonder iets is het ergste geval, en een filter dat bedoeld is om te vinden wie nog niet gesproken is mag ze niet verbergen.

De dekkingsdata is ook beschikbaar via REST op `GET /wp-json/talenttrack/v1/pdp-files/coverage` (`season_id` of `filter[season_id]`, `team_id` of `filter[team_id]`, `search`, `only_missing`, `conducted`, `archived`), zodat een toekomstige front-end hetzelfde antwoord krijgt. Het antwoord noemt het seizoen dat geteld is (`season_id`, `season_name`, `is_current_season`), en `GET pdp-files` en `GET pdp-files/{id}` doen dat ook. Het `summary`-blok bevat de kopregel `total` / `covered` plus een `by_team`-uitsplitsing met dezelfde vijf getallen als op het scherm. Beide worden berekend over de hele gefilterde scope en niet over de getoonde pagina — een totaal dat verandert als je doorbladert is geen totaal — en beide negeren `only_missing` en `conducted`, die een lijst afbakenen om naar te kijken in plaats van te veranderen wat de dekking van de academie is.

## De flow

### 1. Open het dossier

Klik op de **POP**-tegel op *POP aanmaken* in de rij van een speler (of op *Nieuw POP-dossier openen*), kies een speler en klik op *Nieuw POP-dossier openen*. Het dossier wordt aangemaakt met één gesprek per cyclus (2, 3 of 4 — instelbaar per club, te overschrijven per team). Elk `scheduled_at` wordt evenredig over de start- en einddatum van het seizoen verdeeld.

Die eerste data zijn een startpunt, geen afspraak: elk gesprek komt op een hele dag om **18:00** te staan, en de trainer verzet het naar de dag en tijd waarop het gesprek echt plaatsvindt. Heeft de academie cyclusblokken voor het seizoen ingesteld, dan komt het gesprek op de middelste dag van zijn blok te staan, op dezelfde tijd, met het blok als planningsvenster.

Voor elk gesprek wordt automatisch een native agenda-item bijgehouden.

### 2. Voer de gesprekken

Een gesprek opent op drie tabbladen — **Voorbereiding**, **Gesprek**, **Bewijs** — en start op het tabblad dat je nodig hebt: Voorbereiding zolang het gesprek nog voor je ligt, Gesprek zodra het gevoerd is.

- **Voorbereiding** — de eigen antwoorden van de trainer op de vragenset van dit gesprek. Alleen voor de trainer en het hoofd opleiding.
- **Gesprek** — de zelfreflectie van de speler, en daarna de notities, afgesproken acties en ondertekening uit het gesprek zelf.
- **Bewijs** — wat de academie al weet, alleen-lezen.

#### Voorbereiding

Het tabblad **Voorbereiding** vraagt waar je vóór het gesprek over nagedacht moet hebben. De vragen verschillen per gesprek in de cyclus — aan het begin van het seizoen vraag je wat we van deze speler vragen, aan het eind wat je het hoofd opleiding gaat adviseren — en een academie stelt ze zelf in onder *Configuratie → POP-voorbereidingsvragen*.

Het formulier slaat zichzelf op, net als het gespreksformulier ernaast. Ondertekenen verandert niet: dat blijft een eigen knop op het tabblad Gesprek.

**Niemand behalve jij en het hoofd opleiding leest dit.** Niet de speler, niet de ouders, op geen enkel scherm, niet in de print en niet in een export. Hier kun je opschrijven dat een thuissituatie moeilijk is, of dat je nog twijfelt over een advies. Wat gedeeld wordt, is wat je in het gesprek afspreekt — de notities en de afgesproken acties.

> **Overstappen van het agendaveld.** Het losse vrije-tekstveld *Agenda (voor het gesprek)* vervalt. Wat erin stond is verplaatst naar de vraag *“Nog iets anders voor te bereiden?”* bij hetzelfde gesprek, dus er gaat niets verloren. Eén gevolg is het benoemen waard: de agenda was zichtbaar voor de speler op zijn eigen POP-scherm, en de voorbereiding is dat niet. Tekst die is verplaatst werd dus minder zichtbaar, nooit meer.

#### Bewijs

Het tabblad **Bewijs** verzamelt wat de academie al weet over de speler sinds het vorige gesprek — alleen-lezen, zodat de trainer het gesprek opent op basis van het dossier en niet op basis van zijn geheugen: evaluaties met beoordeling, beoordelaar en notities; aanwezigheid met de verdeling aanwezig / afwezig / afgemeld, gespeelde wedstrijden en speelminuten, met de opbouw per wedstrijd; doelen, en of ze bewogen zijn; de zelfreflectie van de speler; notities van de staf, blessures en gebeurtenissen op de tijdlijn; en de potentieel- en gedragsbeoordelingen uit die periode.

Een onderdeel zonder inhoud zegt dat ook, in plaats van te verdwijnen — “Geen evaluaties in deze periode” is zelf iets om te weten voor een gesprek.

Elk record linkt door naar zijn eigen pagina, dus een evaluatie die je helemaal wilt lezen is één tik weg en een terugkoppeling brengt je hier weer terug.

**Waar de cijfers vandaan komen.** Eén verzameling, gelezen door drie schermen: dit tabblad, de bewijspagina van de print, en het eindeseizoensoordeel. Ze zijn per club afgebakend en laten gearchiveerde en verwijderde records buiten beschouwing, dus dezelfde speler op dezelfde dag laat een trainer en een hoofd opleiding dezelfde getallen zien. Het tabblad beperkt de periode tot wat er sinds het vorige gesprek is gebeurd; de print en het eindoordeel beslaan het hele seizoen.

#### Volgorde

De gesprekken verlopen op volgorde: alleen het **actieve** gesprek — het eerste dat nog niet is afgetekend — is volledig bewerkbaar. Latere gesprekken in de cyclus zijn alleen-lezen, behalve hun **geplande datum**, zodat een coach het hele seizoen vooruit kan plannen zonder een gesprek buiten de beurt in te vullen. Een later gesprek komt vrij voor volledige bewerking zodra het voorgaande gesprek is afgetekend.

#### Een gesprek dat niet is doorgegaan

Soms gaat een gesprek niet door: de speler was ziek, een week viel uit, de coach wisselde. Open bij het actieve gesprek, onder **Ondertekenen**, **Dit gesprek is niet doorgegaan…**, vul in waarom en druk op **Markeren als niet doorgegaan**. Na een bevestiging staat het gesprek als **Niet doorgegaan** vastgelegd, met de datum en je reden, en wordt het volgende gesprek in de cyclus het actieve. De rest van de cyclus, het eindgesprek van het seizoen inbegrepen, verloopt daarna gewoon.

- De coach van het dossier en het hoofd opleiding kunnen dit doen: dezelfde mensen die een gesprek aftekenen.
- Alleen het actieve gesprek kan worden gemarkeerd, dus gesprekken worden nog steeds op volgorde afgehandeld. Een gesprek dat is gevoerd of afgetekend kan niet worden gemarkeerd.
- Een reden is verplicht.
- Het gesprek blijft in het dossier staan. De gesprekkenlijst toont **Niet doorgegaan** met de reden, en de speler en de ouders zien hetzelfde op *Mijn POP*. Het staat nooit op *te laat*, en de speler wordt er niet om een reflectie gevraagd.
- Per ongeluk gemarkeerd? **Niet doorgegaan ongedaan maken** bij dat gesprek haalt de markering weg, zolang er sindsdien geen later gesprek is gevoerd, afgetekend of gemarkeerd.
- Voor het eindoordeel telt een niet doorgegaan gesprek als afgerond, net als een afgetekend gesprek.

De speler kan op elk moment vóór ondertekening zijn zelfreflectie invullen. Zodra de coach ondertekent, wordt het veld vergrendeld.

### Het gespreksformulier slaat zichzelf op

Er is geen knop **Gesprek opslaan** meer. Het formulier slaat op terwijl je schrijft, en op de plek van de knop staat een statusregel die vertelt hoe ver dat is — *Niet-opgeslagen wijzigingen…*, *Opslaan…*, *Alle wijzigingen opgeslagen*. **Ongedaan maken** en **Wijzigingen terugdraaien** staan ernaast, net als op elk ander automatisch opslaand scherm; beide staan volledig beschreven in [hoe opslaan werkt](save-model.md).

De **zelfreflectie** van de speler werkt hetzelfde, zolang het venster open staat. Buiten het venster is het veld uitgeschakeld, zoals eerder, en wordt er niets opgeslagen.

**Ondertekenen is nu een eigen knop, geen vinkje op het formulier.** Het was een vinkje dat je samen met de rest opsloeg. Op een formulier dat zichzelf opslaat zou dat één misklik verwijderd zijn van het gesprek permanent op slot zetten voor iedereen — daarom staat er nu een aparte knop **Ondertekenen** onder het formulier, achter een bevestiging. Alles daarboven is op dat moment al opgeslagen; ondertekenen is wat het gesprek voor bewerken sluit.

Een **inhoudelijk vergrendeld** gesprek — een later gesprek in de cyclus — slaat nog wel zijn geplande datum automatisch op, het enige veld dat het je laat wijzigen. Een **ondertekend** gesprek slaat niets op, want er valt niets meer te schrijven.

### 3. Bevestiging

Na ondertekening verschijnt het gesprek op het *Mijn POP*-overzicht van de speler (en de ouder, indien gekoppeld). Beiden kunnen op *Bevestigen* klikken — een lichte "ik heb het gezien"-timestamp.

Als het gesprek persoonlijk plaatsvindt, kan de coach die bevestigingen ook op het gespreksformulier vastleggen — *Bevestiging speler vastleggen* / *Bevestiging ouder vastleggen*, elk achter een bevestigingsdialoog. Het legt dezelfde bevestiging vast alsof de speler of ouder er zelf op had geklikt. Bevestig alleen wanneer zij het gesprek daadwerkelijk met u hebben bevestigd.

### Wat de speler ziet: een tijdlijn-eerst Mijn POP

*Mijn POP* is de **voorbereidings- en zelfreflectieruimte** van de speler, opgebouwd rond het seizoen als een tijdlijn.

- **Seizoenstijdlijn bovenaan.** De ontwikkelgesprekken van het seizoen staan als markers op een horizontale rail, elk in een van vijf toestanden: **afgerond** (een groene ✓), **niet doorgegaan** (het gesprek heeft niet plaatsgevonden; openen toont de reden van de coach), **te laat** (rood - de geplande datum is verstreken en het gesprek is niet gevoerd), **gepland** (het gouden eerstvolgende gesprek) en **later** (gedempt). Een gesprek staat op *te laat* vanaf de dag ná de geplande datum, dus een gesprek dat later vandaag staat leest nog steeds *gepland*; het label verdwijnt zodra het gesprek is gevoerd of afgetekend. Het is de enige marker op de rail waar iemand iets mee moet - de coach verzet het of legt vast dat het is gevoerd. Een voortgangsvulling loopt langs de rail tot aan het laatst afgeronde gesprek. Op een marker tikken **vouwt het gespreksdetail ter plekke uit** - notities, afgesproken acties, de besproken doelen, een eventueel opgeslagen reflectie en de bevestigingsknop - zodat lang scrollen niet nodig is. De markers zijn bedienbaar met het toetsenbord (Tab om te focussen, Enter/Spatie om te openen, Escape om te sluiten).
- **Actieve doelen onder de tijdlijn.** De huidige focusdoelen van de speler (niet het volledige archief), elk met het doelspecifieke statuslabel (bijv. *In ontwikkeling*) en de streefdatum.
- **Eén zelfreflectie-invoer.** Alleen het **ene eerstvolgende geplande** gesprek kan een reflectie krijgen - eerdere en latere gesprekken tonen nooit een invoerveld. Het veld verschijnt zodra het reflectievenster van 2 weken vóór het gesprek opent; daarvóór legt een melding uit wanneer het verschijnt. Een eerder opgeslagen reflectie staat **rechts** van de invoer op bredere schermen en **eronder gestapeld** op mobiel. De zelfreflectie is bedoeld als hulp, nooit verplicht - er wordt niets geblokkeerd als de speler het overslaat. Bij een academie met gespreksformulieren neemt het formulier die plaats in; zie *De speler bereidt voor op het formulier*.
- **Eindeseizoensoordeel** sluit de pagina af zodra het is vastgelegd.

Ouders zien dezelfde tijdlijn voor hun kind, alleen-lezen en bezittelijk ("ontwikkelplan van &lt;kind&gt;"): de opgeslagen reflectie is zichtbaar maar er is geen bewerkbaar veld, en zij bevestigen via hun eigen knop. De tijdlijnstatus wordt afgeleid uit de ingeplande gesprekken en hun planvensters - er verandert niets aan de planning of de vensterdata.

### 4. Eindeseizoens­oordeel

Wanneer het laatste gesprek van de cyclus is ondertekend, legt het hoofd academie (of de hoofdcoach in sommige configuraties) een eindoordeel vast: **doorstromen**, **behouden**, **uitsluiten**, of **transfer**. Het eindoordeel is een aparte rij, los ondertekend van de gesprekken.

Boven de beslissing staat **Bewijs van dit seizoen** — ingeklapt, één tik weg — met het hele seizoen in hetzelfde paneel dat de trainer bij een gesprek leest. De cijfers komen uit één verzameling, dus waar het hoofd opleiding het eindoordeel op baseert, is zichtbaar hetzelfde als wat de trainer zag.

De knop *Eindoordeel vastleggen* staat bij de gesprekkenlijst, onder de cyclus. De knop blijft **uitgeschakeld totdat elk gesprek in de cyclus is afgetekend of als niet doorgegaan is gemarkeerd**, met de voortgang op de knop zelf — bijv. *Eindoordeel vastleggen (3/5 gesprekken afgerond)* — zodat duidelijk is waarom hij nog niet beschikbaar is in plaats van dat hij ontbreekt.

## Carryover

Bij het instellen van een nieuw huidig seizoen draait een eenmalige taak: elk open POP-dossier uit het vorige seizoen wordt voor het nieuwe seizoen gerepliceerd — verse gesprekken, vers `created_at`, maar de open doelen van de speler (alles behalve `completed` of `archived`) worden meegenomen.

Tekstuele inhoud van gesprekken wordt **niet** meegenomen. Elk seizoen begint schoon; de geschiedenis blijft staan waar het stond.

## Doelen­koppelingen

Een doel kan nu gekoppeld worden aan één of meer methodische entiteiten:

- een **principe** (bv. *opbouwen vanaf achteren*)
- een **voetbalhandeling** (bv. *passen onder druk*)
- een **positie** (bv. *nummer 8*)
- een **spelerswaarde** (toewijding, leerbaarheid, leiderschap, veerkracht, communicatie, werkethiek, fairplay, ambitie — bewerkbaar via Configuratie → Keuzelijsten)

De koppelingen verschijnen op het spelerprofiel en in de printsjabloon; ze maken queries mogelijk als "alle doelen gekoppeld aan *veerkracht* in de academie" of "elke speler die werkt aan *opbouwen vanaf achteren*".

### Doelen en ontwikkelgesprekken

Een doel kan ook aan een **ontwikkelgesprek** worden gekoppeld. Op het gespreksformulier vinkt de coach onder **Doelen besproken in dit gesprek** de actieve doelen van de speler aan die zijn behandeld; die koppelingen worden bij het gesprek opgeslagen. Op *Mijn POP* toont een uitgevouwen gespreksmarker vervolgens een lijst **Besproken doelen**, zodat de zelfreflectie van de speler ingaat op de doelen die echt aan bod kwamen - POP en doelen worden zo echt gecombineerd in plaats van naast elkaar te staan. (Een afgesproken actie omzetten in een gloednieuw doel is een geplande vervolgstap; deze stap is het lees-/koppelweefsel.)

## Printen

De **Printen / PDF**-knop in het detailoverzicht opent een schone A4-layout: foto, seizoenslabel, huidige doelen + status, afgesproken acties per gesprek, en handtekening­regels voor coach / speler / ouder. Schakel *Opnieuw renderen met bewijspagina* in voor een tweede A4 met hetzelfde bewijs dat de trainer op het tabblad Bewijs leest en het hoofd opleiding op het eindoordeelscherm — één verzameling, dus de cijfers op papier en op het scherm kunnen niet uit elkaar lopen. De bewijspagina is alleen voor de staf: een speler of ouder die het eigen dossier print, krijgt de enkele A4, en de schakelaar wordt hun niet aangeboden.

## Configuratie

- **Configuratie → Keuzelijsten → Spelers­waarden** — bewerk de waarde-woordenlijst.
- **Hoofdmenu → Seizoenen** — lijst, toevoegen, huidig instellen. Een nieuw huidig seizoen instellen activeert de carryover.
- **Configuratie → Systeem** — *POP-cyclusstandaard* (2 / 3 / 4) en de *Print: standaard bewijs meenemen*-knop.
- **Per-team override** — op de team-bewerk­pagina kun je *POP-cyclus­grootte* afwijkend instellen.
- **Configuratie → POP-voorbereidingsvragen** — waar een trainer over nadenkt voor elk gesprek, met per gesprek in de cyclus een eigen set. Elke academie begint met een meegeleverde set en kan vragen toevoegen, herformuleren, verslepen of verwijderen.
- **Configuratie → POP-gespreksformulieren** — welke set gespreksformulieren de academie gebruikt, of geen. Zie *Gespreksformulieren* hieronder.

### Voorbereidingsvragen

Aan het begin van het seizoen vraag je andere dingen dan aan het eind, dus elk gesprek in de cyclus heeft een eigen set prompts. De antwoorden zijn **alleen zichtbaar voor de trainer en het hoofd opleiding** — nooit voor de speler of de ouders, op geen enkel scherm.

Herformuleer je een vraag die al beantwoord is, dan verandert dat niet waar die antwoorden op gegeven zijn: de oude formulering blijft staan bij de voorbereidingen die ze bevatten, en de nieuwe formulering geldt vanaf het volgende gesprek. Een voorbereiding van vorig seizoen leest dus nog steeds zoals hij geschreven is.

### Gespreksformulieren

Een academie kan haar POP-gesprekken voeren op een vast eigen formulier: een lijst onderdelen in vier secties (technisch, tactisch, fysiek en mentaal), waarbij de speler en de trainer elk onderdeel een score van 1 tot 5 geven, met daarbij de grootste kwaliteit, twee ontwikkelpunten en een ondertekening. Het formulier is van de academie zelf. De tekst wordt precies gebruikt zoals de academie hem schreef, in elke taal, en is in TalentTrack niet te bewerken. Een keeper krijgt het keepersformulier en elke andere speler het formulier voor veldspelers.

Formulieren staan bij elke academie uit tot iemand met het recht om instellingen te wijzigen een set kiest onder **Configuratie → POP-gespreksformulieren**. Een set kiezen zet klaar waar de scores van de trainer onder worden opgeslagen:

- per onderdeel een subcategorie voor evaluaties, onder de vier hoofdcategorieën die de academie al heeft, zodat het radardiagram, de evaluatiegeschiedenis en de rapporten de scores van de trainer tonen zoals elke andere beoordeling;
- per formulier een evaluatietype ("POP-gesprek veldspeler" en "POP-gesprek keeper").

De gewone evaluatieformulieren bieden deze onderdelen en typen nooit aan: die horen bij het gespreksformulier. Een formulier scoort in vijf stappen, dus de beoordelingsschaal van de academie moet er vijf hebben (de standaard één tot vijf sterren). Heeft de schaal een ander aantal stappen, of ontbreekt een van de vier hoofdcategorieën of staat die uit, dan wordt het kiezen van een set geweigerd met een melding en verandert er niets. Met *Geen* zet je de formulieren weer uit; de categorieën blijven staan, omdat evaluaties ze al kunnen gebruiken.

Een formulier verandert nooit meer als het in gebruik is. Veranderen de tekst of de onderdelen, dan komt er een nieuwe versie van het formulier bij, en gesprekken die al gevoerd zijn houden de versie waarop ze gevoerd zijn.

#### Wie ziet wat op het formulier, en wanneer

De speler en de trainer vullen elk hun eigen deel in, en elk deel slaat zichzelf op terwijl het wordt ingevuld.

| Deel | De speler | Een ouder | Staf die het dossier mag openen | Staf die het dossier mag bewerken |
| --- | --- | --- | --- | --- |
| Het deel van de speler, voordat de speler het klaar meldt | leest en wijzigt het | niets | niets | niets |
| Het deel van de speler, na "klaar", voor het ondertekenen | leest het, wijzigt het tot het gesprek begint | niets | leest het | leest het |
| Het deel van de trainer, voor het ondertekenen | niets | niets | leest het | leest en wijzigt het |
| Alles, na het ondertekenen | leest het | leest het, tenzij de speler het POP voor hen afschermt | leest het | leest het |

- **De scores van de speler zijn van de speler.** Niemand anders kan ze wijzigen, en niemand anders ziet ze voordat de speler de voorbereiding klaar meldt, ook het hoofd ontwikkeling en de academiebeheerder niet. Een ouder ziet niets van het formulier tot het is ondertekend.
- **Het deel van de speler sluit als het gesprek begint**, op de geplande tijd, of bij het ondertekenen als dat eerder is. Tot dan kan de speler "klaar" ook terugdraaien.
- **Het formulier volgt de positie van de speler.** Een speler wiens eerste voorkeurspositie keeper is, krijgt het keepersformulier; elke andere speler, ook een speler zonder positie, krijgt het formulier voor veldspelers. Op het gespreksscherm staat welk formulier het gesprek gebruikt.
- **Het formulier ligt vast zodra de speler is begonnen.** Daarvoor kan de trainer met de wisselknop op het gespreksscherm naar het andere formulier wisselen; wat de trainer zelf invult, legt het formulier niet vast. Zodra de speler iets heeft opgeslagen, is de wisselknop weg. Het formulier en de versie ervan blijven vanaf de eerste opslag bij het gesprek, zodat een latere versie van het formulier een begonnen gesprek nooit verandert.
- **Ondertekenen slaat alles in één keer op.** De scores van de trainer worden een evaluatie, gekoppeld aan het gesprek en zichtbaar in het radardiagram en de geschiedenis zoals elke andere. De twee ontwikkelpunten worden doelen, gekoppeld aan het gesprek naast de doelen die besproken zijn. Lukt een deel daarvan niet, dan wordt niets opgeslagen. Ondertekenen vraagt om een score van de trainer op elk onderdeel, de afgesproken grootste kwaliteit, en beide ontwikkelpunten met een actie, de hulp van de trainer en een deadline. Het wacht niet op de speler: een speler die de voorbereiding nooit klaar meldde, houdt een lege kolom.
- **De scores van de speler worden nooit een evaluatie**, zodat een zelfbeoordeling zich in rapporten nooit mengt met de beoordelingen van de trainer.
- De evaluatie die een ondertekend formulier opsloeg, kan niet los worden gewijzigd of verwijderd; het ondertekende formulier is het verslag.

#### De speler bereidt voor op het formulier

Bij een academie met gespreksformulieren bereidt de speler het volgende gesprek voor op het formulier in *Mijn POP*, in plaats van met de vrije zelfreflectie. Een academie zonder formulieren houdt de zelfreflectie zoals die was.

- **Het opent 14 dagen voor het geplande gesprek.** Daarvoor staat in *Mijn POP* vanaf welke datum de speler kan voorbereiden. Voor een gesprek zonder geplande datum kan de speler nog niet voorbereiden; *Mijn POP* zegt dat het opent zodra het gesprek is gepland.
- **Eén stap per onderdeel**, daarna de grootste kwaliteit, met een stappenbalk bovenaan. Elk item toont de naam en wat het betekent, en een rij van vijf genummerde keuzes; het label van de gekozen score staat onder de rij, en **Wat betekenen de cijfers?** opent de hele schaal. De grootste kwaliteit is een tekstveld met de eigen vraag van het formulier onder het label.
- **Alles slaat zichzelf op** terwijl de speler kiest en typt, met de gewone opslagstatus, ongedaan maken en terugdraaien.
- **Klaar** is een eigen knop op de laatste stap. Die slaat op wat nog openstaat, meldt de voorbereiding klaar en toont een samenvatting: hoeveel items een score hebben, het gemiddelde per onderdeel, en de grootste kwaliteit. Vanaf dan kan de trainer het deel van de speler lezen. **Toch aanpassen** draait "klaar" terug en opent het formulier weer, tot het gesprek begint.
- **Zodra het gesprek begint**, op de geplande tijd of bij het ondertekenen, is de voorbereiding alleen-lezen: *Mijn POP* toont de samenvatting met een regel dat het gesprek is begonnen.
- De trainer ziet dat de speler is begonnen, omdat de wisselknop voor het formulier verdwijnt bij de eerste opslag van de speler. Wat de speler schreef, blijft uit beeld tot "klaar".

#### De trainer vult het formulier in

Op het gespreksscherm staat bij een gesprek met een gespreksformulier het formulier op het tabblad **Gesprek**, onder de geplande datum en de notities.

- **De voorbereiding van de speler staat naast jouw cijfers.** Zolang de speler die niet klaar meldt, staat bij elk item *Speler: nog niet klaar* en zie je geen van de antwoorden. Vanaf "klaar" zie je bij elk item het cijfer van de speler, lichtblauw op de keuze, en staat de grootste kwaliteit van de speler boven die van jou.
- **Eén rij per item.** Je geeft je cijfer met de vijf genummerde keuzes; het cijfer van de speler staat op dezelfde rij. **Bespreken** staat bij elk item waar de twee cijfers verschillen, hoe weinig ook, en de kop van een onderdeel telt ze. **Aangepast** staat bij een item dat de speler wijzigde nadat de voorbereiding klaar was gemeld.
- **De onderdelen zijn uitklapbaar**, één per categorie, daarna de grootste kwaliteit en de twee ontwikkelpunten. Elke categorie heeft een veld voor toelichting.
- **De grootste kwaliteit:** wat de speler schreef, en wat jullie afspraken.
- **Twee ontwikkelpunten (SMART).** Elk heeft wat de speler doet, hoe de trainer helpt, een deadline, en eventueel het item dat het ontwikkelt. Elk wordt bij het ondertekenen een doel van de speler.
- **Alles slaat zichzelf op**, met de gewone opslagstatus, ongedaan maken en terugdraaien.
- **Gesprek afronden en ondertekenen** is een eigen knop, met een bevestiging. Die blijft dicht tot het formulier compleet is, en de lijst erboven noemt wat nog ontbreekt: een cijfer op elk item, de afgesproken grootste kwaliteit, en van elk ontwikkelpunt de actie, de hulp en de deadline. De voorbereiding van de speler is niet nodig. Ondertekenen zet de cijfers vast en bewaart ze als evaluatie, die het radardiagram en de evaluatiegeschiedenis dan tonen; het maakt de twee doelen aan, gekoppeld aan het gesprek naast de doelen die je als besproken markeerde; en het vraagt de speler en de ouder om te bevestigen. Voor die tijd komt er niets in de evaluaties of de doelen.
- Na het ondertekenen is het formulier alleen-lezen, en kun je de bevestiging van de speler en de ouder vastleggen zoals voorheen.

#### Het ondertekende formulier lezen en bevestigen

Zodra de trainer ondertekent, krijgen de speler en het gezin bericht en kunnen ze het hele gesprek lezen in *Mijn POP*.

- **Eén bericht bij het ondertekenen**, aan dezelfde mensen als andere berichten over de speler, naar leeftijd: tot en met tien de ouders, op elf de speler en de ouders, vanaf twaalf de speler. Van de ouders krijgen alleen zij die het ontwikkelplan mogen zien het: niet zolang de speler de POP voor die ouder afschermt, en niet zolang de academie de ontwikkelinformatie nog niet voor het gezin heeft opengezet. Een ouder die geen bericht krijgt, leest en bevestigt het gesprek gewoon in *Mijn POP*. Het bericht opent *Mijn POP* bij het gesprek, met het ondertekende formulier in beeld. Het gaat één keer weg; twee keer ondertekenen kan niet.
- **Zolang een ondertekend formulier op je bevestiging wacht**, staat bovenaan *Mijn POP* een melding met een link ernaartoe. Die verdwijnt zodra je hebt bevestigd.
- **Het ondertekende formulier, één uitklapblok per onderdeel.** De titel van elk blok toont het gemiddelde van de speler en dat van de trainer. Daarin staan bij elk item beide cijfers met hun label, en daarna de toelichting van de trainer bij dat onderdeel. Het eerste blok staat open; de andere gaan open met een tik of met Enter.
- **Een speler die niet heeft voorbereid**, leest "niet ingevuld" in de eigen kolom. De cijfers van de trainer staan er gewoon.
- **Onder de blokken**: de afgesproken grootste kwaliteit met daaronder wat de speler schreef, en de twee ontwikkelpunten met de actie, hoe de trainer helpt, de deadline en een link naar het doel.
- **Ik heb het gelezen** bevestigt het gesprek. De speler bevestigt voor zichzelf en een ouder voor het eigen deel; daarna staat in het blok wanneer het is bevestigd. Voor het ondertekenen valt er niets te bevestigen, en wordt de bevestiging geweigerd.
- Een ouder ziet niets van het formulier voor het ondertekenen, en een ouder die niet aan de speler is gekoppeld ziet helemaal niets.

## Werkflow­herinneringen

Er zijn drie taaktemplates geregistreerd:

- `POP_conversation_due` — herinnert de verantwoordelijke coach wanneer `scheduled_at` van een gesprek nadert.
- `POP_verdict_due` — herinnert het hoofd academie aan het einde van het seizoen.

Beide gebruiken de werkflow- en takenmotor — dezelfde inbox, dezelfde herinnerings­cadans die je instelt via Configuratie → Werkflow.

### Zelfreflectie-nudge

Wanneer het planvenster van een gesprek opent, krijgt de speler een taak **"Bereid je voor op je ontwikkelgesprek"** in *Mijn taken / Werk van vandaag*, met als deadline de gespreksdatum. Erop tikken opent *Mijn POP* bij de zelfreflectie. De sweep die deze taken aanmaakt draait op de planner van de werkflowmotor en is idempotent - precies een taak per gesprek, geen duplicaten. Het derde sjabloon `pdp_self_review` levert deze taak.

Het is een **duwtje, geen poort**:

- Het opslaan van de reflectie **voltooit** de taak.
- Het voeren van het gesprek **lost de taak automatisch op** zonder gevolgen, ook als de reflectie nooit is ingevuld.
- Het gesprek markeren als niet doorgegaan lost de taak op dezelfde manier op. De markering ongedaan maken **opent** de taak weer, tenzij de speler die al had voltooid.
- Er wordt nooit iets geblokkeerd als de speler het negeert.

Aan de coachkant krijgt de gesprekslijst een kolom **Zelfreflectie** met **Klaar / Nog niet** per komend gesprek - alleen ter info, nooit een poort voor het voeren of ondertekenen.

## Cyclusvoortgang en bevestigingen

Op de POP-detailpagina staat de cyclusvoortgang nu als **(X van N ondertekend)** naast de cyclusgrootte, zodat je in één oogopslag ziet hoe ver de cyclus is. Elke gespreksregel laat zien:

- een afgeleide **status**-badge (Gepland / Gehouden / Ondertekend) in plaats van drie aparte datum/ja-kolommen
- een **Bevestigingen**-kolom met iconen voor ouder (👤) en speler (⚽) — `✓` na bevestiging, `·` zolang de bevestiging nog ontbreekt

De samenvattingskaart heeft een hulpknop die het PDP-onderwerp direct in de docs-drawer opent.

## Archiveren versus definitief verwijderen

POP-dossiers kennen **twee** verwijderpaden zodat destructief opruimen nooit per ongeluk de verkeerde regel raakt.

- **Archiveren** — soft-delete. Het dossier verdwijnt uit de standaardlijst, maar elke rij blijft in de database staan. Coaches met bewerkrechten kunnen een actief dossier archiveren (knop *Archiveren* in de actiekolom). Beheerders zetten de schakelaar *Gearchiveerd tonen* aan op de POP-lijst en klikken op *Herstellen* om het dossier terug te halen. Dit is het juiste antwoord wanneer een speler halverwege het seizoen vertrekt of de cyclus per ongeluk is geopend.
- **Definitief verwijderen** — onomkeerbare hard-delete. Alleen beschikbaar voor operators met de capability `tt_delete_pdp` (standaard alleen voor beheerders). Twee ingangen: de knop *PDP definitief verwijderen* op de detailpagina van het POP-dossier, **en** een actie *Permanent verwijderen* per rij bij gearchiveerde dossiers (zet de schakelaar *Gearchiveerd tonen* aan op de POP-lijst — operators met `tt_delete_pdp` zien deze schakelaar ook zonder herstelrechten). Beide openen dezelfde bevestigingspagina die:
 - een **cascade-samenvatting** toont — hoeveel gesprekken / eindoordelen / kalenderkoppelingen / POP-blokken / doel-koppelingen er verdwijnen.
 - vereist dat de operator de **naam van de speler** letterlijk overtypt voordat de knop *PDP definitief verwijderen* actief wordt (hoofdletterongevoelig, met tolerantie voor extra spaties).
 - een **CSV-momentopname vóór verwijdering** schrijft naar `wp-content/uploads/tt-pdp-deletes/pdp-<dossier-id>-<tijdstempel>.csv` voordat de cascade wordt uitgevoerd. Het absolute pad wordt vastgelegd in de audit-log-entry `pdp.deleted_with_cascade`, samen met de rijaantallen per tabel.
 - de cascade over vijf tabellen draait binnen één transactie. Elke fout draait alles terug; gedeeltelijke status na een fout is onmogelijk.

Gebruik standaard *Archiveren*. Grijp alleen naar *Definitief verwijderen* voor AVG-wisbeleid, ouderverzoeken of andere legitieme bewaartermijn-zaken. Het CSV-bestand is je audit trail — bewaar het.
