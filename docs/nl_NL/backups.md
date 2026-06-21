<!-- audience: admin -->

# Back-ups

TalentTrack heeft een eigen back-upmodule (los van een eventuele algemene WordPress-back-upplugin die je ook gebruikt). Snapshots dekken alleen de eigen `tt_*`-tabellen van de plugin — geen WordPress-gebruikers, geen media-uploads. Het doel is om je academiegegevens snel terug te kunnen zetten zonder de rest van de site mee te slepen.

## Waar je hem vindt

`Configuratie → Back-ups`. Zichtbaar voor beheerders en de rol **Head of Development**; de onderliggende capability is `tt_manage_backups`.

## Instellingen

- **Preset** — *Minimaal* (basisgegevens), *Standaard* (dagelijkse gegevens inclusief sessies/doelen/personen), *Uitgebreid* (alles inclusief auditlog + opzoeklijsten), of *Aangepast* (lijst per tabel). De omschrijving onder de keuzelijst werkt automatisch bij wanneer je een andere preset selecteert.
- **Schema** — dagelijks, wekelijks of op aanvraag (geen automatische runs).
- **Bewaartermijn** — hoeveel lokale back-ups bewaard worden voor de oudste verwijderd wordt. Standaard 30.
- **Lokale bestemming** — schrijft `.json.gz`-bestanden naar `wp-content/uploads/talenttrack-backups/`. De map wordt automatisch aangemaakt met een `index.php` + `.htaccess` die directe browsertoegang blokkeert.
- **E-mailbestemming** — verstuurt iedere back-up via wp_mail() naar een door komma's gescheiden ontvangerslijst. Bestanden groter dan 10 MB zijn voor de meeste mailservers te groot; in dat geval is de e-mail alleen een melding en wordt de back-up uitsluitend lokaal opgeslagen.

## Nu een back-up maken

De knop "Nu back-up maken" op de instellingenpagina draait een back-up zonder op het schema te wachten. Handig voor:
- Je instellingen end-to-end testen.
- Net vóór een risicovolle handeling (CSV-import, bulk-archief).
- Sites waar WP-cron onbetrouwbaar is (weinig verkeer, agressieve caching).

Tijdens de back-up komt er een schermvullende "Bezig met back-up…"-overlay over de pagina. Deze kan niet worden weggeklikt — zodra de server klaar is (meestal een paar seconden voor kleine academies, langer voor Volledig op een drukke installatie) wordt de pagina herladen en is de overlay verdwenen.

## Terugzetten

1. Kies een back-up uit de lokale lijst en klik **Terugzetten**. Een bevestigingsvenster vraagt of je het herstelvoorbeeld wilt openen.
2. De pagina toont een overzicht per tabel van wat vervangen wordt plus de pluginversie van de snapshot.
3. Typ **RESTORE** in het bevestigingsveld.
4. Verstuur. Tijdens het terugzetten verschijnt dezelfde "Bezig met…"-overlay over de pagina — niet weg te klikken totdat de server klaar is.
5. De actie maakt elke tabel in de snapshot leeg en speelt de rijen opnieuw in. Tabellen die op de site bestaan maar niet in de snapshot staan, worden niet aangeraakt.
6. Als rij-aantallen na het terugzetten niet overeenkomen met de verwachting verschijnt er een foutmelding.

Terugzetten tussen verschillende hoofdversies wordt geweigerd (een v2.x-snapshot zet niet terug op een v3.x-site). Binnen dezelfde hoofdversie (bijv. v3.12 → v3.14) is het toegestaan; schemamigraties dekken verschillen.

## Statusmelding

Het wp-admin TalentTrack-dashboard toont een korte melding met de back-upstatus:

- **Groen** — laatste succesvolle run binnen 24 uur.
- **Geel** — laatste succesvolle run tussen 1 en 7 dagen geleden, of nog geen run terwijl een schema is ingesteld.
- **Rood** — laatste run mislukt, of > 7 dagen oud, of geen bestemming ingeschakeld.

## Bestandsformaat

Elke back-up is een gzipped-JSON-document met:

```json
{
  "version":        "1.0",
  "plugin_version": "3.15.0",
  "created_at":     "2026-04-25T22:00:00Z",
  "preset":         "standard",
  "tables":   { "tt_players": { "columns": [...], "rows": [...] }, ... },
  "checksum": "sha256-..."
}
```

