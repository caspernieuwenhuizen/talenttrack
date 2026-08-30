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

## Hoe een functie eruitziet die niet in je pakket zit

Drie antwoorden, alle drie bewust gekozen. Samen zorgen ze ervoor dat een pakketwijziging nooit voelt alsof er iets stukgaat.

### Op slot, niet verborgen

Een functie die niet in je pakket zit, blijft staan waar hij hoort. Open je hem, dan krijg je een paneel dat de functie noemt, het pakket noemt waar hij bij hoort, en doorlinkt naar de accountpagina — geen verdwenen menu-item en geen foutmelding.

Verbergen zou netter ogen en slechter zijn. Een trainer die wedstrijdanalyse niet kan vinden, kan niet zien of de club het nooit had, of het uitgezet is, of dat er iets kapot is. Zichtbaar-op-slot beantwoordt die vraag meteen, en het betekent dat de functiematrix op het tabblad Pakket overeenkomt met wat mensen daadwerkelijk zien.

### Wat al is vastgelegd, blijft leesbaar

Overstappen naar een pakket zonder een bepaalde functie neemt niet weg wat die functie heeft opgeleverd. Oude wedstrijdanalyses, oude trainingsplannen, oude media blijven leesbaar en exporteerbaar precies zoals ze waren. Wat stopt, is het schrijven van nieuwe — een opslagactie, een upload, een aanmaakknop.

Een club die van Pro naar Standard gaat, verliest dus mogelijkheden, nooit historie. Er wordt niets verwijderd, verborgen of achtergehouden, en er hoeft niets teruggezet te worden als de club later weer terugstapt.

### "Zit niet in je pakket" en "mag je niet" zijn verschillende antwoorden

Er bestaan twee soorten weigeringen en ze delen nooit dezelfde zin:

| Wat je ziet | Wat het betekent | Wat het oplost |
| - | - | - |
| Een paneel op slot met een pakketnaam | De installatie zit niet op dat pakket | Vraag je operator naar het pakket |
| Een rechtenmelding | Jouw rol heeft dit niet, op geen enkel pakket | Vraag een academiebeheerder naar je rechten |

Een koppeling die de API leest, ziet dezelfde scheiding: een pakketweigering komt terug als HTTP **402 Payment Required**, een rechtenweigering als **403 Forbidden**. Gaat er iets mis en kun je niet zien welke van de twee het was, dan is dat een bug die het melden waard is.

### Hoe dat er per functie uitziet

De wedstrijd- en trainingsfuncties passen de drie antwoorden hierboven toe.
Wat een Standard-club wel en niet kan:

| Functie | Standard kan wel | Standard kan niet |
| - | - | - |
| **Wedstrijdanalyse** | Elke geschreven analyse lezen en exporteren | Er een schrijven of wijzigen |
| **Wedstrijdvoorbereiding** | Een bestaand plan lezen en afdrukken | Een nieuw plan starten of een bestaand plan wijzigen |
| **Live wedstrijd** | Uitslag, speelminuten en gebeurtenissen van gespeelde wedstrijden lezen | Het live scherm openen om er nog een te draaien |
| **Toernooien** | Elk toernooi, de wedstrijden, selecties en totalen bekijken | Er een aanmaken, wijzigen of plannen |
| **Automatisch balanceren** | Een toernooischema met de hand plannen | Het schema automatisch laten vullen |
| **Trainingsplannen** | Elk plan dat de club maakte lezen, met de historie | Een nieuw plan bouwen of er een draaien |
| **Oefeningenbibliotheek** | Alle oefeningen doorzoeken en bekijken | Oefeningen toevoegen, wijzigen of importeren |
| **Media** | Elke foto en video van de club zien, afspelen, downloaden — en **verwijderen** | Iets nieuws uploaden, of een bestaand item aan nog een record koppelen |

De regel over media is de vorm van het geheel: *de club houdt elke foto die
hij heeft, en kan er geen bij doen.* Verwijderen wordt nooit geweigerd om
een abonnement — de foto van een kind weghalen is een plicht, geen functie.

