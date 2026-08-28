---
title: Mediabibliotheek
group: development
summary: Foto's en video bij spelers, teams en activiteiten — waar bestanden staan, wie ze mag zien, en hoe je het geheel uitzet.
audience: [admin, dev]
views: [media-retention]
module: TT\Modules\Media\MediaModule
feature: media
capability: tt_view_media
order: 70
---

# Mediabibliotheek

Een 7 voor één-tegen-één verdedigen is een cijfer. Het fragment van twaalf seconden erachter is het bewijs. In de mediabibliotheek staan foto's en
video's zó opgeslagen dat ze bij het spelersdossier horen en niet in de camerarol van een trainer blijven liggen.

Deze pagina beschrijft het **fundament**: waar bestanden staan, wie erbij kan, en hoe een academie de functie uitzet. De uploadschermen, het
mediatabblad bij een speler en de demo-inhoud worden hierop gebouwd en worden beschreven zodra ze er zijn.

## Wat een media-item is

Drie soorten:

| Soort | Wat het is |
|---|---|
| Foto | Een afbeelding die naar deze academie is geüpload — JPEG, PNG of WebP. |
| Video | Een videobestand dat naar deze academie is geüpload — MP4 of MOV. |
| Videolink | Een link naar beeld dat ergens anders staat: Veo, Hudl, YouTube of Vimeo. Er wordt niets gekopieerd; de academie bewaart een verwijzing naar de wedstrijd die al online staat. |

Een media-item hangt aan één of meer records — een speler, een team, of een trainings- of wedstrijdactiviteit. Eén foto van een training kan aan die activiteit
worden gekoppeld **én** aan elke speler die erop staat, zodat één upload op elk bijbehorend record verschijnt in plaats van vier keer geüpload te
moeten worden.

## Waar je het ziet

De foto's en video's van een speler staan op een tabblad **Media** op het spelersprofiel, naast Beoordelingen en Blessures. Het tabblad verschijnt
alleen voor wie met zijn rechten bij de media van die speler kan; een trainer zonder toegang tot een selectie ziet het simpelweg niet.

Media staat gesorteerd op **wanneer het gemaakt is**, nieuwste eerst — niet op het moment van uploaden. Daardoor leest het tabblad als een deel van
het verhaal van de speler in plaats van als een map: een camerarol die je in november leegmaakt, duwt augustus niet naar beneden.

Tik op een foto om hem groot te zien, of op een video om af te spelen. Met de pijltjestoetsen ga je heen en weer, met Escape sluit je de weergave.
Video's beginnen pas te laden zodra je er een afspeelt, dus het openen van het tabblad op een telefoon kost geen data aan beeld waar je niet om
hebt gevraagd.

**Verwijderen** wist definitief — het bestand zelf, niet alleen de vermelding. Het wordt alleen aangeboden aan wie de media van die speler mag
bewerken.

### Teams en trainingen

Een team heeft een sectie **Media** op de teampagina, onder de selectie en de wedstrijden — selectiefoto's, toernooidagen, momenten aan het eind
van het seizoen. Er staat alleen in wat aan het team zelf hangt; media van een individuele speler blijft op diens profiel, zodat je bij het
bekijken van een team niet door het dossier van elke speler scrolt. Net als de andere secties op die pagina kun je hem voor je eigen weergave
uitzetten.

Een training of wedstrijd heeft een eigen sectie **Media**, en daar gebeurt het taggen.

### De spelers op een foto taggen

Bij een training of wedstrijd biedt elke foto **Spelers taggen**, met de selectie van dat team. Vink de spelers aan die erop staan en de foto
verschijnt ook op hun profiel — één upload, hoeveel records het ook betreft. Geen opslaanknop: elk vinkje wordt meteen bewaard, en gaat terug als
dat niet lukt.

Een speler untaggen haalt de foto alleen bij die speler weg. De foto blijft bij de training staan en bij iedereen die je verder getagd hebt.

Dit maakt het punt over gedeelde zichtbaarheid hierboven ook concreet: een foto die aan drie spelers hangt, is voor alle drie de gezinnen
zichtbaar.