De controlesom wordt berekend over alleen de `tables`-subboom — terugzetten verifieert deze voordat de database wordt aangeraakt.

## Gedeeltelijk terugzetten (v3.16.0+)

Klik **Gedeeltelijk terugzetten** bij een opgeslagen back-up om specifieke rijen terug te halen zonder alles te overschrijven. De flow:

1. **Scope kiezen** — selecteer een tabel uit de back-up en geef een door komma's gescheiden lijst rij-id's op, of laat de id's leeg om alle rijen uit die tabel mee te nemen. Vink optioneel kindertabellen aan om naar beneden te volgen (bijv. begin bij een speler en neem zijn evaluaties mee).
2. **Diff bekijken** — voor elke tabel in de berekende sluiting zie je hoeveel rijen *nieuw* zijn (in back-up, niet in DB) en hoeveel *verschillen*. Kies per tabel een actie:
   - Groen: **Terugzetten** of **Overslaan**.
   - Geel: **Huidige behouden**, **Overschrijven met back-up**, of **Overslaan**.
3. **Uitvoeren** — verstuur de gekozen acties. Vink eerst **Proefdraai** aan als je de wijzigingen wilt berekenen zonder ze weg te schrijven.

De afhankelijkheidsmap is klein: spelers, teams, evaluaties, beoordelingen, sessies, aanwezigheid, doelen, personen, team-personen, functionele rollen, custom values en categoriegewichten. Een tabel toevoegen is één regel in `BackupDependencyMap::refs()`.

## Pre-bulk-veiligheid + ongedaan maken (v3.16.0+)

Vóór elke wp-admin-bulkactie die meer dan 10 rijen *archiveert* of *definitief verwijdert*, maakt TalentTrack automatisch een veiligheidsmomentopname. De snapshot is een gewone back-up, in metadata gemarkeerd zodat de bewaartermijn apart afgesteld kan worden.

Direct na afloop van de bulkactie verschijnt een melding met de link **Ongedaan maken via back-up →**. Die link voert een gedeeltelijk terugzetten uit op de veiligheids-back-up, beperkt tot exact de rijen die geraakt waren. De melding blijft 14 dagen staan; klik **Sluiten** om hem op te ruimen zonder terug te zetten.

De drempel van 10 rijen is filterbaar via `tt_backup_bulk_safety_threshold`.

## Datamigratie — export (v4.21.14+)

Om gegevens naar een **andere** TalentTrack-installatie te verplaatsen, gebruik je het onderdeel **Datamigratie** op de Back-uppagina. Vink de gegevenssets aan die je meeneemt (Spelers, Teams, Staf & rollen, Evaluaties, Activiteiten & aanwezigheid, Doelen, Keuzelijsten & configuratie) en klik op **Exporteren voor migratie** om een `.ttmig`-bestand te downloaden — gzip-JSON, dezelfde structuur als een back-up, met `kind: migration`.

Export bevat alleen gegevens: WordPress-gebruikers en media worden niet meegenomen. Koppelingen tussen installaties (`wp_user_id`) worden bij het importeren bepaald, niet in het bestand opgeslagen.

### Afzonderlijke records achterlaten (v4.26.8+)

Naast de selectievakjes per gegevensset heeft elke recordhoudende set (Spelers, Teams, Staf & rollen, Evaluaties, Activiteiten & aanwezigheid, Doelen) een **Toon N records**-uitklap. Elk record is standaard inbegrepen; haal het vinkje weg bij de records die je wilt achterlaten — handig om testspelers of kladrecords weg te laten voordat je naar een schone installatie migreert. Een record uitsluiten verwijdert ook de onderliggende rijen binnen dezelfde set (bijv. een activiteit uitsluiten verwijdert de bijbehorende aanwezigheidsrijen). "Keuzelijsten & configuratie" blijft alles-of-niets, want dat is referentiegegevens en geen testrecords.

Als je een record uitsluit waarnaar een andere inbegrepen set nog verwijst — bijvoorbeeld een speler uitsluiten maar diens evaluaties behouden — toont een bevestigingsstap die losgekoppelde verwijzingen vóór het downloaden. Je kunt **Toch downloaden** (de afhankelijke records worden geëxporteerd zonder het record waarnaar ze verwijzen) of annuleren en je selectie aanpassen. Zeer grote sets tonen alleen de eerste 500 records in de uitklap; records daarboven zijn altijd inbegrepen.

## Datamigratie — importvoorbeeld (v4.32.1+)

