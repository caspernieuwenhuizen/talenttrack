---
title: Berichten
group: configuration
summary: Hoe de berichten van de academie werken — sjablonen, kanalen, stiltetijden, afmeldingen en het verzendlogboek.
audience: [user, admin]
views: [messages, my-messages]
order: 55
---

# Berichten

Elk bericht dat TalentTrack naar een gezin, een speler of een staflid stuurt, gaat via één plek naar buiten. Daardoor is de vraag te beantwoorden waar het hele systeem om draait: *hebben de ouders dat afgelastingsbericht nu echt gekregen?*

Deze pagina legt uit wat er verstuurd wordt, wie het ontvangt, wat het kan tegenhouden, en waar je kijkt als er iets niet is aangekomen.

## Waar een bericht uit bestaat

Vier dingen bepalen of een bericht de deur uitgaat en wat er in staat.

**Een sjabloon.** Elk soort bericht heeft er één — een afgelaste training, een ontwikkelingsplan dat klaar is om te lezen, een uitnodiging om een account aan te maken. Het sjabloon bepaalt de tekst in het Nederlands en het Engels. Een handvol veelgebruikte sjablonen kun je per academie herschrijven zodat de toon past bij hoe je al met gezinnen praat; de rest ligt vast.

**Een ontvangersregel.** Je richt een bericht nooit rechtstreeks aan een kind. Je richt het op een *speler*, en de contactregels voor jeugd bepalen wie het daadwerkelijk krijgt: bij de jongste leeftijdsgroepen de ouders, bij de middengroepen allebei, en vanaf O12 de speler zelf. Die regel staat op één plek en elk bericht houdt zich eraan, zodat geen enkele losse functie het fout kan doen.

**Een kanaal.** E-mail, pushmelding, sms, WhatsApp-link of de inbox in de app. Elk sjabloon geeft aan welke kanalen erbij passen, en het eerste kanaal dat de ontvanger echt bereikt wordt gebruikt. Wie geen telefoonnummer heeft staan, krijgt e-mail; wie de app heeft, krijgt een push.

**Een berichtsoort.** Dit is waar een afmelding en de stiltetijdenregel op werken, en waarop het verzendlogboek is gegroepeerd.

## Wat een bericht kan tegenhouden

Vijf dingen, in deze volgorde. Elk daarvan wordt vastgelegd, dus bij een bericht dat niet is aangekomen staat altijd een reden.

| Reden | Wat er gebeurde |
| --- | --- |
| Sjabloon uitgezet | Iemand heeft dit soort bericht voor de hele academie uitgezet. |
| Afgemeld | De ontvanger heeft aangegeven dit soort bericht niet te willen. |
| Stiltetijden | Het is tussen 21:00 en 07:00 en dit bericht kan tot de ochtend wachten. |
| Verzendlimiet | Eén afzender heeft ongewoon veel berichten in een uur verstuurd. |
| Geen adres | Niemand op het dossier heeft een e-mailadres of telefoonnummer dat dit kanaal kan gebruiken. |

Twee uitzonderingen zijn bewust gemaakt. **Berichten over veiligheid en welzijn en accountherstelmail kun je niet uitzetten** — dat zijn geen voorkeuren. En **een afgelaste training negeert de stiltetijden**, want een training die vanavond niet doorgaat is morgen geen nieuws meer.

## Stiltetijden

Standaard gaat er tussen **21:00 en 07:00** niets uit dat niet urgent is. Een bericht dat in dat venster valt wordt vastgelegd als uitgesteld in plaats van verstuurd. Het venster is per academie in te stellen.

## Afmelden

Iedereen beheert zijn eigen voorkeuren via **Mijn instellingen**. De lijst is per berichtsoort, niet alles-of-niets: een ouder kan doelherinneringen dempen en toch bericht krijgen over een afgelaste training. Berichten over veiligheid en welzijn en accountherstel staan niet in de lijst, omdat ze niet optioneel zijn.

## Een soort bericht voor iedereen uitzetten

Er is per sjabloon een schakelaar voor de hele academie. Gebruik die als een soort bericht niet past bij hoe jullie werken — een academie die nooit doelaansporingen stuurt kan die uitzetten zonder de aanwezigheidssignalen kwijt te raken.

Een sjabloon uitzetten onderdrukt het bericht en **niet** het bewijs: het verzendlogboek legt nog steeds vast dat het bericht verstuurd zou zijn en dat de schakelaar het heeft tegengehouden. Dat is met opzet. "We hebben het uitgezet" en "het is stilletjes misgegaan" mogen er over een half jaar niet hetzelfde uitzien.

Er is nog een tweede, grovere schakelaar onder Modules: **Geplande berichten** zet de dagelijkse cron uit die doelaansporingen, aanwezigheidssignalen, onboarding-aansporingen en herinneringen voor stafontwikkeling verstuurt. Gebeurtenisgestuurde berichten — die afgaan op het moment dat er iets gebeurt — blijven daarbij ongemoeid.

## Het verzendlogboek

**Instellingen → Berichtenlogboek**, of vanaf het spelersdossier via **⋯ → Verstuurde berichten**.