## Media toevoegen

Gebruik **Media toevoegen** vanaf een speler, team of training. De wizard heeft vier stappen:

1. **Voor wie** — al ingevuld als je vanaf een record begon, dus er valt niets te kiezen.
2. **Bestanden** — kies foto's of video, of plak een link naar video die ergens anders staat. Op een telefoon zit de camera één tik verderop.
3. **Details** — een titel, eventueel een omschrijving, en de datum waarop het gemaakt is.
4. **Bevestigen** — wat er wordt opgeslagen, en waar het verschijnt.

**Uploads worden bewaard zodra ze klaar zijn**, dus vóór je de laatste stap bereikt. Dat is een bewuste keuze: een wegvallende verbinding of een
gesloten tabblad kost je nooit een bestand waar je al op hebt staan wachten. Stop je halverwege, dan staan de foto's al bij het record — alleen
zonder titel, die je later vanaf het record zelf kunt toevoegen.

De datum in stap 3 bepaalt waar de media op de tijdlijn van de speler staat, dus dat is de dag van de training of wedstrijd, niet de dag waarop je
hebt geüpload. Draagt een foto zijn eigen datum, dan wordt die alvast ingevuld.

Van elk bestand zie je de voortgang tijdens het uploaden, en je kunt er één annuleren die te lang duurt zonder de rest kwijt te raken.

### Videolinks

Plak het webadres van een video en TalentTrack bepaalt waar die gehost wordt. Veo, Hudl, YouTube en Vimeo worden herkend; bij YouTube en Vimeo
worden de titel en een miniatuur automatisch opgehaald. Al het andere wordt bewaard als gewone link met een titel die jij typt — TalentTrack neemt
nooit contact op met een adres dat het niet herkent.

### Lange galerijen laden per pagina

Een record met veel media toont de 24 meest recente items en daaronder een knop **Meer tonen**. Elke druk voegt de volgende 24 toe, steeds verder
terug in de tijd, tot er niets meer te laden valt en de knop verdwijnt.

Bewust een knop en geen bijladen tijdens het scrollen: zo blijft de oudste foto bereikbaar, blijft de terugknop van de browser werken, en is er niets
kleins dat je met een duim moet raken.

Het getal op het tabblad Media telt alles wat van die speler bewaard wordt, niet wat er op dat moment op het scherm staat — bij 24 tegels en een
badge van 31 wachten er dus nog zeven achter de knop.

## Waar de bestanden staan

Geüploade bestanden komen **niet** in de WordPress-mediabibliotheek. Een bestand daarin heeft een openbaar webadres: wie het adres kent of raadt,
kan het openen, en dat adres is achteraf niet meer in te trekken. Voor foto's van kinderen is dat geen acceptabel uitgangspunt.

TalentTrack bewaart media in plaats daarvan in een eigen, afgeschermde map (`uploads/tt-media/`) met willekeurig genoemde bestanden. Er is geen
webadres dat ze uitserveert. Elke weergave van een foto of video loopt via TalentTrack, dat eerst controleert wie het opvraagt voordat er ook maar
één byte verstuurd wordt.

Twee beveiligingen, en het is goed om te weten welke het echte werk doet:

- De map bevat een regel die directe toegang via het web blokkeert. **Op Apache-servers werkt dit. Op nginx-servers doet het niets** — nginx leest
 die regels niet.
- De eigen rechtencontrole van TalentTrack draait bij elk verzoek om een bestand, op elke server.

De tweede is de echte grens. De eerste is een nuttige extra waar de server hem respecteert.

### Adressen van media verlopen

Het adres waarvandaan een foto of video laadt, hoort bij jouw sessie en werkt na ongeveer een dag niet meer. Dat is met opzet: een adres dat uit een
pagina wordt gekopieerd — geplakt in een chat, vastgelegd in een serverlog, meegestuurd in een referrer-header — is daardoor voor niemand anders
bruikbaar. Wie zo'n link volgt zonder ingelogd te zijn in TalentTrack, krijgt geen toegang.

