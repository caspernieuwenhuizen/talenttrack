<!-- audience: user, admin -->

# Metingen & Testen

Een **meting** is één geregistreerde waarde van een test voor één speler op
een datum — een sprinttijd, een lengte, een sprong, een beep-testniveau.
Metingen geven de fysieke en atletische ontwikkeling van een speler een
chronologisch, vergelijkbaar verloop, naast de beoordelingen en doelen.

Deze pagina beschrijft het fundament: het datamodel en wie wat ziet. De
instelwizard, de invoerschermen en het trendoverzicht per speler bouwen
hierop voort.

## De onderdelen

- **Test (definitie)** — iets wat je meet (bijv. "Sprint 30m", "Lengte").
  Elke test hoort bij een **categorie** en heeft een **waardetype**, een
  **eenheid**, een **frequentie** en een **richting** (is hoger of lager
  beter?).
- **Categorie** — de groep waar een test onder valt. Standaard gevuld met
  *Antropometrie*, *Fysiek*, *Techniek* en *Mentaal*; een beheerder kan de
  lijst aanpassen.
- **Eenheid** — de meeteenheid. Standaard gevuld met gangbare eenheden (cm,
  m, kg, g, s, min, herhalingen, niveau, %, bpm); een test kiest er één **of**
  geeft een eigen, aangepaste eenheid op.
- **Frequentie** — hoe vaak de test moet plaatsvinden: jaarlijks, twee keer
  per jaar, per kwartaal, maandelijks of ad hoc. Dit voedt "wie is aan de
  beurt".
- **Sessie** — een gepland testmoment voor één team: één test, één datum. Staf
  voert per speler één waarde in.
- **Streefwaarde** — een band per leeftijdsgroep (groen / oranje) voor een
  test. Een geregistreerde waarde krijgt groen, oranje of rood ten opzichte
  van de band voor de leeftijdsgroep van de speler, rekening houdend met de
  richting van de test.
- **Statusniveaus** — alleen voor het waardetype **status**: een door de
  beheerder ingestelde, geordende reeks gekleurde niveaus (bijv. *Risico*
  rood, *Aandacht* oranje, *Op koers* groen). Een statustest registreert per
  speler een niveau in plaats van een getal; het laatste niveau van de speler
  verschijnt als een gekleurde chip op het profiel.

## Statustests (een handmatige spelersstatus)

Een **status**test is een eenvoudige, handmatig bijgehouden, gedateerde
spelersstatus — een tussenoplossing totdat het berekende statussignaal rijk
genoeg is. Hij gebruikt het metingenraamwerk en krijgt zo automatisch
gedateerde historie en zichtbaarheid op het profiel.

- Kies **Een status (gekleurde niveaus)** als waardetype bij het aanmaken van
  de test. De wizard brengt je daarna naar het bewerkscherm van de test.
- Stel op het bewerkscherm de **statusniveaus** in van laag naar hoog: elk
  niveau heeft een label en een kleur uit een vast palet (groen, limoen,
  geel, amber, oranje, rood, cyaan, blauw, grijs). Maak het label van een niveau
  leeg om het te verwijderen; de rijvolgorde is de bewaarde volgorde.
- Registreer een status net als elke andere test — *Metingen vastleggen* toont
  per speler een gekleurde **statuskiezer** in plaats van een getalveld:
  een keuzelijst waarvan zowel het gesloten veld als elke optie het
  kleurvierkant van het niveau naast het label toont, breed genoeg zodat het
  langste label nooit wordt afgekapt. De kiezer is volledig met toetsenbord en
  touch te bedienen (openen met Enter/Spatie of de pijltjestoetsen, bewegen
  met ↑/↓, type-vooruit, Escape om te sluiten); met JavaScript uit valt hij
  terug op een gewone keuzelijst.
- Op het spelersprofiel verschijnt het laatste niveau als een gekleurde chip
  in het tabblad **Metingen**, in de kleur van dat niveau. Statustests hebben
  geen groene/oranje streefband — hun kleur komt volledig uit het gekozen
  niveau.

Elke statuswijziging is een gedateerde vermelding op het spelersrecord, zodat
de statushistorie van de speler in de tijd opvraagbaar en zichtbaar is. Een
voorgedefinieerde categorie **Spelersstatus** is beschikbaar om deze tests te
groeperen.

