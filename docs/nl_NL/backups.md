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

## Wat blijft uitgesteld

S3, Dropbox, GDrive en SFTP-bestemmingen zitten niet in v1; de bestemmingsinterface is al aanwezig, dus elk daarvan is een toevoeging van één klasse wanneer de tijd er rijp voor is (waarschijnlijk gebundeld met #0011 monetisatie als Pro-feature).
