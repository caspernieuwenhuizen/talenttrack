---
title: Foto-invoer — DPIA
group: operator
summary: De gegevensbeschermingseffectbeoordeling voor foto-naar-training-invoer.
audience: [admin]
order: 30
---

# Foto-naar-training-invoer — DPIA

> Vereist op grond van artikel 35 AVG vóór brede uitrol van de foto-naar-training-invoer bij een club waarvan de foto's minderjarige spelers kunnen bevatten.

> **De Engelse versie is de leidende tekst.** Dit is een vertaling voor de Nederlandse lezer. Wijkt de vertaling ergens af, dan geldt `docs/photo-capture-dpia.md`.

> ## Bijna klaar om te ondertekenen
>
> **Juridische goedkeuring is gegeven op 23-08-2026.** Vereisten 2 tot en met 5 zijn besloten en hieronder vastgelegd; 7 is een restrisico dat de FG bewust aanvaardt, geen gat. Wat er vóór een handtekening nog echt open staat:
>
> 1. **Twee open velden in § 4** — waar toestemming wordt vastgelegd en hoe die wordt ingetrokken. Dat kan het product niet weten.
> 2. **De bestemming in § 2** — het endpoint en de regio die deze installatie verklaart. Zolang die niet in `wp-config.php` staan, kan er niets verstuurd worden.
> 3. **Vereiste 6**, de providervergelijking. Geen juridische blokkade, maar een handtekening impliceert dat de extractie geschikt is voor het doel uit § 5, en dat is nooit getoetst op echt coachhandschrift.
>
### Hoe dit document hier is gekomen
>
> Een toetsing tegen de opgeleverde code (22-08-2026) liet zien dat een aantal technische beweringen waarborgen beschreef die niet bestonden. Die passages zijn herschreven naar wat de code werkelijk doet, en **§ 0** houdt elke vereiste bij die daaruit voortkwam.
>
> **Twee zijn daarna in code gesloten** (23-08-2026): er is geen standaard-endpoint meer — er wordt niets verstuurd tot deze installatie verklaart waar foto's heen gaan — en de extractieprompt houdt spelersnamen uit vrije tekstvelden. Vereiste 7 is maar *gedeeltelijk* gesloten; lees het restrisico vóór ondertekening.
>
> **Correctie van 23-08-2026, tweede ronde:** dit document beweerde kort dat de feature-vlag `exercises_vision_extraction` op een verse installatie uit stond en leunde daarop als waarborg. Die staat **aan**. De bewering was onjuist, ze is hier geschreven tijdens precies de toetsing die dit soort fouten moest wegnemen, en ze is ook in de release-notities van v4.96.0 beland. Aan de feitelijke veiligheid van de installatie veranderde niets — een verse installatie verstuurt nog steeds niets, omdat er geen bestemming is verklaard — maar de reden is de bestemmingsdrempel, niet de vlag.
>
> De correctie die dit alles in gang zette: de vorige versie stelde dat foto's standaard naar een EU-endpoint gingen en dat EU-residentie verlaten een bewuste opt-out vergde. Geen van beide klopte.

Dit sjabloon legt de gegevensbeschermingseffectbeoordeling voor de foto-invoer vast. Elke paragraaf heeft ruimte voor de specifieke situatie van de uitrollende academie; de technisch opgeleverde standaarden zijn ingevuld waar dat kan. Afdrukken, invullen, ondertekenen, bewaren — dat is het dossier waarmee de operator zijn zorgvuldigheid aantoont.

## 0. Voordat dit ondertekend kan worden

Elk punt is een technische of besluitvereiste. Een handtekening die wordt gezet terwijl een van deze open staat, legt een zorgvuldigheidstoets vast die niet heeft plaatsgevonden.