## Wie ziet wat

Zichtbaarheid volgt de autorisatiematrix — geen enkele rol is hardgecodeerd:

| Persona | Ziet |
| --- | --- |
| **Speler** | Alleen de eigen metingen en trend. |
| **Ouder** | Alleen de metingen van het eigen kind. |
| **Assistent-/hoofdtrainer, teammanager** | De resultaten en sessies van het eigen team. |
| **Hoofd opleiding, academiebeheerder** | De resultaten van elk team, academiebreed. |

Trainers voeren resultaten in en bewerken die voor hun eigen team. De
testcatalogus (definities en streefwaarden) wordt opgezet door het hoofd
opleiding of een academiebeheerder. Een academiebeheerder of hoofd opleiding
kan elke waarde wijzigen.

## Frequentiewaarden

| Waarde | Betekenis |
| --- | --- |
| `annual` | Eén keer per seizoen |
| `biannual` | Twee keer per seizoen |
| `quarterly` | Vier keer per seizoen |
| `monthly` | Maandelijks |
| `adhoc` | Geen vaste frequentie |

## De metingen van een speler bekijken

Spelers en ouders krijgen een tegel **Mijn metingen** die de
*Metingen*-weergave opent: elke test gegroepeerd per categorie, met de
laatste waarde, een groen/oranje/rood vlaggetje ten opzichte van de
streefwaarde voor de leeftijdsgroep, een kleine trendlijn en de
frequentie. Een ouder ziet de weergave van het kind.

Staf ziet hetzelfde **in context** op het spelersprofiel: open een speler
en ga naar het tabblad **Metingen** (naast Beoordelingen). De badge op
het tabblad toont voor hoeveel tests de speler resultaten heeft.

Het **In één oogopslag**-paneel van de speler bevat ook een signaal
**Metingen** naast Gem. beoordeling, Aanwezigheid en Doelen: het aantal
tests waarvoor de speler nu een waarde heeft, met een hint hoeveel daarvan
*onder de norm* vallen (oranje of rood ten opzichte van de leeftijdsband)
— of *op schema* als dat er geen zijn. Het verwijst rechtstreeks naar het
tabblad Metingen voor de volledige tijdlijn per test. Het signaal
verschijnt alleen voor wie metingen mag inzien, zodat de stand nooit
zichtbaar wordt voor een rol die de onderliggende tests niet mag openen.

## Resultaten vastleggen

Staf krijgt een tegel **Metingen vastleggen**. Kies een team, een test en
een datum, voer per speler één waarde in en klik op **Alles opslaan** — de
hele selectie wordt in één keer opgeslagen (lege spelers worden
overgeslagen) en gekoppeld aan een testsessie voor die datum. Numerieke
tests tonen een getalveld met de eenheid; geslaagd/niet-tests tonen een
keuzelijst. Een trainer kan alleen voor de eigen teams vastleggen; het
hoofd opleiding en de academiebeheerder kunnen voor elk team vastleggen.

## Testdekking (wie is aan de beurt)

Staf krijgt ook een tegel **Testdekking**. Kies een team en het scherm
toont, voor elke test met een herhaling, hoeveel van de selectie
**up-to-date** is versus het tekort - en noemt de spelers die **te laat**,
**binnenkort aan de beurt** of **nooit** getest zijn. Het is spelergericht:
het begint bij de selectie en laat precies zien wie deze cyclus nog een test
nodig heeft, zodat je een sessie kunt plannen. Tests zonder herhaling
(*ad hoc*) tellen niet mee voor de dekking. Een coach ziet de eigen teams;
het hoofd opleiding en de academiebeheerder zien elk team. Dezelfde gegevens
zijn beschikbaar via REST op
`GET /wp-json/talenttrack/v1/teams/{team_id}/measurement-coverage`.

## Een test aanmaken

Het hoofd opleiding (of een academiebeheerder) maakt tests aan met de
wizard **Nieuwe test** — bereikbaar vanaf het scherm *Metingen
vastleggen*. De wizard kent drie stappen:

1. **Gegevens** — de categorie, een naam en het type waarde (een getal,
   een schaalscore, geslaagd/niet of een status met gekleurde niveaus).