Het praktische gevolg is klein maar goed om te weten: een galerij die 's nachts in een browsertabblad open blijft staan, toont de volgende ochtend
gebroken afbeeldingen. De pagina herladen lost dat op.

### Locatiegegevens worden verwijderd

Een foto of video die met een telefoon is gemaakt, legt meestal vast waar hij gemaakt is. Bij een training is dat de locatie van een veld vol
kinderen, en die informatie zit ín het bestand.

**Foto's.** TalentTrack leest de opnamedatum uit — zodat de foto op de juiste plek in de tijdlijn van de speler belandt — en verwijdert daarna alle
ingesloten gegevens, locatie inbegrepen, vóór opslag. Het opgeslagen bestand bevat de foto en verder niets.

**Video.** TalentTrack zoekt de plekken in het videobestand op waar telefoons coördinaten wegschrijven en maakt die leeg vóór opslag. Beeld en
geluid worden niet aangeraakt en het bestand wordt niet opnieuw gecodeerd, dus aan de opname verandert niets.

Na het uploaden van een video vertelt de uploadlijst wat er is gebeurd:

- *Locatiegegevens zijn uit deze video verwijderd.* — er zijn coördinaten gevonden en die zijn weg.
- Niets — het bestand bevatte geen locatiegegevens.
- Een waarschuwing dat het bestand **gegevens bevat die TalentTrack niet kon lezen** — het bestand is opgeslagen, maar iets erin was niet te
  begrijpen en het kan nog steeds prijsgeven waar het is opgenomen. Verwijder die gegevens vóór het uploaden, of gebruik in plaats daarvan de
  videolink.

Dat laatste komt zelden voor en wordt bewust getoond in plaats van verzwegen. TalentTrack noemt een bestand niet schoon als het dat niet zeker weet.

Wil je liever helemaal geen beeld op de server, gebruik dan de videolink en houd het beeld bij je videoleverancier.

### Uploadgrootte

Hoe groot een bestand mag zijn, bepaalt je webserver — niet TalentTrack. Veel hostingpakketten staan standaard tussen 8MB en 64MB toe, minder dan
een minuut telefoonvideo. Het uploadscherm toont de werkelijke limiet van jouw server. Is die te klein, vraag je host dan om `upload_max_filesize`
en `post_max_size` te verhogen, of gebruik videolinks.

Geüploade video kost ook echte schijfruimte, en niets ruimt die automatisch op. Zodra een academie media heeft opgeslagen, verschijnt het totaal
als **Media opgeslagen** op de systeemstatusbalk van de academiebeheerder — dus op de plek waar je toch al kijkt, en niet achter een instellingen-
tabblad. Er is nog geen automatische opschoning en geen bewaartermijn: wanneer oude media weg mag, is een beleidskeuze, en die verzint TalentTrack
niet voor je.

Een academie die wekelijks wedstrijdfragmenten uploadt, moet dat getal afzetten tegen wat de hosting daadwerkelijk biedt — TalentTrack kan niet
zien hoe groot de schijf is waarop het draait.

## Toestemming voor foto en video vastleggen

Elk spelersrecord heeft een vinkje **Toestemming foto & video**, op het bewerkformulier naast de foto. Aanvinken legt de datum vast en de naam van de
medewerker die het registreerde, zodat de vermelding bewijs is en geen bewering. Uitvinken haalt beide weg, want de herkomst van een toestemming die
niet meer geldt, zou alleen maar misleiden.

Het profiel van de speler toont het antwoord aan de staf — óók als het antwoord nee is, want een leeg veld leest als "er is niet naar gevraagd".

**Het legt vast, het beperkt niet.** Bij het toevoegen van een foto wordt dit vinkje nergens gecontroleerd. Een trainer kan media toevoegen bij een
speler zonder vastgelegde toestemming, en de academie wordt daarin niet tegengehouden. Dat is bewust. De echte beheersmaatregel is het gesprek en het
formulier dat het gezin heeft getekend; een harde blokkade langs de lijn wordt in de praktijk omzeild door met een privételefoon te fotograferen, en
daar is het kind slechter mee af dan met een vastgelegd hiaat.