Op de doelinstallatie accepteert het onderdeel **Importeren vanaf een andere installatie** (net onder de exportbediening) een `.ttmig`-bestand. Kies het archief en klik op **Import voorvertonen** om het te inspecteren. Deze stap is **alleen-lezen** — hij valideert het bestand en rapporteert wat erin zit, maar wijzigt niets.

Het voorbeeld toont:

- **Validatie** — het bestand moet decodeerbaar zijn, het kenmerk `kind: migration` dragen en zijn controlegetal doorstaan (`sha256` over de gegevenstabellen). Een beschadigd of bewerkt archief wordt geweigerd. Een archief van een andere hoofdversie opent nog wel, maar met een compatibiliteitswaarschuwing.
- **Inhoud** — aantal rijen per gegevensset (Spelers, Teams, Staf & rollen, Evaluaties, Activiteiten & aanwezigheid, Doelen, Keuzelijsten & configuratie).
- **Wat er bij importeren zou gebeuren** — voor de recordsets met een natuurlijke sleutel (Spelers op voornaam + achternaam + geboortedatum, Teams op naam + leeftijdsgroep, Staf op voornaam + achternaam + e‑mail): hoeveel binnenkomende records **overeenkomen met een bestaand record** op deze installatie versus hoeveel **nieuw** zijn. De match gebeurt op stabiele sleutel, niet op id — ids verschillen tussen installaties, dus bronrecord 5 is niet doelrecord 5.

## Datamigratie — een import toepassen (v4.36.0+)

Vanuit het voorbeeld kun je met **Import configureren** het archief op deze installatie toepassen:

1. **Kies gegevenssets** — selecteer welke recordgroepen je importeert (Spelers, Teams, Staf & rollen, Evaluaties, Activiteiten & aanwezigheid, Doelen). Keuzelijsten & configuratie worden **niet** geïmporteerd; ze worden alleen gebruikt om verwijzingen te matchen (zie hieronder).
2. **Los matches op** — voor elke recordset waar een binnenkomend record overeenkomt met een bestaand record op de stabiele sleutel, kies je **Als nieuw toevoegen** (standaard — beide behouden) of **Het bestaande record bijwerken**.
3. **Koppel WordPress-gebruikers** — records die naar een gebruiker op de broninstallatie verwezen, worden getoond met een voorgestelde doelgebruiker (gematcht op e‑mail); bevestig, kies een andere, of laat ongekoppeld.
4. **Proefrun** — geeft een telling per tabel van wat er *zou* worden toegevoegd / bijgewerkt / overgeslagen. **Tijdens de proefrun wordt niets weggeschreven.**
5. **Bevestig** — typ `IMPORT` en pas toe. De schrijfactie draait in een databasetransactie en wordt volledig teruggedraaid als een rij faalt, zodat een gedeeltelijke import nooit blijft staan.

Hoe verwijzingen worden behandeld:

- **Bron-ids worden nooit behouden.** Elke geïmporteerde rij wordt als nieuw ingevoegd; de importer houdt een oud→nieuw id-overzicht per tabel bij en herschrijft verwijzingen (via de afhankelijkheidskaart) naar de nieuwe ids vóór elke schrijfactie — zo verwijst een geïmporteerde evaluatie naar de geïmporteerde speler.
- **`club_id`** wordt herschreven naar de huidige club; **`wp_user_id`** wordt ingesteld vanuit je gebruikerskoppeling (niet gekoppeld → ongekoppeld).
- **Verwijzingen naar Keuzelijsten & configuratie** (bijv. het type van een evaluatie, de categorie van een beoordeling) worden op stabiele sleutel gematcht met het overeenkomstige item dat al op deze installatie bestaat. Heeft het doel geen match, dan wordt de rij zonder die koppeling geïmporteerd en verschijnt een waarschuwing — stel eerst de configuratie in als je die koppelingen nodig hebt.

Uploads zijn begrensd op 25 MB. Het importeren van *waarden* van aangepaste velden wordt nog niet gedekt (de records zelf worden geïmporteerd, hun aangepaste waarden niet).

## Wat blijft uitgesteld

S3, Dropbox, GDrive en SFTP-bestemmingen zitten niet in v1; de bestemmingsinterface is al aanwezig, dus elk daarvan is een toevoeging van één klasse wanneer de tijd er rijp voor is (waarschijnlijk gebundeld met #0011 monetisatie als Pro-feature).