2. **Eenheid & frequentie** — de eenheid (uit de lijst of een eigen
   eenheid), of hoger of lager beter is, en hoe vaak de test plaatsvindt.
3. **Streefwaarden** — optionele groene en oranje banden per
   leeftijdsgroep; een geregistreerde waarde krijgt een vlaggetje ten
   opzichte van de band voor de leeftijdsgroep van de speler. Je kunt deze
   leeg laten en later toevoegen.

Bij voltooien worden de test en de streefwaarden in één keer aangemaakt.

## De testcatalogus beheren

Het hoofd opleiding (of een academiebeheerder) krijgt een tegel **Tests
beheren** onder *Configuratie*. Die opent een lijst met elke test die je
academie heeft ingesteld — naam, categorie, eenheid, richting en frequentie
— met de status **Actief** of **Inactief**, en drie acties per rij:

- **Bewerken** — opent de test in een plat formulier. Je kunt de naam,
  categorie, het type waarde, de eenheid (uit de lijst of een eigen
  eenheid), de schaalgrenzen, de richting, de frequentie en de
  actief-schakelaar wijzigen, en de groene/oranje streefbanden per
  leeftijdsgroep ter plekke aanpassen. **Opslaan** legt vast; **Annuleren**
  brengt je terug naar de lijst (of naar waar je vandaan kwam).
  Geslaagd/niet-tests hebben geen streefbanden.
- **Activeren / Deactiveren** — een inactieve test blijft in de catalogus
  en behoudt de geschiedenis, maar wordt verborgen in de keuzelijst van
  *Metingen vastleggen*, zodat staf er geen nieuwe resultaten meer voor kan
  vastleggen.
- **Exporteren naar Excel** — downloadt alle vastgelegde resultaten van deze
  test als een opgemaakt `.xlsx`-bestand (zie hieronder).
- **Archiveren** — verplaatst de test naar de prullenbak (soft delete). Er
  gaat niets verloren; een beheerder kan hem herstellen.

### De resultaten van een test exporteren

Elke testregel — en het bewerkscherm van de test — heeft een actie
**Exporteren naar Excel**. Die maakt een opgemaakt werkblad voor die ene
test: een kopblok (testnaam, eenheid of *status*, datumbereik en club) boven
een vastgezette, vette kolomkoprij, en daaronder één regel per vastgelegd
resultaat met **speler, team, vastlegdatum, waarde, leeftijdsgroep en
vastgelegd-door**. Resultaten staan per speler gegroepeerd, zodat de reeks
van een speler in de tijd bij elkaar staat.

Bij een **status**-test toont de waardekolom het vastgelegde **niveaulabel**
(bijvoorbeeld *Op koers*), en de cel krijgt de kleur van dat niveau, zodat
het werkblad in één oogopslag leesbaar is — net als de statuschip op het
spelersprofiel. Numerieke tests tonen het getal met de eenheid.

De export gebruikt dezelfde exportpijplijn als de rest van het systeem en is
afgeschermd met dezelfde *lees*-rechten op `measurements`: alleen staf die de
resultaten van een test mag zien, mag ze exporteren.

Een test aanmaken gaat nog steeds via de wizard **Nieuwe test**, bereikbaar
boven aan deze lijst en vanaf *Metingen vastleggen*. Dezelfde catalogus is
beschikbaar via REST op
`/wp-json/talenttrack/v1/measurement-definitions` voor integraties en de
SaaS-frontend.

## Wisselen tussen de schermen

**Tests & metingen** heeft drie schermen voor staf — *Tests beheren* (de
catalogus inrichten), *Metingen vastleggen* (resultaten invoeren) en
*Testdekking* (zien wie aan de beurt is) — en ze verwijzen naar elkaar,
zodat je niet terug hoeft naar het dashboard:

- *Metingen vastleggen* toont een link **Tests beheren** naast **+ Nieuwe
  test**, zodat je snel de frequentie of banden van een test kunt aanpassen
  en meteen terugkeert.
- *Tests beheren* toont boven aan de lijst de links **Metingen vastleggen**
  en **Testdekking**.
- *Testdekking* toont een link **Tests beheren** (alleen voor staf die de
  catalogus mag bewerken).

Elke link draagt bij aankomst een contextuele terug-pil, zodat het
bestemmingsscherm een terugroute met één klik biedt naar waar je vandaan
kwam.
