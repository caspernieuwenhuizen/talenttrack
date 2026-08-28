---
title: Licentie & account
group: configuration
summary: Tier, gebruikslimieten en hoe een pakket op een installatie wordt vastgelegd.
audience: [admin]
module: TT\Modules\License\LicenseModule
order: 100
---

# Licentie en account

TalentTrack kent twee pakketten — **Standard** en **Pro** — plus de staat **Niet geactiveerd** voor een installatie waarvan het pakket niet is vastgelegd of is verlopen. Op welk pakket een installatie zit, wordt bepaald bij het inrichten ervan, niet in de plugin zelf: er is hier geen afrekenscherm, geen licentiesleutel om te plakken, en niets wat een clubbeheerder kan omzetten om meer te krijgen dan waar de club recht op heeft.

## Hoe een installatie haar tier kent

Je TalentTrack-operator legt het pakket vast bij het inrichten. De installatie bewaart daar een lokale kopie van, zodat alles gewoon blijft werken als de systemen van de operator even niet bereikbaar zijn — een pakket verdampt niet omdat er een middag een server plat lag.

Volgorde waarin de tier wordt bepaald, de eerste treffer wint:

1. **Ontwikkelaars-override** — alleen op installaties waar de eigenaar `TT_DEV_OVERRIDE_SECRET` heeft ingesteld. Zie onderaan.
2. **Het vastgelegde pakket.**
3. **Niet geactiveerd** — als er geen pakket is vastgelegd, of het vastgelegde pakket zo lang niet is ververst dat het niet meer wordt vertrouwd.

Staat er op de Accountpagina dat er geen pakket is vastgelegd en klopt dat niet? Neem contact op met je operator. Aan hun kant is het één regel werk en aan je gegevens verandert niets.

## Van pakket wisselen

Vraag het je operator. Je installatie gaat ter plekke naar de nieuwe tier: dezelfde site, dezelfde URL, dezelfde gegevens, met meer ruimte en meer functies. Er wordt niets gemigreerd, geëxporteerd, opnieuw ingelezen of opgebouwd, en er is geen downtime.

Andersom werkt hetzelfde, met één ding om te weten: teruggaan naar een tier waarvan je de limieten al overschrijdt verwijdert niets. Bestaande teams en spelers blijven leesbaar; je kunt er alleen niets bij zetten tot je weer onder de limiet zit.

## Twee pakketten

**Standard** is het academieproduct. **Pro** voegt wedstrijddag, training, media, het analyseplatform en de koppelingen toe.

Er is geen Free-pakket. TalentTrack draait gehost — je club heeft een subdomein dat de beheerder draait — dus een installatie bestaat omdat iemand ervoor betaalt. Wat je op de accountpagina nog wél tegenkomt is *Niet geactiveerd*: de staat van een installatie vóórdat het pakket is vastgelegd, of nadat het is verlopen. Het is niets wat aan iemand wordt verkocht.

### Standard — de academie draaien

Spelers, teams, staf. Evaluaties met de volledige categorieboom, wegingen en beoordelingsvensters. Ontwikkelplannen en de gesprekscyclus. Doelen. De spelersreis en cohortovergangen. Het stoplicht en gedragsscores. Metingen en testen. Proefspelers, prospects en scouttoegang. Aanwezigheid en speelminuten. De standaardrapportages, radar, spelersvergelijking en tariefkaarten. Methodiek, de planner, vakanties, seizoensovergang. Excel- en CSV-import, back-ups, vertalingen, eigen velden en clubhuisstijl.

### Pro — alles wat 2026 heeft toegevoegd

| | |
| - | - |
| **Wedstrijddag** | Wedstrijdanalyse en de deellink, wedstrijdvoorbereiding, het live-wedstrijdscherm, toernooien en de drie wedstrijd-pdf-exports |
| **Training** | Trainingsplannen, de oefeningenbibliotheek, trainingsblootstelling per speler, foto-extractie |
| **Media** | De mediabibliotheek — foto en video op het spelersdossier |
| **Analyse** | De dimensieverkenner, geplande rapportages, eigen widgets, de persona-dashboardeditor |
| **Mensen bereiken** | Geplande verzendingen, het sms-kanaal, pushmeldingen |
| **Koppelingen** | Spond, Strava |
| **Trainersontwikkeling** | Cursussen |
| **Selectie samenstellen** | Teamchemie en het delen van blauwdrukken |
| **Bulkinvoer** | De aanwezigheids-, minuten- en beoordelingsrasters |
| **Back-up** | Bestemmingen in objectopslag |

### Wat nooit een betaalde functie is

Het auditlogboek, de rechtenmatrix, tweefactorauthenticatie, records verwijderen, de prullenbak, het inlogovername-logboek, mediatoestemming en inzageverzoeken zijn beschikbaar op **elk** pakket, ook op een installatie die niet geactiveerd is.

Zo voldoet een academie aan haar verplichtingen tegenover de kinderen die erin zitten. De veiligheid van kindgegevens verkopen als losse module doet dit product niet.

Om diezelfde reden houdt een club met een verlopen pakket het dashboard, spelerskaarten, lokale back-up en export. Je eigen gegevens kun je altijd inzien en meenemen.

## Gebruikslimieten

Aantal spelers, aantal teams en opslag worden **beprijsd naar wat ze kosten om te draaien**, niet meegebakken in het pakket. Een grote Standard-club kan meer kosten dan een kleine Pro-club, en dat is bewust: het pakket zegt welke functies je hebt, de omvang van je academie zegt wat het hosten kost.

De limieten in `FreeTierCaps` (1 team, 25 spelers) zijn nu demomeubilair: ze voorkomen dat het publieke demo-subdomein als gratis academie wordt gebruikt. Ze staan ingesteld op de demo-installatie en nergens anders.

## Accountpagina

Klik je op **TalentTrack** in de wp-admin-zijbalk, dan land je op de Accountpagina. Die heeft drie tabbladen:

| Tabblad | Recht | Wat je er vindt |
| - | - | - |
| **Account** | `tt_edit_settings` (alleen operators) | Huidige tier, gebruik versus limieten, wat de volgende tier toevoegt, phone-home-diagnostiek |
| **Pakket & beperkingen** | `read` (iedereen die is ingelogd) | Huidig pakket, limiettabel met waarschuwingen, en de volledige Standard/Pro-matrix met jouw effectieve tier gemarkeerd |
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
