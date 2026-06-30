<!-- audience: admin -->

# Databrowser

De Databrowser is een **alleen-lezen** venster op de ruwe gegevens achter TalentTrack. Een beheerder kan er de inhoud van de databasetabellen doorbladeren met begrijpelijke kolomnamen, zien hoe tabellen met elkaar verbonden zijn, en de werkelijk opgeslagen waarden inzien — zonder wp-admin of SQL.

Het is hulpmiddel voor transparantie en data-audit. Er worden nooit gegevens of definities gewijzigd, en de toegang is strak afgebakend tot twee rechten, zodat het nooit verbreedt wie spelersgegevens kan zien.

## Wie het mag gebruiken

De Databrowser-tegel verschijnt onder **Beheer** voor gebruikers met het specifieke recht `tt_view_data_browser`. Standaard zijn dat:

- **Administrator** (de matrix-admin / supergebruiker), en
- **Club Admin** (de academie-admin).

Geen enkele andere rol — trainers, Hoofd Ontwikkeling, scouts, ouders — ziet de tegel of de gegevens. Het recht maakt bewust géén deel uit van de `tt_view_settings`-paraplu, dus algemene toegang tot instellingen geeft geen toegang tot de Databrowser.

## Wat het toont

### Tabeloverzicht (`?tt_view=data-browser`)

Een doorzoekbare lijst van alle `tt_*`-tabellen, verdeeld in twee groepen:

- **Kern-tabellen** — de speler-gerichte tabellen (spelers, teams, activiteiten, beoordelingen, doelen, aanwezigheid, …) met handgeschreven labels en omschrijvingen.
- **Overige tabellen** — de rest, met labels die automatisch uit de tabelnaam zijn afgeleid.

Elke rij toont het label, de echte tabelnaam, een korte omschrijving en een benadering van het aantal rijen. Tabellen met gevoelige gegevens dragen een **Gevoelig**-badge.

Het zoekvak matcht de naam, het label en de omschrijving van een tabel **én de kolomnamen**. Typ je een kolomfragment als `minutes`, `club_id` of `uuid`, dan worden alle tabellen met een overeenkomende kolom getoond; verschijnt een tabel vanwege een kolom, dan toont de rij een **overeenkomende kolom**-hint met de naam ervan, zodat het resultaat bruikbaar is.

### Tabelweergave (`?tt_view=data-browser&table=tt_…`)

- **Semantische kolomkoppen** — elke kolom toont een label, de echte `kolom · type`, en (voor beschreven kolommen) een korte uitleg bij het `?`-teken.
- **Ruwe rijen** — de waarden precies zoals opgeslagen, met paginering. Een zoekveld filtert rijen over de tekstkolommen.
- **Verbonden tabellen** — een rij chips die toont met welke tabellen deze verbonden is (uitgaand, bijv. `team_id → Teams`) en welke terugverwijzen (inkomend).
- **Klikbare verwijssleutels** — een waarde zoals een `team_id` van `3` linkt rechtstreeks naar die rij in de Teams-tabel.

## Gevoelige tabellen

Tabellen met medische, veiligheids- of gezinsgegevens van minderjarigen (zoals blessures, ouder-/verzorgerkoppelingen, spelersnotities) zijn gemarkeerd. Het openen ervan toont een waarschuwing en schrijft een `data_browser.view`-regel naar het audit-log met wie keek en welke tabel. De gegevens worden nog steeds getoond — de markering gaat over verantwoording, niet over beperking.

## Tenancy

Elke gelezen rij wordt afgebakend tot de actieve club wanneer de tabel een `club_id`-kolom heeft, zodat op een toekomstige multi-tenant-installatie de ene academie nooit de rijen van een andere kan zien.

## REST-API

Dezelfde gegevens zijn alleen-lezen beschikbaar via de REST-API (het canonieke contract; de weergave is er één consument van):

| Endpoint | Geeft |
|---|---|
| `GET /talenttrack/v1/data-browser/tables` | Alle doorzoekbare tabellen met labels, omschrijvingen, gevoeligheid, rij-aantallen. |
| `GET /talenttrack/v1/data-browser/tables/{table}/schema` | Kolommen (met labels) + relaties van één tabel. |
| `GET /talenttrack/v1/data-browser/tables/{table}/rows?page=&per_page=&q=&pk=` | Een pagina ruwe rijen. |

Elk endpoint vereist `tt_view_data_browser` en valideert de tabelnaam tegen het live schema voordat een query draait.

## Beperkingen (v1)

- Alleen-lezen — er is geen bewerking van gegevens of schema.
- Relaties worden afgeleid uit `*_id`-kolomnamen (dit schema heeft geen SQL-foreign-keys), dus een ongebruikelijk benoemde koppeling wordt mogelijk niet herkend.
- De begrijpelijke laag dekt de kern-tabellen volledig; overige tabellen vallen terug op vermenselijkte kolomnamen. Een tabel aan de beschreven set toevoegen is een wijziging van één blok in `SemanticRegistry` — zonder migratie.
