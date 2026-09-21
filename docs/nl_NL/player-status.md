---
title: Spelerstatus
group: performance
summary: 'Stoplicht-statusberekening: wegingen, drempels, gedragsbodem, gedrag + potentieel-registratie.'
audience: [user]
views: [player-status-capture, player-status-methodology, team-behaviour-capture]
module: TT\Modules\Players\PlayerStatusModule
capability: tt_view_player_status
order: 110
---

# Spelerstatus — stoplicht

Elke speler krijgt een **stoplichtstatus** — groen, oranje, rood of grijs — die samenvat hoe het ervoor staat. Het is de kop van elk gesprek over een speler; de onderbouwing staat één klik verder.

## Wat de kleuren betekenen

- **Groen** — op koers. Sterke evaluaties, aanwezig op trainingen, gedrag op orde.
- **Oranje** — op de rand. Cijfers vragen om aandacht; geen besluit, wel een signaal.
- **Rood** — de data geeft aan dat deze speler een interventiegesprek nodig heeft. Hoort thuis in een POP-gesprek, niet op een Post-it.
- **Grijs** — eerste beeld nog niet klaar. Nieuwe spelers of weinig data; het systeem heeft nog te weinig signaal.

Het algoritme markeert. Mensen besluiten. De POP-eindbeoordeling aan het eind van de cyclus is de formele call; het stoplicht is het kijkje daartussen.

## Hoe de kleur wordt bepaald

De meegeleverde methodiek weegt vier ingrediënten:

| Input | Weging | Wat het is |
| --- | --- | --- |
| Evaluaties | 40% | Gemiddelde evaluatiescore in de laatste 90 dagen |
| Gedrag | 25% | Gemiddelde gedragsobservatie in de laatste 90 dagen |
| Aanwezigheid | 20% | Aanwezigheidsratio bij trainingen in de laatste 90 dagen |
| Potentieel | 15% | Verwachting van de trainer over hoever de speler kan reiken |

Een gedragsscore onder het midden van je beoordelingsschaal plafonneert de kleur op oranje, ongeacht de overige scores.

**Dit zijn standaardwaarden, geen vaste regels.** Een academiebeheerder stelt onder **Methodiek spelerstatus** eigen wegingen, eigen oranje- en roodgrenzen en een eigen gedragsplafond in, academiebreed of per leeftijdscategorie. De wegingen moeten samen op 100 uitkomen; het scherm zegt dat en weigert een set op te slaan die dat niet doet. De standaarden hierboven gelden tot je een eigen instelling opslaat, en **Herstellen** zet ze terug.

## Waar zie je het

- **Mijn teams → teampagina** — een gekleurde stip naast elke speler. Sorteerbaar, filterbaar.
- **Spelerdetail (beheer)** — dezelfde stip in het spelerspaneel.
- **REST API** — `GET /players/{id}/status` en `GET /teams/{id}/player-statuses` voor eigen dashboards of integraties.

Coaches en hoofd opleidingen zien de volledige onderbouwing (de vier deelscores + de overschreden drempels).

**Alleen voor de staf.** Spelers en ouders zien de status en de potentieelband geen van beide, ook niet voor hun eigen dossier. Beide zijn het eigen oordeel van de academie over een kind: hoe het gaat, en hoe ver het naar verwachting komt. Een ouder die "potentieel: academieniveau, dit seizoen twee keer naar beneden bijgesteld" leest zonder het gesprek dat daarbij hoort, is precies wat deze regel voorkomt. Dat oordeel hoort in het gesprek thuis. De gezinsversie van het spelersrapport laat beide om dezelfde reden weg. Een academie die gezinnen de status bewust wil laten zien, kan `player_status` in de autorisatiematrix aan de persona ouder of speler toekennen. De standaard doet dat niet.

## Een holle stip betekent: berekend met minder

De status is een gewogen gemiddelde van de ingrediënten die daadwerkelijk een waarde hebben. Heeft een speler geen potentieel vastgelegd, dan valt potentieel weg en worden de overige wegingen onderling verdeeld — rekenkundig juist, maar het betekent wel dat twee spelers met dezelfde oranje stip op verschillende gronden beoordeeld kunnen zijn.

De stip zegt dat nu. **Een stip met een holle kern is berekend zonder minstens één van de gewogen ingrediënten**, en er overheen hoveren (of hem laten voorlezen) noemt welke: *"Extra aandacht — Berekend zonder potentieel."* Een gevulde stip betekent dat alles wat de methodiek vraagt aanwezig was. Diezelfde zin staat ook bij de redenen in de onderbouwing, dus je ziet het zowel op het spelersdossier als in de teamtabel.

