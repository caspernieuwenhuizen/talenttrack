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
frequentie. Een ouder ziet de weergave van het kind; staf opent de
metingen van een speler vanuit het spelersprofiel.

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
   een schaalscore of geslaagd/niet).
2. **Eenheid & frequentie** — de eenheid (uit de lijst of een eigen
   eenheid), of hoger of lager beter is, en hoe vaak de test plaatsvindt.
3. **Streefwaarden** — optionele groene en oranje banden per
   leeftijdsgroep; een geregistreerde waarde krijgt een vlaggetje ten
   opzichte van de band voor de leeftijdsgroep van de speler. Je kunt deze
   leeg laten en later toevoegen.

Bij voltooien worden de test en de streefwaarden in één keer aangemaakt.
