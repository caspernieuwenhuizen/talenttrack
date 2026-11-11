---
title: Migraties & updates
group: configuration
summary: Wat er gebeurt wanneer je de plugin bijwerkt en hoe je migraties handmatig uitvoert.
audience: [admin, dev]
views: [migrations]
order: 60
---

# Migraties & updates

Als je TalentTrack bijwerkt via de WordPress-plug-in-updater, verandert de plug-in-code meteen — maar de database heeft soms vervolgwerk nodig: nieuwe kolommen, hernoemde tabellen, nieuwe rechten, seeddata. Dat vervolgwerk is een **migratie**.

## Vóór v3.0.0

Historisch moest je na elke update de plug-in deactiveren en opnieuw activeren om migraties te triggeren. Dit was makkelijk te vergeten en de symptomen van "migratie overgeslagen" waren verwarrend.

## v3.0.0 en later

Migraties zijn nu een eersteklas beheerderactie.

### Automatische detectie

TalentTrack slaat de huidig geïnstalleerde schema-versie op in een WordPress-optie (`tt_installed_version`). Bij elke pagina-laad in het beheer vergelijkt het die optie met de draaiende plug-in-versie.

Komen ze niet overeen — meestal omdat de WP-plug-in-updater net een nieuwe versie heeft gekopieerd — dan verschijnt er bovenaan elke beheerpagina een gele melding:

> **TalentTrack schema moet bijgewerkt worden.**
> Plug-in-versie `3.0.0` is geladen, maar het geïnstalleerde schema is `2.22.0`. Voer de migratie uit om de database bij te werken.
> **[Migraties nu uitvoeren]**

Klik op de knop. Migraties voltooien meestal binnen een seconde of twee. De banner verdwijnt.

### Handmatige trigger

Je kunt migraties ook handmatig uitvoeren vanaf de pagina **Plug-ins**. Naast de TalentTrack-rij:

`Migraties uitvoeren | Dashboard | Deactiveren | Bewerken`

"Migraties uitvoeren" triggert dezelfde routine. Handig als je vermoedt dat een eerdere run mislukt is of als je rechten opnieuw wilt toekennen.

## Wat migraties daadwerkelijk doen

- **Schemaherstel** — zorgen dat elke TalentTrack-tabel bestaat met de verwachte kolommen
- **Seed-data** — standaard evaluatiecategorieën, functionele rollen en keuzelijstwaarden invoegen als de tabellen leeg zijn
- **Toekennen van rechten** — zorgen dat de WordPress-beheerder elk `tt_*`-recht heeft, en dat elke TalentTrack-rol de rechten bezit die het zou moeten hebben
- **Zelfherstel** — bekende foutieve staten uit oude releases detecteren (ontbrekende kolommen, corrupte enum-waarden) en herstellen

Alle stappen zijn **idempotent** — migraties draaien terwijl er niets veranderd is, is een no-op.

## Als een migratie mislukt

Een migratie die een fout geeft (host-specifieke SQL-beperkingen, afwijkend schema, een slechte release) verschuilt zich niet langer achter een succesbanner:

- De plug-in-versie wordt **niet** als geïnstalleerd gemarkeerd — het schema blijft gemarkeerd als in afwachting totdat elke migratie voltooid is.
- Er verschijnt een **rode melding** op elke beheerpagina met per mislukte migratie de databasefout, plus een knop **Migraties nu opnieuw uitvoeren**.
- Automatische her-runs worden opgeschort zolang er een fout geregistreerd staat, zodat één slechte migratie niet bij elke pagina-laad opnieuw draait. Opnieuw proberen is altijd expliciet: de knop in de melding, of **Migraties uitvoeren** op de Plug-ins-pagina.
- De foutenlijst staat in de optie `tt_migration_failures` en wist zichzelf bij de eerste schone run.

Blijft de retry mislukken, dan is de fouttekst in de melding precies wat je host of ontwikkelaar nodig heeft — die benoemt het migratiebestand en de exacte SQL-fout.

## Migraties schrijven (standaarden, dev)

Drie regels, afgedwongen via review + CI:

