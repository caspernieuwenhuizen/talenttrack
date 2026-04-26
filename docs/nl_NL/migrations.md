<!-- audience: admin, dev -->

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
- **Seed-data** — standaard evaluatiecategorieën, functionele rollen en lookup-waarden invoegen als de tabellen leeg zijn
- **Toekennen van rechten** — zorgen dat de WordPress-beheerder elk `tt_*`-recht heeft, en dat elke TalentTrack-rol de rechten bezit die het zou moeten hebben
- **Zelfherstel** — bekende foutieve staten uit oude releases detecteren (ontbrekende kolommen, corrupte enum-waarden) en herstellen

Alle stappen zijn **idempotent** — migraties draaien terwijl er niets veranderd is, is een no-op.

## Wat je nooit hoeft te doen

- Deactiveren + opnieuw activeren (oude workflow, niet langer nodig)
- Handmatig de database bewerken
- Je zorgen maken over "de verkeerde migratie uitvoeren" — het systeem weet zelf wat er toegepast moet worden
