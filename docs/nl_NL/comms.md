---
title: Berichten
group: configuration
summary: Hoe de berichten van de academie werken — sjablonen, kanalen, stiltetijden, afmeldingen en het verzendlogboek.
audience: [user, admin]
views: [messages, my-messages, safeguarding-broadcast]
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

Sommige uitzonderingen zijn bewust gemaakt. **Berichten over veiligheid en welzijn, accountherstelmail en afgelastingen kun je niet uitzetten** — dat zijn geen voorkeuren. Een afgelasting **negeert ook de stiltetijden**, want een training die vanavond niet doorgaat is morgen geen nieuws meer; die twee regels horen bij elkaar, en een tijdlang gold alleen de tweede.

## Stiltetijden

Standaard gaat er tussen **21:00 en 07:00** niets uit dat niet urgent is. Het venster is per academie in te stellen, en het geldt in de tijdzone van de site (**Instellingen → Algemeen → Tijdzone** in WordPress). Zet die dus op de plek waar de academie zit.

Een bericht dat in het venster valt wordt **vastgehouden, niet weggegooid**. In het verzendlogboek staat het als *Wacht tot de ochtend*. In het eerste uur na afloop van het venster wordt het verstuurd, en dezelfde regel krijgt dan de uiteindelijke uitkomst en telt als tweede poging. Alles wat een bericht kan tegenhouden wordt op dat moment opnieuw gecontroleerd: een gezin dat zich 's nachts heeft afgemeld, of een soort bericht dat de academie heeft uitgezet, krijgt niets.

Is een vastgehouden bericht na **24 uur** nog niet verstuurd, dan gaat het helemaal niet meer uit. De regel verandert dan in mislukt, met *Niet verstuurd na de stiltetijden*. Dat gebeurt alleen als de achtergrondtaak die elk uur draait is gestopt, en de regel staat er zodat je dat merkt.

**Geplande rapporten negeren de stiltetijden.** Ze gaan naar de staf, op het tijdstip dat het schema aangeeft, omdat een rapport een bestand meestuurt en een vastgehouden bericht 's nachts nooit een bestand bewaart. Om dezelfde reden wordt elk ander bericht met een bijlage dat in het venster valt niet vastgehouden: het wordt meteen als mislukt vastgelegd, zodat je het 's ochtends opnieuw kunt versturen.

## Afmelden

Iedereen beheert zijn eigen voorkeuren via **Mijn instellingen**. De lijst is per berichtsoort, niet alles-of-niets: een ouder kan doelherinneringen dempen en toch bericht krijgen over een afgelaste training.

Drie soorten staan niet in de lijst, omdat ze niet optioneel zijn: berichten over veiligheid en welzijn, accountherstelmail en afgelastingen. Ze staan wél op het scherm, aangevinkt en grijs, met de reden erbij — een voorkeurenpagina die stilzwijgend weglaat wat je niet kunt weigeren, vertelt je minder dan een die het benoemt.

**De lijst die je ziet, is de post die je ook echt kunt ontvangen.** Bij elke berichtsoort staat voor wie hij bedoeld is — de speler, het gezin of de staf van de academie — en het scherm toont alleen de soorten die jou bereiken. Aan een speler wordt niet gevraagd of hij herinneringen wil over de ontwikkelingsgesprekken van een trainer, en aan een trainer niet of hij uitnodigingen voor ouderavonden wil.

Wie allebei is — een trainer met een eigen kind op de academie — houdt elke regel die een van beide ontvangt. Het filteren kost dus nooit iemand een schakelaar.

Staat bij een berichtsoort nog niet voor wie hij bedoeld is, dan tonen we hem aan iedereen in plaats van hem te verbergen. Dat is een bewuste keuze: een overbodige regel op je scherm is rommelig, maar een ontbrekende regel betekent post die binnenkomt zonder dat je die kunt weigeren.