Het signaal is bewust geen vijfde kleur. De kleur zegt nog steeds waar de speler staat; de ring zegt hoeveel de academie werkelijk weet voordat ze dat zegt.

Grijs — **Eerste beeld nog niet klaar** — is ongewijzigd en betekent nog steeds dat *alle* ingrediënten ontbreken, niet slechts één.

Integraties krijgen hetzelfde, maar dan als data: de statusrespons draagt `coverage` (0–1, het aandeel van de gewogen ingrediënten dat meetelde), `missing_inputs` (de ingrediënten die ontbraken) en `coverage_note` (de zin). Sorteren op `coverage` is de manier om de spelers te vinden die nog door niemand beoordeeld zijn.

## Inputs vastleggen

Gedrag en potentieel leg je op deze plekken vast:

| Waar | Gedrag | Potentieel |
| --- | --- | --- |
| **Spelersprofiel → kaart Gedrag & potentieel** — waar beide staan, met de knoppen **Gedrag vastleggen** en **Potentieel instellen**. Dezelfde twee knoppen staan ook bovenaan de pagina | ja | ja |
| Scherm **Gedrag & potentieel** — beide formulieren met de geschiedenis eronder. Je opent het via de link **Geschiedenis** op de kaart, of via *Bekijk alle gedragsbeoordelingen* in het formulier Gedrag vastleggen | ja | ja |
| **Teampagina → Selectie → Gedrag in bulk vastleggen** — de hele selectie op één scherm | ja | — |
| **Nieuwe evaluatie → 1 speler evalueren → Gedrag vandaag** — een optionele stap na de prestatiebeoordeling | ja | — |

### De kaart Gedrag & potentieel

Stafleden die het profiel van een speler openen, zien naast Identiteit één kaart voor beide inputs:

- **Gedrag** — de laatste beoordeling, wanneer die is gegeven en door wie, en het gemiddelde over de laatste 90 dagen — het getal dat het stoplicht leest.
- **Potentieel** — de huidige band, hoeveel dagen geleden die is vastgelegd en door wie, en **Tijd om er weer naar te kijken.** zodra die ouder is dan de herzieningstermijn van je academie.

Een helft waarin nog niets is vastgelegd zegt dat — *Nog geen gedrag vastgelegd.*, *Nog geen potentieelband ingesteld.* — en toont de knop ernaast als je het mag vastleggen. Bij een speler onder de 13 staat de zin over de leeftijd in plaats van de knop Potentieel instellen. Een helft die je academie heeft uitgezet, valt weg, en een speler of ouder op het eigen profiel ziet de kaart nooit.

Een gedragsbeoordeling is een score op de eigen beoordelingsschaal van je academie (**Configuratie → Beoordelingsschaal**), met een optionele notitie en een gerelateerde activiteit. Een potentieelband is een van Eerste team, Profvoetbal elders, Semi-prof, Top amateur of Basis.

Aanwezigheid en evaluaties worden via hun eigen flows vastgelegd; de calculator leest ze direct.

Koppelingen schrijven dezelfde gegevens via `POST /players/{id}/behaviour-ratings` en `POST /players/{id}/potential` (bandsleutels `first_team` / `professional_elsewhere` / `semi_pro` / `top_amateur` / `recreational`).

Beide formulieren vertellen nu zelf waar ze om vragen, op het scherm in plaats van in dit document.

Het gedragsformulier noemt de uiteinden van jullie eigen schaal en zegt erbij dat de beoordeling over de afgelopen week gaat, niet over de speler als geheel — de status leest het verloop over meerdere beoordelingen, dus één mindere week is informatie en geen oordeel.

Het potentieelformulier vraagt hoe hoog je denkt dat de speler **op zijn top** kan reiken, niet waar hij nu staat, en heeft een blokje *Wat de bands betekenen* naast de keuzelijst: één regel per band voor Eerste team, Profvoetbal elders, Semi-prof, Top amateur en Basis. De moeite waard om één keer als staf samen door te lezen, want twee trainers die naar de betekenis raden is precies hoe dezelfde speler verschillend wordt vastgelegd.

### Potentieel wordt niet gevraagd onder de 13

