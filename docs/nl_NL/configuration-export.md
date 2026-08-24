---
title: Configuratie — Export
group: configuration
summary: Wat de bulkexports bevatten, en wie ze mag draaien.
audience: [admin]
order: 118
---

# Configuratie — Configuratie exporteren

**Dashboard → Configuratie → Configuratie exporteren** (`?config_sub=export`)

Download de configuratie van deze academie als één JSON-bestand: alle instellingen, plus welke modules en functies aan of uit staan. Alleen voor beheerders / clubbeheerders (`tt_edit_settings`).

Twee dingen waar je dit goed voor kunt gebruiken:

- **Weten wat deze installatie werkelijk heeft.** Kijk voordat je trainings- of introductiemateriaal schrijft welke schermen hier bestaan. Een module die uit staat neemt zijn schermen mee, en het heeft geen zin een scherm te beschrijven dat niemand kan bereiken.
- **Een tweede academie hetzelfde inrichten.** Het bestand is stabiele, geversioneerde JSON, bedoeld om tussen installaties te vergelijken en — uiteindelijk — te importeren. Er is nog geen importfunctie; zie [Beperkingen](#beperkingen).

## Wat er in het bestand staat

Configuratie staat niet op één plek, en de export is de enige plek die alles in samenhang leest.

| Onderdeel | Bron | Wat het bevat |
| --- | --- | --- |
| `settings` | `tt_config` | Alle academie-instellingen: huisstijl, kleuren, lettertypen, tegelweergave, beoordelingsschaal, datum en taal, wedstrijdminuten per leeftijdscategorie, persona-dashboardschakelaars, wizardschakelaars, en meer. |
| `options` | `wp_options` | Installatiebrede waarden: pluginversie, dashboardpagina, licentieniveau en -plan. |
| `modules` | `tt_module_state` | Elke module, aan of uit, met label, categorie en de schermen die hij bezit. |
| `features` | `tt_feature_state` | Elke deelfunctie binnen een module, aan of uit, met de schermen die hij afschermt. |

Elke module en functie draagt zijn **leesbare label** en zijn **`view_slugs`** — de `?tt_view=`-adressen die hij bezit. Dat maakt het bestand bruikbaar voor de vraag naar trainingsmateriaal: een module vertelt je niet alleen dát iets uit staat, maar welke schermen dan verdwijnen.

```json
{
  "class": "TT\\Modules\\Journey\\JourneyModule",
  "label": "Spelersreis",
  "enabled": true,
  "always_on": false,
  "under_development": false,
  "view_slugs": ["injuries", "my-journey", "player-journey"]
}
```

Een module die uit staat blijft in de lijst, gemarkeerd met `"enabled": false`. Hem weglaten zou het doel ondermijnen — "wat is hier niet beschikbaar" is de helft van de vraag.

Functies verschijnen alleen als hun bovenliggende module aan staat. Een functie onder een uitgeschakelde module is niet ter zake: de module heeft het scherm al weggenomen.

### Zo lees je het voor dekking

`enabled` zegt of een scherm op deze installatie bestaat. Het zegt niet of een *bepaalde gebruiker* er bij kan — dat hangt ook af van rechten en persona. Voor "welke schermen moet het trainingsmateriaal überhaupt behandelen" is `enabled` de juiste vraag. Voor "wat ziet een scout" raadpleeg je daarnaast de rechtenmatrix.

## Wat er niet in staat

- **Geen spelersgegevens.** In geen enkele vorm, in geen enkel onderdeel.
- **Geen inloggegevens.** In `tt_config` staan echte integratiegeheimen — het Strava-appgeheim, het Spond-wachtwoord en -token, de DeepL API-sleutel, het Google-serviceaccount. Hun waarden worden vervangen door `"[redacted]"` en de sleutelnamen komen samen onder `redacted_keys`.

De sleutelnaam blijft bewust staan, ook als de waarde is weggelaten: "Strava is op deze installatie ingesteld" is precies het soort informatie waarvoor de export bestaat, en dat is niet gevoelig. De waarde wel.

Weglaten gebeurt op patroon (elke sleutel met `secret`, `password`, `api_key`, `token`, `credential`, `private_key`, `service_account`, of eindigend op `_enc`) plus een korte expliciete lijst voor sleutels die geen patroon volgen. Een nieuwe integratie die de bestaande naamgeving aanhoudt, wordt vanaf dag één weggelaten.

## Herkomst

Elk bestand draagt `schema_version`, `exported_at`, `plugin_version` en `club_id`. `schema_version` gaat omhoog zodra de vorm van de inhoud verandert op een manier waar een lezer rekening mee moet houden — een lezer die een versie tegenkomt die hij niet kent, hoort hem te weigeren in plaats van te gokken.

De export wordt vastgelegd in het auditlogboek.

## Via de API

```
GET /wp-json/talenttrack/v1/exports/config_json?format=json
```

Dezelfde rechtencontrole, dezelfde inhoud, dezelfde auditregel. De download en de API leveren een identieke momentopname — het samenstellen gebeurt in `ConfigSnapshotService`, die beide aanroepen.

## Beperkingen

- **Alleen exporteren.** Er is nog geen importfunctie: je kunt een geëxporteerd bestand vanaf dit scherm niet op een andere installatie toepassen. Het scherm Back-ups bezit de bestaande datamigratie-import en is de waarschijnlijke plek voor een configuratie-import zodra die er komt.
- **Alleen instellingen en schakelaars.** Opzoeklijsten en woordenlijsten, definities van eigen velden, de rechtenmatrix, persona's, workflowsjablonen en methodieksets zitten er *niet* in. Dat is inhoud van de academie, geen beschikbaarheid. Gebruik voor een volledige gegevensmomentopname **Configuratie → Back-ups**.
- **Vereist de module Export.** De tegel is verborgen wanneer de module Export uit staat, omdat die module de downloadafhandeling bezit.

## Zie ook

- [Modules](modules.md) — modules en functies aan- en uitzetten.
- [Back-ups](backups.md) — volledige clubmomentopname, herstel en de `.ttmig`-migratieflow.
- [Exports](exports.md) — de bulk-gegevensexports (spelers, aanwezigheid, doelen).