Je voorkeuren opslaan verandert alleen de regels die je voor je ziet. Wat je niet kunt zien, wordt niet aangepast als je op Opslaan drukt — een voorkeur die je instelde toen je een andere rol had, staat er dus nog steeds als je die rol opnieuw krijgt.

## Een soort bericht voor iedereen uitzetten

Er is per sjabloon een schakelaar voor de hele academie. Gebruik die als een soort bericht niet past bij hoe jullie werken — een academie die nooit doelaansporingen stuurt kan die uitzetten zonder de aanwezigheidssignalen kwijt te raken.

Een sjabloon uitzetten onderdrukt het bericht en **niet** het bewijs: het verzendlogboek legt nog steeds vast dat het bericht verstuurd zou zijn en dat de schakelaar het heeft tegengehouden. Dat is met opzet. "We hebben het uitgezet" en "het is stilletjes misgegaan" mogen er over een half jaar niet hetzelfde uitzien.

Er is nog een tweede, grovere schakelaar onder Modules: **Geplande berichten** zet de dagelijkse cron uit die doelaansporingen, aanwezigheidssignalen, onboarding-aansporingen en herinneringen voor stafontwikkeling verstuurt. Gebeurtenisgestuurde berichten — die afgaan op het moment dat er iets gebeurt — blijven daarbij ongemoeid.

## Een veiligheidsbericht versturen

**Instellingen → Veiligheidsbericht.**

Dit is het ene bericht van een academie dat niemand kan weigeren. Het negeert elke berichtvoorkeur van de ontvangers en het negeert de stiltetijden — verstuur je het om 23:00, dan komt het om 23:00 aan. *Mijn instellingen* vertelt gezinnen dat al sinds het begin; tot nu toe kon er alleen niets verstuurd worden.

**Wie het mag versturen.** Alleen de academiebeheerder en de WordPress-administrator. Geen coach, en ook niet het hoofd ontwikkeling — een coach kan al één ouder mailen, en alle gezinnen onweigerbaar aanschrijven is niet dezelfde handeling. Een academie waarvan de aandachtsfunctionaris veiligheid iemand anders is, geeft die persoon dat recht bewust. Zie [Toegangsbeheer](access-control.md) voor de capability en hoe je die verleent.

**Wie het bereikt.** Óf elk gezin in de academie, óf de gezinnen van één team. Je moet kiezen; er is geen standaard, want de standaard zou "iedereen" zijn. Een zorg over één ploeg stuur je beter naar die ploeg — blijkt het breder te liggen, stuur dan een tweede bericht. Dat kost minder dan meteen elk gezin bereikt te hebben.

Elke ouder krijgt één exemplaar, ook met twee kinderen op de academie, en gezinnen van wie het kind is vertrokken staan niet op de lijst.

**De bevestigingsstap.** Voordat er iets wordt verstuurd zie je precies hoeveel mensen het bereikt, wat de doelgroep in woorden is, en drie dingen over het bericht: ontvangers kunnen het niet weigeren, de stiltetijden houden het niet tegen, en het is niet terug te halen. Dat aantal bevestig je expliciet. De tekst kun je op dat moment nog aanpassen; *wie het bereikt* wijzigen betekent teruggaan en opnieuw kiezen, want het aantal waarmee je akkoord ging hoorde bij de oude doelgroep.

**Daarna.** Het verschijnt in het verzendlogboek als elk ander bericht, één regel per ontvanger. Er is geen tweede verzendpad en geen apart logboek.

## Een aankondiging versturen

**Aankondiging**, op je dashboard.

Voor het gewone nieuws dat de gezinnen van een team moeten weten: het veld is dicht, neem zaterdag een wit shirt mee, dinsdag staat er een andere trainer. Tot dit er was ging dat via WhatsApp — dus buiten het dossier van de academie, buiten de stiltetijden en buiten de voorkeuren die gezinnen hadden ingesteld.