De klassen beschrijven hoe ver een speler **als prof** zou kunnen komen. Dat is een eerlijke vraag aan een trainer over een tiener en een gok over een kind, dus TalentTrack stelt hem niet onder de 13 jaar.

Bij een jongere speler zegt de kaart **Potentieel instellen** dat, in plaats van de klassen aan te bieden, en de API weigert een schrijfactie met dezelfde reden. Gedragsbeoordelingen blijven op elke leeftijd gewoon werken — hoe een kind traint, luistert en met ploeggenoten omgaat, is op zijn zevende prima vast te leggen.

Drie dingen volgen hieruit die het waard zijn om te weten:

- **De melding *Potentieel niet herzien* slaat ze ook over.** Zonder dat zou hij elke speler in een JO7-team markeren zodra die lang genoeg ingeschreven staat, voor altijd, zonder manier om hem op te lossen behalve precies het oordeel vastleggen dat deze regel wil voorkomen.
- **Al vastgelegde klassen blijven zichtbaar.** Heeft je academie eerder potentieel bij jongere spelers vastgelegd, dan staan die vermeldingen nog op het profiel en tekenen ze nog steeds het verloop. Wat stopt, is dat er opnieuw naar gevraagd wordt.
- **Een speler zonder geboortedatum krijgt de vraag wél.** Een leeg veld is geen bewijs dat iemand te jong is, en het zo behandelen laat een gat in de gegevens op een kapot scherm lijken. Vul de datum in en de regel gaat gelden.

De leeftijd ligt vast op 13 en is geen instelling. Daar begint jeugdvoetbal ontwikkelrichting als een echte vraag te behandelen, en een instelbaar minimum is precies het soort instelling dat één keer wordt gezet en daarna stilletjes een gat verklaart dat niemand kan vinden.

### Hoe vaak potentieel wordt verwacht

Per kwartaal. Het formulier zegt dat, en het laat zien waar deze speler staat: wanneer de band voor het laatst is vastgelegd, door wie, hoeveel dagen geleden, en of dat inmiddels te lang geleden is. De grens is jullie eigen instelling `alerts_potential_stale_days` — hetzelfde getal dat de melding *Potentieel niet herzien* gebruikt, zodat het scherm en de herinnering het nooit oneens kunnen zijn over wat te laat is.

Bij een speler bij wie nog nooit een band is vastgelegd, staat dat er letterlijk.

## Het verloop van het potentieel

Potentieel is geen etiket maar een inschatting die je bijstelt. Elke keer dat iemand het potentieel vastlegt, komt er een nieuwe regel met datum bij — er wordt niets overschreven — zodat je terugziet hoe het beeld van de club over een speler is verschoven.

Het scherm **Gedrag & potentieel** toont dat verloop nu onder de huidige band, met de nieuwste bovenaan. Per regel zie je de band, wanneer die is vastgelegd en door wie, eventuele notities, en hoe het is veranderd:

- **▲ naar boven bijgesteld** — richting het eerste elftal.
- **▼ naar beneden bijgesteld** — daarvandaan af.
- **= opnieuw bevestigd** — dezelfde band nog eens vastgelegd. Dat gebeurt bewust: dezelfde band opnieuw vastleggen *met een notitie* ("nog steeds eerste elftal, maar de laatste zes weken zijn vlak") is een echte handeling en blijft bewaard, terwijl dezelfde band opnieuw opslaan zonder toevoeging niets vastlegt.

De richting staat er in woorden bij, niet alleen als pijl en kleur, zodat het net zo leesbaar is voor wie de kleuren niet kan onderscheiden of een schermlezer gebruikt.

Bij één regel krijg je geen verloop te zien — er ís nog geen verloop, en de huidige band erboven vertelt dan alles.

De kaart Gedrag & potentieel op het spelersprofiel toont de huidige band, met een link **Geschiedenis** naar dit scherm zodra er meer dan één regel is. Alleen stafleden zien de kaart: een speler of ouder op het eigen profiel krijgt geen link naar een scherm dat diegene niet kan openen.

Twee keer naar beneden bijstellen in één seizoen is waar dit voor bedoeld is. Dat is een sterk signaal over de ontwikkeling, het stond altijd al in de gegevens, en tot nu toe zag niemand het zonder het OP erbij te pakken.

`GET /players/{id}/potential` geeft dezelfde reeks terug voor een koppeling, met de huidige band erbij.

## Gedrag of potentieel uitzetten