| # | Wat ontbreekt | Wie sluit het |
| --- | --- | --- |
| 1 | ✅ **Gesloten.** Er is geen standaard-endpoint meer. De functie weigert iets te versturen tot de operator zowel `TT_VISION_ENDPOINT` als `TT_VISION_DATA_REGION` verklaart; tot die tijd meldt ze zich als niet-geconfigureerd en vallen aanroepen terug op handmatige invoer. **Wat dit níét doet is de verklaring verifiëren** — geen plugin kan vaststellen of een endpoint gegevens werkelijk verwerkt waar de operator zegt. Wat het wél garandeert: de bestemming is altijd een keuze die iemand heeft gemaakt, en dat is wat een DPIA eerlijk kan vastleggen. De verklaarde regiotekst hoort in § 2. | Klaar — de juistheid van de verklaring blijft van de operator |
| 2 | ✅ **Besloten 23-08-2026: 7 dagen, en nu afgedwongen.** Een foto die op de telefoon van een coach wacht omdat er geen bereik is, wordt na zeven dagen weggegooid, beoordeeld of niet. Het venster wordt opgeruimd bij elke keer dat het invoerscherm laadt en elk uur zolang het openstaat, zodat een telefoon die twee weken dicht was zijn voorraad weggooit vóór hij die weer aanbiedt. De coach krijgt te horen dat de foto is verlopen in plaats van hem stilzwijgend kwijt te zijn. | Klaar |
| 3 | ✅ **Besloten 23-08-2026: toestemming, art. 6, lid 1, onder a**, gegeven door de ouder of voogd, omdat het om minderjarigen gaat. § 4 legt dat vast. De verwerkingsverantwoordelijke moet nog wel benoemen **waar** die toestemming wordt vastgelegd en hoe die wordt ingetrokken — zie de twee open velden in § 4. | Besloten; twee velden invullen bij ondertekening |
| 4 | ✅ **Besloten 23-08-2026: geen bevestiging in het product.** Toestemming wordt bij inschrijving geregeld, buiten het product om. Het invoerscherm vermeldt waar de foto heen gaat en verder niets; de plek voor een eerste-keer-paneel is uit het ontwerp gehaald in plaats van te blijven suggereren dat er iets aankomt. | Besloten — niets te bouwen |
| 5 | ✅ **Bevestigd 23-08-2026** door de verwerkingsverantwoordelijke als onderdeel van de juridische goedkeuring. Opnieuw bevestigen bij elke jaarlijkse herziening en telkens als de bestemming wijzigt. | Klaar |
| 6 | **Providervergelijking** — de standaardprovider is nooit getoetst op echt coachhandschrift. Geen juridische blokkade, maar een handtekening impliceert dat de extractie geschikt is voor het doel uit § 5. | Techniek |
| 7 | ⚠️ **Deels gesloten — lees het restrisico.** De extractieprompt instrueert het model om spelersnamen in de gestructureerde `attendance`-array te houden en er nooit een in een vrij `notes`-veld of oefeningnaam te schrijven. **Een prompt is een verzoek, geen garantie**, en een serverzijdige filtering tegen de selectielijst is overwogen en bewust niet gebouwd. Een naam die het model tóch overneemt, belandt dus ergens waar noch een inzageverzoek noch een verwijderverzoek bij kan. Onderteken alleen als de FG dat restrisico bewust aanvaardt. | Instructie opgeleverd; de rest aanvaarden is een FG-besluit |

## 1. Beschrijving van de verwerking

**Wat de functie doet**: een coach fotografeert zijn met de hand geschreven trainingsplan met een telefooncamera. De afbeelding gaat naar een LLM met beeldherkenning — standaard Claude Sonnet, op **het endpoint dat deze installatie heeft verklaard** (§ 2; er is geen standaard, en er gaat niets weg tot er één verklaard is). Het model haalt er een gestructureerde lijst uit van oefeningen, tijdsduur en eventueel aanwezigheidstekens. De coach bekijkt de extractie na, past aan waar nodig, en slaat de training op.

**Persoonsgegevens die in beeld kunnen komen**:

- De foto van het trainingsplan zelf.
- Zichtbare spelersnamen op het plan (als de coach de aanwezigheid op hetzelfde vel heeft gekrabbeld).
- Het handschrift van de coach (in sommige uitleggen biometrie-achtig).
- De gestructureerde extractietekst die het model teruggeeft (die herhaalt welke spelersnamen op de foto stonden).

**Betrokkenen**: jeugdvoetballers (deels minderjarig), ouders (zelden, bijvoorbeeld als er rijschema's op het plan staan), coaches.

## 2. Gegevensstroom

```
[Telefooncamera]
     │
     │ ── geen bereik? ──▶ [Op het toestel bewaard: IndexedDB `tt_photo_hold`]
     │                            │  Verlaat de telefoon nooit. Weg na 7 dagen,
     │                            │  of zodra de extractie is nagekeken.
     │                            │  Gaat hier verder zodra er weer bereik is.
     │       ◀────────────────────┘
     │
     │ HTTP POST multipart/form-data (of JSON photo_base64)
     ▼
[TalentTrack-server]
     │   De beeldbytes worden vanuit de tijdelijke uploadlocatie van PHP
     │   rechtstreeks in het geheugen gelezen. Ze worden NOOIT naar
     │   wp-content/uploads of enige andere opslag geschreven.
     │
     │ HTTPS
     ▼
[Beeldprovider — endpoint wordt door de operator ingesteld; zie EU-residentie]
     │
     │ Inferentie
     ▼
[Gestructureerd JSON-antwoord]
     │
     ▼
[TalentTrack-server] — geeft de extractie terug aan de browser.
     │                 De beeldbytes vallen aan het einde van het
     │                 verzoek buiten scope; PHP ruimt het tijdelijke
     │                 bestand op.
     ▼
[Coach kijkt na — er wordt niets bewaard voordat de coach bevestigt]
     │
     ▼
[Opgeslagen training — tt_activities + tt_activity_exercises]
```

**Toegangsdrempel**: het endpoint weigert elk verzoek tenzij de feature-vlag `exercises_vision_extraction` aanstaat **en** de aanroeper `tt_edit_activities` heeft (`VisionExtractRestController::register()`).

⚠️ **Die vlag staat standaard aan** (`FeatureRegistry`: `'default_enabled' => true`). Een eerdere versie van dit document beweerde het omgekeerde en behandelde de vlag als datgene wat tussen een verse installatie en verwerking in stond. Dat is niet zo. **De drempel die een verse installatie werkelijk tegenhoudt is de bestemmingsverklaring hieronder** — geen endpoint en geen regio betekent dat er niets weggaat, wat de vlag ook zegt. Een academie die een tweede, bewuste schakelaar wil, zet de functie expliciet uit in plaats van aan te nemen dat ze uit begint.

**Gegevenslocatie — de operator verklaart die, en er gaat niets weg tot dat is gebeurd.**

Er is geen standaard-endpoint. De functie verstuurt geen foto tot deze installatie in `wp-config.php` heeft vastgelegd waar verzoeken heen gaan én wat dat betekent:

```php
define( 'TT_VISION_ENDPOINT',    'https://…' );          // waar verzoeken heen gaan
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // waar die gegevens verwerkt
```

Zolang beide ontbreken meldt de provider zich als niet-geconfigureerd, precies zoals bij een ontbrekende API-sleutel, en vallen aanroepen terug op handmatige invoer. Het REST-endpoint antwoordt `503 destination_not_declared` en zegt dat er met zoveel woorden bij.

**Schrijf de verklaarde regio hier letterlijk over als onderdeel van het invullen van dit document:**

> `TT_VISION_DATA_REGION` op deze installatie: `________________________________`

**Wat dit niet doet.** Het kan de verklaring niet verifiëren. Geen plugin kan vaststellen of een endpoint gegevens werkelijk verwerkt waar de operator zegt — dat is een contractueel feit, geen netwerkfeit. Dat bevestigen is vereiste 5 en blijft de verantwoordelijkheid van de operator. Wat de code wél garandeert is smaller en meer waard: **de bestemming is altijd een keuze die iemand heeft gemaakt.** Een DPIA kan een verklaring eerlijk vastleggen; een standaard die niemand gelezen heeft, niet.

Er is nog steeds geen AWS Bedrock-pad — Bedrock vereist SigV4-ondertekening, en die is niet geïmplementeerd. De `TT_VISION_BEDROCK_*`-constanten uit oudere versies van dit document zijn uit de codebase verwijderd omdat niets ze ooit las. Richt `TT_VISION_ENDPOINT` op iets dat de Anthropic Messages API spreekt.

De OpenAI-provider is opgeleverd als stub en in zijn eigen label als DPIA-onverenigbaar gemarkeerd voor EU-clubs, omdat het beeldendpoint van OpenAI alleen via de VS loopt — zet die niet aan bij een club waarvan de betrokkenen minderjarig zijn.

**Geen opslag bij de provider**: welke provider de operator ook instelt, bevestig dat diens verwerkersvoorwaarden bewaring en training op invoergegevens uitsluiten, op de datum van ondertekening. Vertrouw niet op een bewering in dit document over een provider waarmee je geen contract hebt.

## 3. Bewaartermijnen

| Gegeven | Bewaartermijn | Mechanisme |
|---|---|---|
| Bronfoto (ruwe bytes), aan serverzijde | **Alleen de duur van het HTTP-verzoek** | De bytes worden vanuit de tijdelijke uploadlocatie van PHP in het geheugen gelezen en aan de provider doorgegeven. Niets schrijft ze naar schijf, dus er is geen uploadmap en geen opruimtaak. PHP ruimt zijn eigen tijdelijke bestand op als het verzoek eindigt. Eerdere versies van dit document beschreven zeven dagen bewaring met een cron-opruiming en een constante `TT_VISION_PHOTO_RETENTION_DAYS`; **beide bestaan niet**, en het werkelijke gedrag is strenger. |
| Bronfoto, **op het toestel van de coach** | **7 dagen** — besloten 23-08-2026, sindsdien afgedwongen | Een foto die buiten bereik is gemaakt, wacht in de IndexedDB van de browser (`tt_photo_hold`) op dát toestel, en gaat weg zodra er weer verbinding is. Hij wordt verwijderd zodra zijn extractie is nagekeken, en onvoorwaardelijk weggegooid zeven dagen na het maken — opgeruimd bij laden en elk uur, zodat een telefoon die over de vervaldatum heen dicht stond hem weggooit vóór hij hem weer aanbiedt. De coach krijgt te horen dat een foto is verlopen; een foto die zonder woorden verdwijnt is erger dan een die luid verloopt. De zeven dagen zijn het plafond, niet het doel. |
| Gestructureerde extractietekst | Onbepaald (hoort bij de opgeslagen training) | Blijft in `tt_activity_exercises` als onderdeel van het trainingsdossier. Valt onder het algemene bewaarbeleid van de academie. |
| Invoergegevens bij de provider | Volgens het contract van de operator met de provider | Toetsen aan het geldende contract; zie § 2. |

De operator kan foto-invoer volledig uitzetten met `define( 'TT_VISION_PROVIDER', '' );` in `wp-config.php`, of door de functie `exercises_vision_extraction` uit te schakelen — die **aan** staat, dus uitzetten is een handeling en geen toestand om op te vertrouwen. De twee bestemmingsconstanten simpelweg niet invullen betekent al dat er niets weggaat. De handmatige invoer werkt in beide gevallen gewoon door.

## 4. Grondslag

Leg de door de academie gekozen grondslag onder artikel 6 AVG vast:

- [ ] **Gerechtvaardigd belang** (art. 6, lid 1, onder f) — de academie heeft een gerechtvaardigd belang bij efficiënte vastlegging van trainingsgegevens. De operator moet een belangenafweging (LIA) opstellen.
- [x] **Toestemming** (art. 6, lid 1, onder a) — bij minderjarigen wordt toestemming gegeven door de ouder of voogd. **Gekozen op 23-08-2026.**
- [ ] **Uitvoering van een overeenkomst** (art. 6, lid 1, onder b) — de academie heeft een overeenkomst met het gezin waarin het vastleggen van trainingsgegevens een prestatie is.

Kies er hoogstens twee; leg vast waarom.

**Waarom toestemming.** De betrokkenen zijn kinderen, en de verwerking stuurt hun beeltenis — of hun naam, als de coach de aanwezigheid op hetzelfde vel schreef — naar een derde partij die de academie heeft uitgekozen. Gerechtvaardigd belang zou de academie haar eigen gemak laten afwegen tegen de privacy van een kind, en haar eigen huiswerk laten nakijken; toestemming legt de beslissing bij de ouder, en daar hoort ze bij dit soort verwerking.

Twee dingen die de verwerkingsverantwoordelijke nog moet invullen, omdat het product ze niet kan weten:

> Waar toestemming wordt vastgelegd: `________________________________________`
>
> Hoe die wordt ingetrokken: `________________________________________`

**Toestemming wordt buiten het product vastgelegd** (vereiste 4). Er is bewust geen bevestiging in het product vóór de eerste upload van een coach: een extra tik op het invoerscherm zou eruitzien als toestemming terwijl die van de verkeerde persoon komt — de coach is niet de betrokkene, en niet diens voogd.

**Wat intrekken hier betekent.** Omdat de server de foto nooit opslaat, vergt het intrekken van toestemming niet dat er een foto wordt verwijderd — die is er niet. Wat het wél raakt is de gestructureerde extractie bij de opgeslagen training. Zie § 6 voor wat een verwijderverzoek wel en niet bereikt, inclusief de beperking bij vrije tekst uit vereiste 7.

## 5. Noodzaak en evenredigheid

- **Waarom een foto plus AI?**: coaches leggen trainingen structureel niet handmatig vast na afloop (het "data missed"-probleem uit de specificatie). Zonder deze functie gaat ≥ 40% van de trainingsgegevens definitief verloren.
- **Minder ingrijpende alternatieven die zijn overwogen**:
  - De coach typt rechtstreeks in het trainingsformulier → te veel drempel; werkt in de praktijk niet.
  - Spraakinvoer → overwogen voor v2; uitgesteld volgens specificatie.
  - Extractie volledig op het toestel (geen cloud-LLM) → op v1-kwaliteitsniveau niet haalbaar; opnieuw bekijken zodra lokale beeldmodellen de kwaliteit van Claude Sonnet 4.x halen.
- **Evenredigheid**: wat de deur uitgaat is de foto die de coach toch al op zijn eigen telefoon maakte, en de server bewaart alleen de gestructureerde extractie — de beeldbytes worden nooit naar schijf geschreven. Of de provider de invoer bewaart, hangt af van het contract van de operator, en vereiste 5 in § 0 vraagt daar bevestiging van.

## 6. Rechten van betrokkenen

| Recht | Hoe TalentTrack het ondersteunt |
|---|---|
| Inzage (art. 15) | **Gedeeltelijk.** Wie bij een training aanwezig was, is gedekt — `tt_attendance` staat in `PlayerDataMap` en komt in de export. De extractie zelf niet: die belandt in `tt_activity_exercises`, dat vastlegt *welke oefeningen een training bevatte* en **geen enkele spelersaanduiding** draagt, dus het is geen speler-gekoppelde gegevensset en kan niet worden geregistreerd (`PlayerDataMap::register()` vereist een kolom die aan spelersidentiteit koppelt). ⚠️ **Het restrisico zit in de vrije tekstkolom `notes`.** De extractie kan spelersnamen van de foto herhalen, en een naam in vrije tekst is niet bereikbaar voor een export die op tabellen en kolommen werkt — die zou dus niet worden geëxporteerd en niet worden gewist. De prompt instrueert het model nu om namen in de gestructureerde `attendance`-array te houden, waar ze aan een speler vastzitten; dat verkleint het risico zonder het weg te nemen, want een instructie is geen afdwinging. Zie vereiste 7 in § 0. Een eerdere versie van dit document beweerde dat beide tabellen in de export zaten; dat is niet zo. |
| Rectificatie (art. 16) | Via het bewerkformulier van de training corrigeert een coach elke geëxtraheerde oefening of aanwezigheid. De nakijkwizard maakt dat het standaardpad vóór opslaan. |
| Wissen (art. 17) | Het verwijderen van de **activiteit** werkt door in `tt_activity_exercises` (`CascadeRegistry`), en `tt_activities.archived_at` verwijdert zacht. Maar het wissen van **één speler** verwijdert de training niet — die hoort bij een team en de dossiers van de andere spelers hangen eraan — dus wat er over die speler in `tt_activity_exercises.notes` staat, overleeft diens verwijderverzoek. Zelfde oorzaak als bij Inzage; vereiste 7 in § 0. |
| Beperking (art. 18) | De operator kan een activiteit als `is_draft` markeren zodat ze niet in rapportages meeloopt. |
| Overdraagbaarheid (art. 20) | Wat de inzage-export dekt, is overdraagbaar in dezelfde ZIP — de aanwezigheid dus wel, de extractie niet. Zie de rij bij Inzage. |
| Bezwaar (art. 21) | `TT_VISION_PROVIDER` uitzetten haalt de provider er onmiddellijk tussenuit. |

## 7. Risico's en maatregelen

| Risico | Kans | Impact | Maatregel |
|---|---|---|---|
| Foto van een minderjarige gaat naar een endpoint buiten de EU | Laag — kan niet meer standaard gebeuren, alleen door een onjuiste verklaring | Hoog | De functie weigert iets te versturen tot de operator een endpoint en een regio verklaart; er is geen standaard om op terug te vallen. Het restrisico is een verklaring die onjuist of verouderd is, en daar zien vereiste 5 en de jaarlijkse herziening op toe. De OpenAI-adapter markeert bovendien in zijn eigen label dat hij niet EU-verenigbaar is. |
| Provider traint op invoergegevens | Afhankelijk van het contract van de operator | Zeer hoog | Toets de verwerkersvoorwaarden van de ingestelde provider bij ondertekening en bij elke jaarlijkse herziening. Dit document doet daarover geen uitspraak namens de operator. |
| Geëxtraheerde tekst wijst aanwezigheid verkeerd toe | Middel | Middel | De nakijkwizard vraagt expliciete goedkeuring van de coach vóór opslaan; bij een matchbetrouwbaarheid onder 0,6 komt de regel als "handmatig nakijken" naar voren (`ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY`). |
| Een wachtende foto blijft achter op een verloren of gedeeld toestel | Middel | Middel | Zeven dagen is het plafond (§ 3). Zeven is de ruimste van de overwogen opties en is bewust gekozen: het is het venster waarin een coach die op vrijdagavond fotografeert en het weekend erna kijkt, zijn training nog heeft. De kortere alternatieven laten het werk van die coach stilzwijgend verdwijnen. |
| Een spelersnaam wordt in vrije tekst overgenomen en is daarna niet meer vindbaar | Verkleind maar niet weg — het plan en de aanwezigheidstekens staan vaak op hetzelfde vel | Middel | De extractieprompt instrueert het model om namen in de gestructureerde `attendance`-array te houden, waar ze aan een speler vastzitten, en uit elk vrij tekstveld te laten. **Dit is een instructie aan een model, geen afdwinging**: een serverzijdige filtering tegen de selectie is overwogen en niet gebouwd, dus een overgenomen naam bereikt nog steeds een kolom die geen export of verwijdering ziet. Vereiste 7 — bewust aanvaarden of opnieuw bekijken. |
| API-sleutel lekt | Laag (constante in wp-config) | Hoog | Leg een rotatieprocedure voor sleutels vast; zet `wp-config.php` nooit in git. |
| De functie wordt gebruikt vóór dit document is ondertekend | Laag | Hoog | **Niet** omdat de feature-vlag uit staat — die staat aan. Wel omdat een verse installatie geen bestemming heeft verklaard, dus het endpoint antwoordt `503` en verstuurt niets. Beschouw *het verklaren van de bestemming* als de handeling die deze handtekening toestaat, en zet `exercises_vision_extraction` expliciet uit als je een tweede slot wilt. |

## 8. Jaarlijkse herziening

De DPIA wordt elke 12 maanden vanaf de datum van brede uitrol herzien. Eerder herzien is verplicht als:

- De voorwaarden van de ingestelde provider wijzigen.
- `TT_VISION_ENDPOINT` wordt toegevoegd, verwijderd of aangepast — dat verandert waar foto's heen gaan.
- De regio van de provider wijzigt.
- Er een nieuwe beeldprovider aan het register wordt toegevoegd.
- De bewaartermijn wordt verlengd.
- Er nieuwe categorieën betrokkenen bijkomen (bijvoorbeeld als oudernamen op plannen gaan verschijnen).

## 9. Ondertekening

| Rol | Naam | Datum | Handtekening |
|---|---|---|---|
| Verwerkingsverantwoordelijke (academiebeheerder) | __________________ | _______ | _________ |
| Functionaris gegevensbescherming (indien aangesteld) | __________________ | _______ | _________ |
| Technisch verantwoordelijke TalentTrack | __________________ | _______ | _________ |

Bewaar één exemplaar in het DPIA-register van de academie en één in de compliancemap naast wp-config.

---

## Technische referentie

Configuratie:

```php
// wp-config.php — alle vier zijn vereist; er is geen werkende standaard
define( 'TT_VISION_PROVIDER',    'claude_sonnet' );      // '' zet de functie volledig uit
define( 'TT_VISION_API_KEY',     'sk-ant-...' );         // gaat mee als x-api-key-header
define( 'TT_VISION_ENDPOINT',    'https://…' );          // waar foto's heen gaan
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // waar dat endpoint ze verwerkt
```

De functie vereist daarnaast dat de vlag `exercises_vision_extraction` aanstaat — **en die staat standaard aan**, dus dat is geen drempel die een verse installatie moet nemen. De drempel is het paar constanten hierboven: zonder die antwoordt het endpoint `503 destination_not_declared` en gaat er niets weg, wat de vlag ook zegt.

`TT_VISION_DATA_REGION` is bewust vrije tekst. Een keuzelijst nodigt uit om de dichtstbijzijnde optie aan te klikken; de woorden uitschrijven is een kleine daad van aandacht, en die tekst is wat § 2 van dit document vastlegt.

**Constanten die NIET bestaan.** Deze instellen heeft geen enkel effect; ze stonden in eerdere versies van dit document en zijn uit de codebase verwijderd:

- `TT_VISION_BEDROCK_REGION`, `TT_VISION_BEDROCK_ACCESS_KEY`, `TT_VISION_BEDROCK_SECRET_KEY` — er is geen Bedrock-pad.
- `TT_VISION_PHOTO_RETENTION_DAYS` — er is geen opgeslagen foto om te bewaren.

Foto-invoer volledig uitzetten:

```php
define( 'TT_VISION_PROVIDER', '' );  // lege tekst → resolveProvider() geeft null → alleen handmatig
```

Zie ook:

- `docs/i18n-architecture.md` — hoe de geëxtraheerde teksten door de vertaallaag lopen.
- De verwerkersvoorwaarden van de provider die de operator heeft ingesteld. Die opzoeken en toetsen is de verantwoordelijkheid van de operator, bij ondertekening en bij elke herziening — dit document noemt bewust geen voorwaarden van een provider meer, omdat het niet kan weten op welk endpoint een installatie is gericht.