### Kanalen en koppelingen

Deze kosten geld bij elk gebruik, dus ze zijn geprijsd en ze weigeren precies
daar waar ze anders zouden uitgeven.

| Functie | Standard kan wel | Standard kan niet |
| - | - | - |
| **Sms** | Versturen via e-mail, in-app en WhatsApp-links | Sms als kanaal gebruiken — het staat niet eens in de kanaalkeuze |
| **Pushmeldingen** | Dezelfde meldingen per e-mail ontvangen | Ze als telefoon-push laten bezorgen |
| **Geplande verzendingen** | Elk bericht dat door een gebeurtenis komt — uitnodigingen, accountmail, alles wat een klik veroorzaakt | De vier dagelijkse herinneringen (doel, aanwezigheid, inactieve ouder, stafgesprek) |
| **Foto naar plan** | Een trainingsplan met de hand bouwen, zoals altijd | Een gefotografeerd plan laten uitlezen |
| **Spond** | Elke al geïmporteerde wedstrijd lezen — het zijn gewone activiteiten | Opnieuw synchroniseren |
| **Strava** | Elke al gedeelde activiteit lezen op het spelersdossier | Nog een speler koppelen |
| **Back-up naar objectopslag** | Back-ups lokaal en per e-mail | Een S3-achtige bestemming *(nog niet gebouwd)* |

Twee hiervan draaien op de achtergrond, waar niemand meekijkt, dus een
weigering wordt **opgeschreven** in plaats van getoond: de geweigerde
geplande verzending staat per herinnering in het gezondheidsoverzicht van het
berichtenlog, en een geweigerde Spond-synchronisatie staat in de synchronisatie-
historie van dat team. Beide noemen het pakket, zodat het leest als een vraag
over het abonnement en niet als iets dat stuk is.

Er wordt niets aangeraakt van wat al geïmporteerd is. Spond-wedstrijden en
Strava-activiteiten blijven precies staan, leesbaar en exporteerbaar, en doen
het weer zodra het abonnement verandert.

### Schermen die je wel ziet en niet opent

Zeven schermen tonen zich vergrendeld. Dat is de keuze zoals bedoeld: de
tegel blijft staan, en wie hem opent krijgt uitleg.

| Functie | Standard kan wel | Standard kan niet |
| - | - | - |
| **Analyse-verkenner** | Elk standaardrapport en elk dashboardcijfer — die lezen de engine rechtstreeks | Er een eigen vraag aan stellen |
| **Eigen widgets** | De widgets die de club al bouwde zien, op de dashboards waar ze staan | Er een bouwen of wijzigen |
| **Dashboardindelingen** | De opgeslagen indelingen gebruiken | Ze wijzigen |
| **Cursussen** | Zien welke cursussen er zijn en waar ze over gaan | Er een openen of afronden |
| **Aanwezigheidsraster** | **Aanwezigheid vastleggen**, per activiteit | Een hele week in één scherm invullen |
| **Speelminutenraster** | **Speelminuten vastleggen** per activiteit; een live wedstrijd schrijft ze zelf | Een hele selectie in één scherm corrigeren |
| **Beoordelingsraster** | **Een speler beoordelen** vanaf het spelersdossier en vanaf de activiteit | Een hele selectie in één scherm beoordelen |

De drie rasters zijn het waard om twee keer te lezen. Ze zijn de snelle
manier op een laptop om een hele selectie in te voeren — **niet de enige
manier om het vast te leggen**. Aanwezigheid, speelminuten en beoordelingen
zijn allemaal Standard-functies en blijven precies zoals ze waren; wat het
abonnement toevoegt is er twintig tegelijk doen. Elk vergrendeld raster zegt
dat er ook bij, want "aanwezigheid is een betaalde functie" zou onwaar zijn.

Cursussen worden vergrendeld bij de eigen toegangspoort van de module en niet
bij een scherm, zodat de cursuslijst, een lespagina en de API hetzelfde
antwoord geven — en de cursussen blijven **in de lijst staan**, zodat een club
ziet wat het abonnement zou openen.

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