1. **Elke statement loopt via `$this->exec( $sql )`** (op de `Migration`-basisklasse). De fallback van de runner leest alleen de *laatste* databasefout nadat `up()` klaar is — een mislukte statement gevolgd door een geslaagde zou onzichtbaar zijn en de migratie half-af als toegepast markeren. `exec()` gooit een exception bij precies de statement die brak, en dat is wat de rode beheermelding vervolgens toont.
2. **Kolommen toevoegen aan bestaande tabellen gaat via `MigrationHelpers::addColumnIfMissing()`**, nooit via `dbDelta`. dbDelta slaat ALTERs stilletjes over wanneer de live tabel afwijkt van het CREATE-statement — de foutklasse achter de v4.20.85-reparatie van de blueprint-kolommen. CI (`migration-lint.yml`) laat elke nieuwe migratie falen die een bestaande tabel aan dbDelta voert.
3. **`dbDelta` blijft prima voor écht nieuwe tabellen** — eerste aanmaak is het geval dat het goed afhandelt.
4. **Een index / UNIQUE-key toevoegen op een bestaande tabel wordt idempotent gemaakt door eerst `information_schema.STATISTICS` te controleren**, en daarna een afgeschermde `ALTER … ADD … KEY` via `Migration::exec` te draaien. Er is nog geen `addIndexIfMissing`-helper — migratie `0170` (`tt_players` één-account-één-speler UNIQUE, #1772) is de referentie. Wanneer de index een constraint afdwingt die bestaande rijen kunnen schenden (een UNIQUE over data met mogelijke duplicaten), dedupliceer dan **vóór** de `ADD`, in dezelfde migratie, zodat de `exec` niet op oude data kan falen. Wanneer deduplicatie rijen die ernaar verwijzen zou moeten omzetten (beoordelingen, koppelingen of iets anders dat de id's van de duplicaten vasthoudt), weiger dan: gooi vóór de `ADD` een fout die de duplicaten noemt, en verander niets, zodat een beheerder ze bewust kan samenvoegen. Migratie `0324` (`tt_eval_categories` sleutel per club, #4986) is de referentie.
5. **De CREATE van de installatieroutine is de vorm na de migraties.** `Activator::schemaQueries()` draait via dbDelta bij elke activering en elke "Migraties opnieuw proberen", over tabellen die de migraties al hebben gevormd. Een CREATE die achterloopt op een migratie zet dus de oude vorm terug (#5020: `tt_players.wp_user_id` ging terug naar `DEFAULT 0`).
   - Een migratie die een kolom, standaardwaarde, sleutel of tabelnaam wijzigt op een tabel die `schemaQueries()` aanmaakt, past die CREATE aan in dezelfde PR. `ActivatorMatchesMigrationsTest` faalt wanneer ze verschillen.
   - Een oudere migratie die op de eindvorm zou breken, krijgt een guard en wordt niet aangepast: `0027` hernoemt de resultaatkolom van evaluaties alleen zolang die nog zijn oude naam heeft, `0038` voegt `club_id` alleen toe als die ontbreekt, `0170` voegt zijn sleutel alleen toe als die ontbreekt.
   - `tt_attendance` is de enige tabel die alleen wordt aangemaakt als hij ontbreekt. Migratie `0020` indexeert de activiteitskolom onder de naam die hij had voordat `0027` hem hernoemde, dus een verse installatie heeft de oude vorm nodig. Een wijziging aan aanwezigheid gaat via een migratie, nooit via die CREATE.
   - Migratie `0329` herstelt een installatie waar een heractivering al een oude vorm had teruggezet: hij verwijdert uit `tt_attendance` de activiteitskolom onder zijn naam van vóór `0027`, met de bijbehorende index, als `activity_id` er ook staat, en maakt `player_id` weer nullable, zet een uitslag die nog in de resultaatkolom van evaluaties onder zijn naam van vóór `0027` staat over naar een lege `game_result` en verwijdert die kolom, en zet `tt_players.wp_user_id` terug op `DEFAULT NULL`, met "geen account" opgeslagen als NULL. Elke stap leest eerst de tabel zoals hij is, dus een installatie zonder afwijking blijft ongemoeid.

## Wat je nooit hoeft te doen

- Deactiveren + opnieuw activeren (oude workflow, niet langer nodig)
- Handmatig de database bewerken
- Je zorgen maken over "de verkeerde migratie uitvoeren" — het systeem weet zelf wat er toegepast moet worden