Niet elke academie werkt zo, en je hoeft geen van beide te gebruiken. Er zijn **drie** schakelaars, en ze beantwoorden drie verschillende vragen — wie er helemaal mee wil stoppen, wil meestal alle drie.

| Vraag | Waar | Wat het doet |
| --- | --- | --- |
| Leggen we dit überhaupt vast? | **Modules en functies** → *Gedragsbeoordeling* / *Potentieelbeoordeling* | Stopt nieuwe invoer en verbergt de ingangen — de knop op het spelersprofiel, de betreffende helft van het invoerscherm, en (bij gedrag) de stap in de evaluatiewizard en het bulkscherm per team. |
| Telt het mee in het stoplicht? | **Methodiek spelersstatus** → het vinkje *ingeschakeld* bij die input | Haalt de input uit de berekening. De overige inputs worden opnieuw gewogen, zodat de status niet omlaag wordt getrokken door iets wat ontbreekt. |
| Krijgen we er een herinnering over? | **Meldingenbeleid** → *Potentieel niet herzien* → *geforceerd uit* | Zet de herinnering voor de hele club uit. |

Drie dingen om te weten voordat je iets omzet:

- **Het scherm wordt een geschiedenis.** Zodra er niets meer kan worden vastgelegd — de academie heeft beide helften uitgezet, of jij hebt zelf geen van beide rechten — laat het scherm **Gedrag & potentieel** de formulieren weg en toont het wat er is vastgelegd: de recente gedragsbeoordelingen, de huidige band en het verloop daarachter. Het zegt in één regel dat hier niets wordt vastgelegd, en laat daarna zien wat er wél is vastgelegd. Hier komen de links **Geschiedenis** op de profielkaart uit, zodat een trainer die het dossier van een speler mag lezen maar er niets in mag vastleggen bij het dossier uitkomt in plaats van op een lege pagina. Wie het dossier helemaal niet mag lezen, krijgt alleen die ene regel.
- **Wat al is vastgelegd blijft altijd bewaard.** Invoer uitzetten verwijdert of verbergt niets: de band op een profiel, het verloop van het potentieel en elke gedragsbeoordeling blijven gewoon leesbaar zoals ze waren, en verschijnen weer in de formulieren zodra je het terugzet. Uit betekent *vraag ons hier niet meer om*, niet *verberg wat we al hebben bepaald*.
- **Invoer uitzetten zet ook de herinnering over potentieel stil**, dus je hoeft het meldingenscherm er niet bij te zoeken. Het haalt de input **niet** uit het stoplicht — dat is een aparte keuze, want een academie kan willen stoppen met nieuwe bands vastleggen terwijl de laatste nog wel meetelt.

## Rechten

- `tt_view_player_status` — zie de kleur en het potentieelverloop. Geldt voor de stafrollen die spelers mogen bekijken; **niet** voor spelers of ouders.
- `tt_view_player_status_breakdown` — zie de deelscores + redenen. Coaches + HO; **niet** voor ouders.
- `tt_rate_player_behaviour` — leg een gedragsobservatie vast. Coaches + HO.
- `tt_set_player_potential` — bepaal het potentieelniveau. Hoofdtrainers (voor hun eigen selecties) + HO.

### …en het recht is maar de helft van het antwoord

Elk van die rechten zegt wát je mag doen. **Bij welke** spelers je dat mag doen
is je teambereik, en de statusroutes stellen nu allebei die vragen.

- De status van één speler lezen stelt dezelfde vraag als het spelersdossier,
  dus een trainer leest de eigen selecties en niemand anders. Een ouder of een
  speler wordt geweigerd: gezinnen hebben helemaal geen leesrecht op de status.
- De statussen van een heel team lezen vraagt of je de spelerstatussen van dát
  team mag lezen — afgebakend op spelerstatus, niet op teams, zodat een Hoofd
  Ontwikkeling met academiebrede statusleesrechten nog steeds elk bord krijgt.
- Een gedragsobservatie vastleggen vraagt of je die speler mag bewerken. De
  rollen met `tt_rate_player_behaviour` konden dat al voor hun eigen spelers;
  wat verandert is dat de vastlegging niet meer op een kind buiten de eigen
  selecties kan belanden.
- Een potentieelband instellen stelt dezelfde vraag. Hoofdtrainers stellen
  banden in voor de selecties die ze trainen en worden geweigerd bij spelers
  van een ander; Hoofd Ontwikkeling, Clubbeheerder en beheerder hebben het
  recht academiebreed. Assistent-trainers stellen geen potentieel in.