**Wie er een mag versturen, en hoe ver.** Twee rechten, want dit zijn twee verschillende handelingen.

- Een **hoofdtrainer of teammanager** kondigt iets aan bij de teams waaraan hij is gekoppeld. Verder niets — geen ander team, geen leeftijdscategorie, niet de academie. Iemand met het recht maar zonder teamkoppeling bereikt niemand; daarom kan het recht breed worden verleend zonder risico.
- Het **hoofd ontwikkeling en de academiebeheerder** kondigen iets aan bij elk team, bij een leeftijdscategorie of bij alle gezinnen tegelijk.

De regel wordt afgedwongen bij het versturen, niet alleen in de keuzelijst. Een coach die toch om de gezinnen van een ander team vraagt, krijgt nul op het rekest.

**De drie stappen.** Doelgroep, dan het bericht, dan bevestigen — in die volgorde, want de volgorde is de veiligheid. Eerst de doelgroep kiezen betekent dat je schrijft terwijl je weet wie het gaat lezen. Er wordt niets verstuurd tot de laatste stap; wat je hebt getypt blijft als concept bewaard, en afbreken verstuurt niets.

De bevestigingsstap toont precies hoeveel mensen het bereikt en wat de doelgroep in woorden is, en vraagt je akkoord op dat aantal. Ook zie je het bericht zoals een gezin het krijgt.

**Wat een aankondiging niet is.** Het is een gewoon bericht en gedraagt zich ook zo:

- Een gezin dat aankondigingen heeft uitgezet in *Mijn instellingen* krijgt het niet. Het logboek legt vast dat de voorkeur het tegenhield.
- Buiten de berichtuurtjes van je academie wordt het **vastgehouden en de volgende ochtend bezorgd**. Daar is geen uitzondering op. Een bericht dat niet tot zeven uur kan wachten is geen aankondiging — een afgelaste training heeft een eigen bericht en gaat meteen de deur uit.
- Niemand kan erop antwoorden, en terughalen kan niet.

Elke ouder krijgt één exemplaar, ook met twee kinderen in de doelgroep, en gezinnen van wie het kind is vertrokken staan niet op de lijst.

**Daarna.** Het verschijnt in het verzendlogboek, één regel per ontvanger. Mag je het logboek lezen, dan kom je daar uit na het versturen; zo niet, dan ga je terug naar je dashboard.

## Het verzendlogboek

**Instellingen → Berichtenlogboek**, of vanaf het spelersdossier via **⋯ → Verstuurde berichten**.

Elke verzendpoging schrijft een regel, wat de uitkomst ook is. Die regel legt vast wie het stuurde, wie het kreeg, over welke speler het ging, welk sjabloon en kanaal, de onderwerpregel en de status.

Het scherm filtert op speler, soort bericht, uitkomst en datumbereik. Het spelersfilter biedt alleen spelers aan waar het logboek daadwerkelijk een bericht over heeft gedragen — een lijst met elke speler van de academie zou vooral bestaan uit keuzes die niets opleveren.

Uitkomsten staan er in gewone taal, niet als databasesleutel, en in drie tinten in plaats van twee: bezorgd, bewust tegengehouden, en een probleem. Een afmelding die het product netjes heeft gerespecteerd en een adres dat bounced zijn allebei "niet bezorgd" en vragen om een tegengestelde reactie, dus ze krijgen niet dezelfde kleur.

**Een regel zegt twee dingen, geen één.** De uitkomst legt uit waarom het bericht is gestopt. Daarnaast staat een tweede feit: of de ontvanger überhaupt bereikbaar was. Dat zijn twee verschillende vragen, en ze allebei met één woord beantwoorden is precies waarom dezelfde ouder — die zonder e-mailadres en zonder telefoonnummer in het dossier — om tien uur 's ochtends als *geen adres* verscheen en om tien uur 's avonds als *tot morgenochtend vastgehouden*. Hetzelfde gezin, hetzelfde ontbrekende gegeven, beschreven door de regel die het bericht toevallig als eerste tegenhield. Een regel zegt nu *tot morgenochtend vastgehouden* **én** *geen contactgegevens bekend*, en dat is het paar dat je nodig hebt: het eerste verklaart de vertraging, het tweede is het feit waar je iets aan kunt doen.