Waar het veld wél voor is: de vraag beantwoorden — *wie mogen we fotograferen?* — vóór een wedstrijddag, en kunnen laten zien dát de vraag gesteld is.

Intrekken gebeurt door het vinkje weg te halen. Dat werkt niet met terugwerkende kracht op al opgeslagen foto's; wil een gezin bestaande media
verwijderd hebben, dan gebeurt dat via het tabblad Media van de speler.

## Wie de media van een speler mag zien

- **Staf** — trainers, scouts en beheerders — ziet de media van de spelers waar zij verantwoordelijk voor zijn, volgens dezelfde rechten die voor
 de rest van het spelersdossier gelden.
- **De speler zelf** en de **ouder of verzorger** zien de media van die speler.
- Verder niemand. Media gaat nooit van de ene academie naar de andere, en komt niet bij staf zonder toegang tot die speler.

### Op een foto kan meer dan één kind staan

Staat een foto of fragment gekoppeld aan drie spelers, dan kunnen alle drie de gezinnen het zien. Dat is een bewuste keuze: teamsport wordt in
groepen gefotografeerd, en het alternatief — een gezin alleen beeld tonen waarop hun kind alléén staat — zou vrijwel elke trainingsfoto voor
iedereen verbergen.

**Zorg dat je toestemmingstekst hierop aansluit.** Vertel gezinnen bij aanmelding dat foto's en video's die bij de academie gemaakt worden hun kind
samen met anderen kunnen tonen, en zichtbaar kunnen zijn voor die andere gezinnen. Dit hoort geen ontdekking te zijn die een ouder doet bij het
zien van een onverwachte foto.

## Hoe lang media bewaard blijft

Een academie die de vraag krijgt *"hoe lang bewaren jullie foto's van mijn kind?"* heeft een antwoord nodig. Dat van TalentTrack luidt: **een
ingestelde periode nadat de speler weggaat**, en daarna kijkt er een mens naar.

De periode stel je in onder **Configuratie → Media bewaren nadat een speler weggaat**. Standaard staat die op **drie jaar** en je kunt hem op alles
tussen één en tien jaar zetten, of op **Onbeperkt bewaren** als je academie liever per geval beslist.

Drie dingen zijn hierbij van belang, want die maken het veilig:

**De klok begint als de speler weggaat, niet wanneer de foto gemaakt is.** Een speler die nog bij de academie zit, houdt zijn hele dossier, hoe oud
ook. Dat verloop over de jaren — dezelfde speler op zijn 12e en op zijn 18e — is precies waar het product voor bestaat, en een termijn vanaf de
opnamedatum zou daar stilletjes het begin van wissen.

**Er wordt nooit automatisch iets verwijderd.** Als de periode verstreken is, verschijnt de media onder **Mediabewaring** zodat iemand kan
beslissen. Daarom is een standaardperiode veilig: hij start een beoordeling, geen verwijderklok. Een academie die bijwerkt vindt een lijst, geen
gaten in het dossier.

**Het verlopen geldt de koppeling met één speler, niet de hele foto.** Een teamfoto met een vertrokken speler erop gaat van *diens* dossier af; hij
blijft bij het team, bij de training waar hij vandaan komt, en bij de andere spelers erop. Pas als er niets meer naar een bestand verwijst, wordt
het bestand zelf verwijderd.

### Beoordelen

**Mediabewaring** toont wat er klaarstaat, oudste vertrek eerst, met twee keuzes per item:

- **Verwijderen** — haalt het van het dossier van die speler af. Hangt er verder niets meer aan, dan wordt het bestand definitief gewist; de pagina
 vertelt je wat er gebeurd is.
- **Bewaren** — houdt het vast, en vraagt waarom. Een zorgmelding, een lopend geschil, een bezwaar. Bewaarde items staan apart met hun reden erbij,
 want een bewaarbeleid met een onzichtbare lijst uitzonderingen is niet te controleren. Je kunt een bewaard item later terugzetten in de wachtrij.