Elke verzendpoging schrijft een regel, wat de uitkomst ook is. Die regel legt vast wie het stuurde, wie het kreeg, over welke speler het ging, welk sjabloon en kanaal, de onderwerpregel en de status.

Het scherm filtert op speler, soort bericht, uitkomst en datumbereik. Het spelersfilter biedt alleen spelers aan waar het logboek daadwerkelijk een bericht over heeft gedragen — een lijst met elke speler van de academie zou vooral bestaan uit keuzes die niets opleveren.

Uitkomsten staan er in gewone taal, niet als databasesleutel, en in drie tinten in plaats van twee: bezorgd, bewust tegengehouden, en een probleem. Een afmelding die het product netjes heeft gerespecteerd en een adres dat bounced zijn allebei "niet bezorgd" en vragen om een tegengestelde reactie, dus ze krijgen niet dezelfde kleur.

Als een geplande detectie blijft mislukken, staat er een waarschuwing boven de tabel met welke het is en wanneer die voor het laatst liep. Dat is de enige plek waar dat verschil zichtbaar wordt: een detectie zonder iets te versturen en een detectie die elke nacht crasht laten allebei geen regels achter.

**De inhoud van het bericht wordt nooit opgeslagen.** Het logboek bewaart er een vingerafdruk van, zodat de regel niet ongemerkt kan worden aangepast, en verder niets. Dat is een bewuste grens: het logboek kan je vertellen dát er een bericht over een kind is verstuurd, aan wie, en of het is aangekomen — en kan niet worden gebruikt om te lezen wat een trainer over dat kind heeft geschreven.

Regels blijven standaard **18 maanden** staan. Daarna maakt een dagelijkse taak het ontvangeradres en de onderwerpregel leeg, terwijl de regel zelf blijft — zo blijft het feit van het bericht bewaard als bewijs zonder de persoonlijke details eraan.

## De inbox in de app

**Mijn berichten**, onder Ik op je dashboard. De tegel toont het aantal ongelezen berichten.

Berichten die via het in-app-kanaal gaan, komen in de eigen inbox van de ontvanger terecht in plaats van in de mail. Ongelezen berichten zijn gemarkeerd, en **Markeer als gelezen** haalt die markering weg zonder de pagina opnieuw te laden.

Iedereen ziet alleen zijn eigen inbox. Een ouder ziet berichten over het eigen kind en nooit die van een ander gezin — dat wordt afgedwongen door de zoekopdracht zelf, niet door een rechtencontrole die te omzeilen zou zijn.

## Welke berichten er vandaag uitgaan

Berichten vallen in drie groepen.

**Gebeurtenisgestuurd** — ze gaan af op het moment dat er iets gebeurt. Een training wordt afgelast; een ontwikkelingsplan wordt ondertekend; een uitnodiging gaat de deur uit; een trainer stuurt een direct bericht; een scoutrapport wordt bezorgd; een herinnering voor proefinvoer gaat uit; een geplande rapportage wordt bezorgd.

**Gepland** — een dagelijkse taak zoekt naar een situatie en verstuurt: doelen waar het stil om is geworden, herhaalde afwezigheid, ouders die een maand niet hebben ingelogd, ontwikkelgesprekken van staf die eraan komen.

**Wel geregistreerd, nog niet aangesloten** — een klein aantal sjablonen wordt geleverd met de tekst klaar en nog zonder trigger erachter. Die versturen niets. Je ziet ze wel in de sjabloonlijst staan, en aan- of uitzetten verandert er niets aan totdat de functie die ze aanroept er is.

## Als iemand zegt dat hij het niet heeft gekregen

Werk de lijst af — begin op het spelersdossier, open **⋯ → Verstuurde berichten**, dan is er al op die speler gefilterd:

1. **Zoek het bericht in het verzendlogboek.** Staat er helemaal geen regel, dan is er niets geprobeerd — de trigger is niet afgegaan, en dat is een ander probleem dan een mislukte bezorging.
2. **Lees de status.** Afgemeld, uitgesteld, sjabloon uitgezet en geen-adres zeggen elk precies wat er is gebeurd, en elk vraagt om een andere oplossing.
3. **Controleer het adres op het dossier.** "Geen adres" betekent dat niemand op het spelersdossier — ouder of speler — een bruikbaar adres had voor dat kanaal.
4. **Controleer de sjabloonschakelaar** als de status zegt dat het sjabloon uit stond.

Het enige wat het logboek niet kan vertellen, is of een bezorgde e-mail ook gelezen is. Bij de mailprovider houdt het zicht van TalentTrack op.

## Voor ontwikkelaars

De REST-koppeling staat beschreven in `rest-api.md`: het verzendlogboek op `GET /comms/messages` en `GET /players/{id}/messages`, de inbox op `GET /comms/inbox` en `PATCH /comms/inbox/{id}`, de sjabloonschakelaar op `GET /comms/templates` en `PATCH /comms/templates/{key}`, en de eigen voorkeuren van de aanroeper op `GET|PUT /comms/preferences`.