Regels die vóór deze wijziging zijn geschreven, melden dat de bereikbaarheid **nooit is vastgesteld**, en dat blijft bewust zo staan. Een verzending van vorige maand zou worden beoordeeld op de contactgegevens van vandaag en niet op die van toen; een logboek dat zijn eigen gaten invult, houdt op bewijs te zijn.

Als een geplande detectie blijft mislukken, staat er een waarschuwing boven de tabel met welke het is en wanneer die voor het laatst liep. Dat is de enige plek waar dat verschil zichtbaar wordt: een detectie zonder iets te versturen en een detectie die elke nacht crasht laten allebei geen regels achter.

**De inhoud van het bericht wordt nooit opgeslagen.** Het logboek bewaart er een vingerafdruk van, zodat de regel niet ongemerkt kan worden aangepast, en verder niets. Dat is een bewuste grens: het logboek kan je vertellen dát er een bericht over een kind is verstuurd, aan wie, en of het is aangekomen — en kan niet worden gebruikt om te lezen wat een trainer over dat kind heeft geschreven.

Er is één kortstondige uitzondering. Een bericht dat door de stiltetijden wordt vastgehouden wacht in een aparte wachtrij tot het wordt verstuurd. De wachtrij bewaart wat nodig is om het bericht op te stellen en het adres waar het heen gaat, nooit een bijlage, en elk item wordt verwijderd zodra het bericht is verstuurd of na 24 uur, wat het eerst komt.

Regels blijven standaard **18 maanden** staan. Daarna maakt een dagelijkse taak het ontvangeradres en de onderwerpregel leeg, terwijl de regel zelf blijft — zo blijft het feit van het bericht bewaard als bewijs zonder de persoonlijke details eraan.

## De inbox in de app

**Mijn berichten**, onder Ik op je dashboard. De tegel toont het aantal ongelezen berichten.

Berichten die via het in-app-kanaal gaan, komen in de eigen inbox van de ontvanger terecht in plaats van in de mail. Ongelezen berichten zijn gemarkeerd, en **Markeer als gelezen** haalt die markering weg zonder de pagina opnieuw te laden.

Iedereen ziet alleen zijn eigen inbox. Een ouder ziet berichten over het eigen kind en nooit die van een ander gezin — dat wordt afgedwongen door de zoekopdracht zelf, niet door een rechtencontrole die te omzeilen zou zijn.

## Welke berichten er vandaag uitgaan

Berichten vallen in drie groepen.

**Gebeurtenisgestuurd** — ze gaan af op het moment dat er iets gebeurt. Een training wordt afgelast; een ontwikkelingsplan wordt ondertekend; er wordt een proefperiode voor een speler geopend; een uitnodiging gaat de deur uit; een trainer stuurt een direct bericht; een scoutrapport wordt bezorgd; een herinnering voor proefinvoer gaat uit; een geplande rapportage wordt bezorgd.

Het welkomstbericht voor een proefperiode verdient één kanttekening, want het belooft minder dan je zou verwachten. Een proefdossier legt de speler, het traject en de data vast — er is geen locatie en geen lijstje met wat er mee moet — dus het bericht noemt de startdatum en meldt dat een trainer contact opneemt over het tijdstip, de plek en wat er mee moet. Dat is ook wat er in de praktijk gebeurt, en het is beter dan een bericht met twee lege kopjes erin. Het gaat naar de ouders van een jeugdspeler en naar de speler zelf zodra die daar oud genoeg voor is, net als elk ander bericht over een speler.

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
