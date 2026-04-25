# Back-ups

TalentTrack heeft een eigen back-upmodule (los van een eventuele algemene WordPress-back-upplugin die je ook gebruikt). Snapshots dekken alleen de eigen `tt_*`-tabellen van de plugin — geen WordPress-gebruikers, geen media-uploads. Het doel is om je academiegegevens snel terug te kunnen zetten zonder de rest van de site mee te slepen.

## Waar je hem vindt

`Configuratie → Back-ups`. Zichtbaar voor beheerders en de rol **Head of Development**; de onderliggende capability is `tt_manage_backups`.

## Instellingen

- **Preset** — *Minimaal* (basisgegevens), *Standaard* (dagelijkse gegevens inclusief sessies/doelen/personen), *Uitgebreid* (alles inclusief auditlog + opzoeklijsten), of *Aangepast* (lijst per tabel).
- **Schema** — dagelijks, wekelijks of op aanvraag (geen automatische runs).
- **Bewaartermijn** — hoeveel lokale back-ups bewaard worden voor de oudste verwijderd wordt. Standaard 30.
- **Lokale bestemming** — schrijft `.json.gz`-bestanden naar `wp-content/uploads/talenttrack-backups/`. De map wordt automatisch aangemaakt met een `index.php` + `.htaccess` die directe browsertoegang blokkeert.
- **E-mailbestemming** — verstuurt iedere back-up via wp_mail() naar een door komma's gescheiden ontvangerslijst. Bestanden groter dan 10 MB zijn voor de meeste mailservers te groot; in dat geval is de e-mail alleen een melding en wordt de back-up uitsluitend lokaal opgeslagen.

## Nu een back-up maken

De knop "Nu back-up maken" op de instellingenpagina draait een back-up zonder op het schema te wachten. Handig voor:
- Je instellingen end-to-end testen.
- Net vóór een risicovolle handeling (CSV-import, bulk-archief).
- Sites waar WP-cron onbetrouwbaar is (weinig verkeer, agressieve caching).

## Terugzetten

1. Kies een back-up uit de lokale lijst en klik **Terugzetten**.
2. De pagina toont een overzicht per tabel van wat vervangen wordt plus de pluginversie van de snapshot.
3. Typ **RESTORE** in het bevestigingsveld.
4. De actie maakt elke tabel in de snapshot leeg en speelt de rijen opnieuw in. Tabellen die op de site bestaan maar niet in de snapshot staan, worden niet aangeraakt.
5. Als rij-aantallen na het terugzetten niet overeenkomen met de verwachting verschijnt er een foutmelding.

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

## Wat zit er niet in v1

Sprint 2 voegt toe:
- **Gedeeltelijk terugzetten met diff-weergave** — selecteer specifieke records, bekijk groen/geel/rood-diff tegen de huidige status, inclusief afhankelijkheidssluiting.
- **Automatische pre-bulk-back-up** — automatische veiligheidsmomentopname voor elke operatie die meer dan 10 rijen verwijdert/archiveert.
- **Ongedaan-makenkortlink** — de admin-melding na een bulkoperatie bevat een 1-klik "Ongedaan maken via back-up"-link die 14 dagen geldig is.

S3, Dropbox, GDrive en SFTP-bestemmingen zitten niet in deze release; de bestemmingsinterface is al aanwezig, dus zij zijn een toevoeging van één klasse per stuk wanneer de tijd er rijp voor is.