Sommige regels staan als **geschat**. Dat betekent dat er geen vertrekdatum van de speler bekend is — meestal omdat hij wegging voordat TalentTrack
die vastlegde — en dat de datum van de laatste wijziging aan zijn dossier is gebruikt. Dat bepaalt alleen wanneer het item ter beoordeling
verschijnt; er wordt niets op besloten.

## Een speler verwijderen verwijdert de media

Wordt een speler definitief verwijderd, dan gaan de mediakoppelingen mee. Elke foto of video die alléén aan die speler hing, wordt volledig
verwijderd — zowel de registratie als het bestand zelf. Media die ook aan een team of een activiteit hangt, blijft bestaan, omdat die records er nog
naar verwijzen.

Dat is van belang bij een verzoek om vergeten te worden: het wissen van een speler wist ook de foto's, niet alleen de regel met de naam erin.

## Wat een inzageverzoek oplevert

Doet iemand een beroep op het recht om te zien wat de academie over een speler bewaart, dan bevat de export een `media.json` met alle foto's en
video's die van die speler worden bewaard: wat het is, wanneer het is gemaakt, waar het aan hangt en wie het heeft toegevoegd.

**De bestanden zelf zitten niet in de export.** Een seizoen aan video loopt in de gigabytes, en een export die te groot is om te maken helpt niemand.
De export zegt dat er met zoveel woorden bij en zwijgt er niet over, want een lijst zonder uitleg leest alsof de academie niets bewaart.

Wil de verzoeker de bestanden wel, dan stuurt de academie ze apart na — op het tabblad Media van de speler staat alles, en elk item kan daar geopend
en opgeslagen worden.

Media van een team of een activiteit komt nooit in de export van één speler terecht, ook niet als die speler erbij was. Die hoort bij het team of bij
de training, niet bij één kind.

## Uitzetten

Er zijn twee schakelaars, met verschillende gevolgen.

**De moduleschakelaar** (Modules → Mediabibliotheek) zet de functie volledig uit. Een academie die helemaal geen foto's van haar spelers in het
systeem wil, gebruikt deze. Met de module uit wordt niets van de mediafunctionaliteit geladen.

**De functieschakelaar** (Functies → Mediabibliotheek) verbergt de mediaschermen, maar laat de module en alles wat al opgeslagen is intact. Gebruik
deze om media uit het dagelijks gebruik te halen zonder het verzamelde materiaal weg te gooien.

Geen van beide schakelaars verwijdert iets. Weer aanzetten brengt de bestaande media precies terug zoals die was.

## Voor ontwikkelaars

- Tabellen: `tt_media` (het item) en `tt_media_links` (waar het aan hangt). Beide club-scoped; `tt_media` heeft een `uuid`, en dát is de identiteit
 die de REST-laag naar buiten brengt — oplopende id's zijn van buitenaf niet adresseerbaar.
- Opslag zit achter `MediaStorageInterface`. `LocalPrivateStorage` is de meegeleverde implementatie. `tt_media.storage_key` is **ondoorzichtig**:
 geen pad en geen URL, en alleen de adapter die hem geschreven heeft mag hem interpreteren. Registreer een andere adapter via de filter
 `tt_media_storage_adapters`; bestaande rijen blijven bediend door de adapter die in de rij genoemd staat.
- De mediamap is standaard `uploads/tt-media/` en is aan te passen via de filter `tt_media_storage_root`. Wijs hem naar een apart volume wanneer
 groeiende video anders de schijf bedreigt waarop WordPress zelf draait. Een gefilterd pad wordt letterlijk gebruikt en moet dus absoluut en
 beschrijfbaar zijn.
- `MediaIngestService` bepaalt het bestandstype aan de hand van de bytes zelf, nooit aan de hand van de naam, en weigert SVG categorisch.
- `MediaLinksRepository::unlink()` verwijdert de media én het bestand zodra de laatste koppeling weggaat. Een media-item zonder koppelingen is
 onbereikbaar en wordt niet bewaard.
