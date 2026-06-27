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
vereist. (De herafscherming zelf landt in het REST-werk van de prullenbak,
issue #2024; deze basis legt de beslissing vast en registreert het recht dat
de herafscherming zal gebruiken.)

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
