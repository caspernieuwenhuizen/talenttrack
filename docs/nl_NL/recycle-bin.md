<!-- audience: admin -->

# Prullenbak — bewaartermijn, definitief verwijderen en AVG

Records in TalentTrack worden nooit in één klik verwijderd. Ze doorlopen drie
niveaus, elk definitiever dan het vorige:

1. **Actief** — de rij is live en verschijnt overal.
2. **Gearchiveerd** — verborgen in de dagelijkse lijsten, maar volledig
   herstelbaar. Een speler die de academie verlaat, een afgerond toernooi,
   een gesloten proefdossier: archiveren bewaart de geschiedenis zonder de
   actieve weergave te vervuilen.
3. **In de prullenbak** — klaargezet om definitief verwijderd te worden. Nog
   herstelbaar, maar met een klok eraan: een rij in de prullenbak wordt na de
   bewaartermijn automatisch opgeschoond, of direct als een beheerder de
   prullenbak leegt.

Deze pagina behandelt het niveau **prullenbak** — waarvoor het dient, wie
eraan mag komen, hoe lang dingen er blijven en hoe het voldoet aan de
AVG-verplichtingen van de academie voor gegevens van minderjarigen.

> De prullenbak is **alleen voor de academiebeheerder**. Zie [Wie de prullenbak mag beheren](#wie-de-prullenbak-mag-beheren).

## Wat in de prullenbak kan

Elke entiteit die gearchiveerd kan worden, kan ook in de prullenbak — 20
recordtypen, van spelers en teams tot beoordelingen, doelen, proefdossiers,
blessures, metingen en geplande rapporten. Opzoek- en configuratietabellen
(beoordelingsschalen, leeftijdsgroepen, woordenlijsten) kunnen **niet** in de
prullenbak: dat zijn gedeelde instellingen, geen spelerrecords, en er valt
niets te herstellen.

De lijst is verankerd aan dezelfde entiteitenkaart die het archief gebruikt,
zodat de prullenbak en het archief het altijd eens zijn over welke records ze
omvatten.

## Wie de prullenbak mag beheren

De prullenbak beheren — rijen in de prullenbak bekijken, herstellen en
definitief opschonen — vereist het recht **`tt_manage_recycle_bin`**.
Standaard houden slechts twee actoren dit recht:

- de **WordPress-beheerder** (degene die de installatie beheert), en
- de rol **Academiebeheerder** (`tt_club_admin`).

Coaches, hoofden ontwikkeling, scouts, staf en alleen-lezen waarnemers houden
het **niet** en kunnen de prullenbak niet bereiken. Dat is bewust: definitief
opschonen is de meest destructieve actie in het product en wordt daarom
strakker afgeschermd dan gewoon bewerken. Met name het recht
`tt_edit_settings` (dat elke bewerker van een instellingen-tab heeft) geeft
**geen** toegang tot de prullenbak.

In de [autorisatiematrix](authorization-matrix.md) wordt het recht gekoppeld
aan de entiteit `recycle_bin`, met `rcd` op globale scope alleen voor de
persona Academiebeheerder.

## De bewaartermijn

Een rij in de prullenbak wordt een **bewaartermijn** lang vastgehouden
voordat hij automatisch wordt opgeschoond. De standaard is **30 dagen**,
per club opgeslagen in `tt_config` onder de sleutel
`tt_recycle_bin_retention_days`. Er is nog geen instellingenscherm voor; een
beheerder die een andere termijn nodig heeft, kan de configuratiewaarde
rechtstreeks zetten, en het opschoonproces leest deze met een terugval op 30
dagen.

De termijn is een expliciete **bewaar- / hersteltermijn**, geen toeval:

- Het geeft medewerkers een respijtperiode om een verkeerde verwijdering
  ongedaan te maken — de meest voorkomende reden dat gegevens uit de
  prullenbak terugkomen.
- Het begrenst hoe lang voor verwijdering klaargezette gegevens blijven
  hangen, zodat de academie niet stilletjes records bewaart die ze besloten
  heeft te verwijderen.

## AVG — bewaargrondslag en het recht op vergetelheid

Dit zijn gegevens van minderjarigen, dus de bewaargrondslag is expliciet.

- **Rechtmatige hersteltermijn.** De termijn van 30 dagen is de
  gedocumenteerde bewaargrondslag voor records in de prullenbak: gegevens die
  voor verwijdering zijn gemarkeerd maar kort worden bewaard zodat een
  onbedoelde verwijdering kan worden teruggedraaid. Na de termijn verwijdert
  het opschonen ze definitief.
- **Artikel 17 — directe verwijdering.** Wanneer een ouder of voogd het recht
  op vergetelheid uitoefent en verwijdering *nu* moet gebeuren, leegt een
  beheerder de prullenbak (of schoont de specifieke rij op) in plaats van de
  30 dagen af te wachten. "Nu opschonen" is het pad voor directe verwijdering;
  de bewaartermijn is de standaard, geen ondergrens.
- **Overeenstemming met verwijdering.** Elke speler-PII-entiteit die de
  prullenbak kan bevatten, is geregistreerd in `PlayerDataMap`, het centrale
  manifest dat de inzage- en verwijdertooling doorloopt. Zo werken een
  gelijktijdige verwijderrun en de prullenbak over dezelfde set tabellen — de
  prullenbak kan nooit PII achterlaten die het verwijderpad anders zou
  weghalen, en andersom. (Let op: enkele entiteiten die in de prullenbak
  kunnen — teams, toernooien, eigen widgets, geplande rapporten,
  meting-*definities* — zijn academieconfiguratie, geen speler-PII, en staan
  terecht niet in `PlayerDataMap`.)

Voor de volledige AVG-handleiding (inzageverzoeken, de verwijderlevenscyclus
van een speler die komt en gaat) zie de Privacy-beheerdershandleiding in de
documentatie in het product.

## Eén eigenaar voor definitief verwijderen

Er moet precies één vertrouwensniveau zijn voor het vernietigen van gegevens.
Vóór de prullenbak gaten de oude per-entiteit "definitief verwijderen"-
endpoints (bijvoorbeeld `DELETE /players/{id}/permanent`) op
`tt_edit_settings` — een lagere lat dan het opschonen van de prullenbak zelf.
**Geen verwijderpad mag zwakker afgeschermd zijn dan de prullenbak.**

De beslissing: de oude `/permanent`-endpoints worden **opnieuw afgeschermd op
`tt_manage_recycle_bin`**, zodat elk definitief-verwijderpad — het opschonen
van de prullenbak én de oude per-entiteit-endpoints — hetzelfde recht
vereist. Deze herafscherming landt in het REST-werk van de prullenbak (issue
#2024): elke `DELETE …/permanent`-route (spelers, teams, evaluaties, doelen,
activiteiten, toernooien, vakanties, proefdossiers, prooftrajecten, blessures,
testtrainingen, eigen widgets, trainingsoefeningen) vereist nu
`tt_manage_recycle_bin`. Alleen `tt_edit_settings` houden volstaat niet meer
voor een definitieve verwijdering vanaf welk scherm dan ook.

## De centrale prullenbak

De prullenbak heeft een eigen scherm. Open **Configuratie → Systeem →
Prullenbak**, of ga rechtstreeks naar `?tt_view=recycle-bin`. Het is geen
dashboardtegel — het staat in het instellingengedeelte, en alleen
academiebeheerders (`tt_manage_recycle_bin`) kunnen erbij. Iedereen anders
ziet een melding "geen toegang".

Het scherm toont elk record in de prullenbak over alle entiteitstypen heen,
**gegroepeerd per type** met een aantal per groep (Spelers, Teams,
Evaluaties, …). Elke rij toont:

- de **identiteit** van het record (naam of titel, of `Record #<id>` als
  terugval),
- **wie het in de prullenbak zette en wanneer**, en
- een **dagen-tot-opschoning-badge** die aftelt naar de automatische
  opschoning. De badge wordt **rood in de laatste week** (7 dagen of minder),
  zodat een nakende definitieve verwijdering opvalt.

De prullenbak is **alleen-actie** — je kunt vanaf hier niet doorklikken naar
een record. Op elke rij staan twee inline-acties:

- **Herstellen** — zet het record terug naar het **archief**-niveau (niet
  direct naar actief). Het verlaat de prullenbak en verschijnt weer in de
  Gearchiveerd-lijst van de entiteit.
- **Nu verwijderen** — schoont het record definitief op. Voordat er iets wordt
  verwijderd, toont een bevestigingsvenster de **volledige
  cascadevoorbeeldweergave**: wat wordt verwijderd, welke verwijzingen worden
  gewist (behouden, niet verwijderd) en — als de opschoning **geblokkeerd** is
  omdat andere records er nog van afhangen — het afhankelijkheidsrapport. Een
  geblokkeerde opschoning schrijft niets en laat het record in de prullenbak.
  "Nu verwijderen" is het handmatige pad voor directe verwijdering (AVG artikel
  17); het wacht de bewaartermijn niet af.

Als de prullenbak leeg is, meldt het scherm dat in plaats van een lege tabel.

## Een record vanuit een lijst naar de prullenbak verplaatsen

Je hoeft de prullenbak niet te openen om er iets in te plaatsen. Elke
per-entiteit-lijst (spelers, teams, evaluaties, doelen, toernooien,
vakanties en de rest) heeft twee statusweergaven:

- **Actief** — actieve records.
- **Gearchiveerd** — verborgen, herstelbare records.

Een eerder derde tabblad, **Alle**, is verwijderd: records in de prullenbak
verschijnen nooit in een per-entiteit-lijst, dus "Alle" was misleidend.
Gearchiveerde rijen zijn de enige plek waar de verwijderactie staat.

Op een **gearchiveerde** rij zie je twee acties:

- **Herstellen** — zet het record terug in de actieve lijst.
- **Naar prullenbak** — markeert het record voor definitieve verwijdering.
  Dit is **omkeerbaar**: het record komt in de prullenbak en kan vandaaruit
  worden hersteld totdat het wordt opgeschoond. Het vervangt de oude knop
  "Definitief verwijderen", die gegevens direct vanuit de lijst vernietigde;
  de echte definitieve verwijdering staat nu alleen nog in de prullenbak.

Voordat het record wordt verplaatst, toont een bevestigingsvenster de
**volledige cascadevoorbeeldweergave** — elk gekoppeld record dat een latere
opschoning zou verwijderen, elke verwijzing die zou worden gewist, en alles
wat een definitieve verwijdering momenteel blokkeert. De verplaatsing zelf
wordt nooit geblokkeerd (ze is omkeerbaar); de blokkades worden ter
informatie getoond.

Direct nadat een record naar de prullenbak is verplaatst, biedt een melding
**Ongedaan maken** — met één klik staat het record weer buiten de prullenbak.

## Een gearchiveerd of verwijderd record openen

Je kunt de detailpagina openen van een record dat niet langer actief is.
Voorheen toonde een directe link naar een gearchiveerd of in de prullenbak
geplaatst record "bestaat niet", omdat de detailpagina alleen actieve records
laadde. Nu valt de pagina terug op een **compacte alleen-lezen samenvatting**
van het record.

De alleen-lezen pagina toont de identiteit van het record (naam en foto waar
aanwezig) en een paar kernvelden — genoeg om te herkennen om welk record het
gaat — plus een statusbanner. Het is bewust **niet** het volledige profiel en
heeft **geen Bewerken-knop**: om een niet-actief record te wijzigen herstel je
het eerst, daarna bewerk je het.

- Een **gearchiveerd** record toont een amberkleurige banner — "Dit record is
  gearchiveerd", met wie het archiveerde en wanneer — en twee acties:
  **Herstellen** (terug naar de actieve lijst) en **Naar prullenbak**.
- Een record in de **prullenbak** toont een rode banner — "In de prullenbak —
  wordt over N dagen verwijderd" — en twee acties: **Terugzetten naar archief**
  (uit de prullenbak, terug naar de gearchiveerde laag) en **Nu definitief
  verwijderen**.

Een record in de prullenbak is op deze manier alleen bereikbaar voor een
beheerder die de prullenbak mag beheren. Iedereen anders die de link van zo'n
record opent, krijgt de gewone "niet gevonden"-pagina — hetzelfde antwoord als
voor een record dat nooit heeft bestaan — zodat het bestaan van het record van
een verwijderde minderjarige nooit wordt bevestigd aan iemand die het niet mag
zien.

## Auditspoor

Elke prullenbakactie wordt vastgelegd in het auditlogboek met een stabiele
actiesleutel per entiteit:

- `{entity}.trashed` — in de prullenbak gezet
- `{entity}.restored` — uit de prullenbak hersteld
- `{entity}.purged` — definitief verwijderd

Bijvoorbeeld `player.trashed`, `evaluation.restored`, `goal.purged`. Deze
sleutels verschijnen in het actiefilter van de auditlog-weergave zodra de
bijbehorende acties hebben plaatsgevonden, zodat een beheerder precies kan
nagaan wat er in de prullenbak is gezet, hersteld of vernietigd, door wie en
wanneer.
