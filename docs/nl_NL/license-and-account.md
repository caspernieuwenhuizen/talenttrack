---
title: Licentie & account
group: configuration
summary: Tier, gebruikslimieten en hoe een pakket op een installatie wordt vastgelegd.
audience: [admin]
module: TT\Modules\License\LicenseModule
order: 100
---

# Licentie en account

TalentTrack kent drie tiers — **Free**, **Standard** en **Pro**. Op welke een installatie zit, wordt bepaald bij het inrichten ervan, niet in de plugin zelf: er is hier geen afrekenscherm, geen licentiesleutel om te plakken, en niets wat een clubbeheerder kan omzetten om meer te krijgen dan waar de club recht op heeft.

## Hoe een installatie haar tier kent

Je TalentTrack-operator legt het pakket vast bij het inrichten. De installatie bewaart daar een lokale kopie van, zodat alles gewoon blijft werken als de systemen van de operator even niet bereikbaar zijn — een pakket verdampt niet omdat er een middag een server plat lag.

Volgorde waarin de tier wordt bepaald, de eerste treffer wint:

1. **Ontwikkelaars-override** — alleen op installaties waar de eigenaar `TT_DEV_OVERRIDE_SECRET` heeft ingesteld. Zie onderaan.
2. **Het vastgelegde pakket.**
3. **Free** — als er geen pakket is vastgelegd, of het vastgelegde pakket zo lang niet is ververst dat het niet meer wordt vertrouwd.

Staat er op de Accountpagina dat er geen pakket is vastgelegd en klopt dat niet? Neem contact op met je operator. Aan hun kant is het één regel werk en aan je gegevens verandert niets.

## Van pakket wisselen

Vraag het je operator. Je installatie gaat ter plekke naar de nieuwe tier: dezelfde site, dezelfde URL, dezelfde gegevens, met meer ruimte en meer functies. Er wordt niets gemigreerd, geëxporteerd, opnieuw ingelezen of opgebouwd, en er is geen downtime.

Andersom werkt hetzelfde, met één ding om te weten: teruggaan naar een tier waarvan je de limieten al overschrijdt verwijdert niets. Bestaande teams en spelers blijven leesbaar; je kunt er alleen niets bij zetten tot je weer onder de limiet zit.

## Tiers

| Functie | Free | Standard | Pro |
| - | - | - | - |
| Basis spelers / teams / activiteiten / doelen / eenvoudige evaluaties | ✓ | ✓ | ✓ |
| Back-up naar lokaal + e-mail | ✓ | ✓ | ✓ |
| Maximaal 1 team en 25 spelers | ✓ | onbeperkt | onbeperkt |
| Radardiagrammen, spelersvergelijking, tariefkaarten (volledig) | — | ✓ | ✓ |
| CSV-bulkimport | — | ✓ | ✓ |
| Functionele rollen | — | ✓ | ✓ |
| Gedeeltelijk terugzetten van back-ups + 14 dagen ongedaan maken | — | ✓ | ✓ |
| Geplande rapportages | — | ✓ | ✓ |
| Meerdere academies / federatie | — | — | ✓ |
| Proefspelersmodule | — | — | ✓ |
| Scouttoegang | — | — | ✓ |
| Teamchemie + blauwdrukken | — | — | ✓ |
| Back-up naar S3 / Dropbox / GDrive | — | — | ✓ |

> **Deze tabel loopt achter op het product.** Ze beschrijft de indeling zoals die in v3.17.0 is getrokken. Het meeste wat TalentTrack sindsdien heeft gekregen — wedstrijdanalyse, de mediabibliotheek, trainingsplannen, signalen, cursussen, toernooien, het analyseplatform en meer — heeft geen tier en gedraagt zich daardoor als Free. Het opnieuw trekken van die indeling is bekend en staat gepland; behandel de tabel tot die tijd als historisch, niet als leidend.

## Free-tier-limieten

**1 team, 25 spelers, onbeperkt evaluaties.** Bij het bereiken van de team- of spelerlimiet verschijnt een upgrade-melding in plaats van opslaan. Limieten gelden alleen op Free; Standard en Pro kennen ze niet.

De limieten worden afgedwongen in de schermen, in de wizards én op de REST-API, dus ze zijn niet te omzeilen via de importroute of een directe API-aanroep.

## Accountpagina

Klik je op **TalentTrack** in de wp-admin-zijbalk, dan land je op de Accountpagina. Die heeft drie tabbladen:

| Tabblad | Recht | Wat je er vindt |
| - | - | - |
| **Account** | `tt_edit_settings` (alleen operators) | Huidige tier, gebruik versus limieten, wat de volgende tier toevoegt, phone-home-diagnostiek |
| **Pakket & beperkingen** | `read` (iedereen die is ingelogd) | Huidig pakket, limiettabel met waarschuwingen, en de volledige Free/Standard/Pro-matrix met jouw effectieve tier gemarkeerd |
| **MFA** | `read` (iedereen die is ingelogd) | Je eigen tweestapsverificatie en herstelcodes |

Het tabblad Pakket staat bewust open voor iedereen: een trainer die een functie niet kan vinden, moet zelf kunnen zien of die ontbreekt of alleen op slot zit.

## Niet-commerciële testinstallaties

`TT_COMMERCIAL_MODE` in `talenttrack.php` bepaalt of hier iets van wordt afgedwongen.

Staat de constante op `false` — de standaard, en het geval op elke ontwikkel- en demo-installatie — dan is het een **niet-commerciële testinstallatie**: alles is ontgrendeld, limieten gelden niet, en de Accountpagina toont één uitleg in plaats van de pakket-UI. Staat ze op `true`, dan geldt de volgorde hierboven.

## Ontwikkelaars-override (alleen eigenaar)

Voor demo's en lokaal testen zonder een echt pakket in te richten.

**Eenmalig instellen op je demo-/ontwikkelinstallatie:**

1. Genereer een bcrypt-hash van een wachtwoord dat je onthoudt. In een PHP-shell:
   ```php
   echo password_hash( 'jouw-wachtwoord-hier', PASSWORD_BCRYPT );
   ```
2. Voeg toe aan `wp-config.php`:
   ```php
   define( 'TT_DEV_OVERRIDE_SECRET', '$2y$10$....jouw-hash-hier....' );
   ```
3. Ga naar `wp-admin/admin.php?page=tt-dev-license` (geen menulink — typ de URL).
4. Voer je wachtwoord in, kies een tier, klik op Activeren.

De override wordt 24 uur bewaard als transient. In de bovenbalk van wp-admin verschijnt een "🔓 DEV: Pro"-label zodat je niet vergeet dat hij aanstaat. Ga opnieuw naar de URL om hem eerder te wissen.

**Klantinstallaties komen hier nooit langs** — zonder de constante geeft de beheerpagina een 404 en negeert de gate de override volledig.
