---
title: Instroompijplijn
group: configuration
summary: De wervingstrechter waar elke binnenkomende speler doorheen gaat.
audience: [user]
views: [onboarding-pipeline, prospects-overview]
module: TT\Modules\Prospects\ProspectsModule
order: 124
---

# Aannamepijplijn

De **Aannamepijplijn** is de werving-trechter — elke speler die bij de academie binnenkomt, doorloopt deze. Open hem via de tegel op het dashboard (groep Academie) of direct via `?tt_view=onboarding-pipeline`.

## Wat zie je

Zeven kolommen naast elkaar, één per fase van de reis van "scout heeft hem gespot" tot "speelt voor de academie":

| Kolom | Wat erin zit |
|---|---|
| **Prospects** | Geconcept maar nog niet doorgegeven aan de Hoofd Ontwikkeling. Nieuwe inschrijvingen via de wizard slaan deze kolom over — die landen direct in *Uitgenodigd*. Wat hier staat, is óf een legacy `log_prospect`-conceptklus óf een keten die halverwege werd afgebroken. |
| **Toestemming gevraagd** | De academie heeft de eigen club van dit kind gevraagd het toestemmingsverzoek door te geven aan het gezin, en wacht op antwoord. |
| **Uitgenodigd** | De HoD stelt de uitnodiging voor de testtraining op of heeft hem verstuurd, of de bevestiging van de ouder is in afwachting. |
| **Testtraining** | De testtraining is gepland of heeft plaatsgevonden — de HoD legt de uitkomst vast. |
| **Trialgroep** | De prospect is in de trialgroep opgenomen en wordt daar beoordeeld. |
| **Teamaanbod** | Een coach heeft de prospect een plek in het team aangeboden; wachten op beslissing (ouder + speler). |
| **Aangesloten** | De prospect is in de afgelopen 90 dagen gepromoveerd tot een spelersrecord. |

Elke kolom toont een teller en een stapel kaartjes — één kaartje per prospect. Op kaartjes staan de naam, leeftijd (of geboortedatum), huidige club en een contextregel per fase. Klik op een kaartje om te openen wat nu actie vraagt voor die prospect (het openstaande takenformulier voor de actieve fase; het spelersprofiel voor wie al gepromoveerd is).

Een lichtoranje kaartje met een *stale*-badge betekent dat de openstaande klus voor die prospect meer dan 30 dagen over zijn deadline is.

## Een nieuwe prospect toevoegen

Klik op **+ Nieuwe prospect** bovenaan. De wizard loopt door:

1. **Identiteit** — voornaam / achternaam, geboortedatum, huidige club. Duplicaatdetectie draait hier — als er al een prospect met dezelfde naam bestaat, moet je het vinkje "dit is een nieuwe inschrijving" zetten voordat je verder kunt.
2. **Ontdekking** — waar je hem hebt gespot (evenement / wedstrijd), korte scoutnotities.
3. **Oudercontact** — naam, e-mail, telefoon. **Alles is optioneel.** Is het gezin nog niet benaderd, laat de hele stap dan leeg: een scout die een kind bij een andere club heeft gezien, heeft geen gezinsgegevens en mag die ook niet gaan ophalen. Wat níét versoepeld is, is de toestemmingsregel — vul je een contactgegeven in, dan moet je het toestemmingsvakje aanvinken. Je mag niets over een gezin vastleggen, of hun gegevens mét hun instemming; nooit hun gegevens zonder.
4. **Controleren** — bevestig de antwoorden en maak aan.

Bij verzenden:

- Het prospectrecord wordt aangemaakt.
- Er wordt een klus naar de Hoofd Ontwikkeling gestuurd om de prospect uit te nodigen voor een testtraining.
- Je komt terug op de pijplijnpagina, waar het nieuwe kaartje in de kolom **Uitgenodigd** verschijnt.

De wizard is het canonieke startpunt voor "+ Nieuwe prospect". Klikken op de knop opent hem en verder niets — er wordt geen workflow-klus als bijwerking aangemaakt.

## Rechten

- **`tt_view_prospects`** — vereist om de pijplijn te openen. Standaard toegekend aan Academy Admin, Hoofd Ontwikkeling, Scout en Hoofdtrainer.
- **`tt_edit_prospects`** — vereist om de Nieuwe prospect-wizard te starten. Toegekend aan Academy Admin, Hoofd Ontwikkeling en Scout — een hoofdtrainer leest de pijplijn, maar vult die niet aan.
- **`tt_invite_prospects`** — vereist om de klussen *Uitnodigen voor
  testtraining* en *Aanwezigheid testtraining bevestigen* af te ronden, en om
  vanuit de pijplijn een testtraining te regelen. Alleen toegekend aan Academy
  Admin en Hoofd Ontwikkeling.

De uitnodigingsklus toegewezen krijgen is op zichzelf niet genoeg om hem af te
ronden: het recht wordt óók gecontroleerd. Wie de klus heeft maar het recht
niet, kan hem nog wel openen en lezen, maar het formulier is vergrendeld en
draagt een melding met het verzoek een academy-beheerder het recht te laten
toekennen of de klus over te laten nemen. Dat is een wijziging — voorheen kon
de klus worden afgerond door wie hem toevallig had gekregen. De **hoofdtrainer**
is de persona die dit raakt: die leest de pijplijn en de testtrainingen van de
eigen selectie, maar het eerste bezoek van een kind aan de academie regelen is
een beslissing van de Hoofd Ontwikkeling.

De bevestigingslink voor de ouder verandert niet. Dat is een ondertekende
eenmalige URL die de bevestigingsklus afrondt zonder dat iemand inlogt, dus
daar komt geen personeelsrecht aan te pas.

### Wie welke prospects ziet

Het recht opent het bord. **Wat erop staat, hangt af van je bereik.**

- **Academy Admin, Hoofd Ontwikkeling en scouts** zien elke prospect in de
  academie. Scouts zagen vroeger alleen hun eigen vondsten; dat is veranderd
  toen twee scouts in dezelfde vijver elkaars bezoeken moesten kunnen zien.
- **Een hoofdtrainer** ziet de pijplijn die de eigen selecties voedt: prospects
  die voor een van de eigen **leeftijdsgroepen** zijn vastgelegd, iedereen die
  al naar een van de eigen teams is doorgestroomd, en alles wat de trainer zelf
  heeft vastgelegd.

Een prospectrecord draagt een leeftijdsgroep, geen team — ze zijn nog niet
toegetreden, dus er is nog geen selectie om bij te horen. Een prospect zonder
leeftijdsgroep en zonder doorstroom is alleen zichtbaar voor de academiebrede
rollen en voor wie de prospect heeft vastgelegd. Zegt een hoofdtrainer dat een
prospect ontbreekt op het bord, kijk dan eerst naar de leeftijdsgroep op het
prospectrecord.

De aantallen op de dashboardtegel volgen precies dezelfde regel, dus tegel en
bord komen altijd overeen.

## Faseregels

Elke prospect hoort in **precies één** kolom. De classifier loopt in deze volgorde:

1. Gepromoveerd tot speler in de laatste 90 dagen → **Aangesloten**.
2. Heeft een openstaande klus *Wachten op teambeslissing* → **Teamaanbod**.
3. Is opgenomen in een trialgroep → **Trialgroep**.
4. Heeft een openstaande klus *Uitkomst testtraining vastleggen* → **Testtraining**.
5. Heeft een openstaande klus *Uitnodigen voor testtraining* of *Bevestiging testtraining* → **Uitgenodigd**.
6. Heeft een openstaande klus *Toestemming vragen aan het gezin* → **Toestemming gevraagd**.
7. Anders (geen openstaande klus, niet gepromoveerd, niet gearchiveerd) → **Prospects**.

Regel 6 staat bewust ónder de uitnodigingsregels: wie eenmaal is uitgenodigd, is duidelijk voorbij de toestemming, wat een blijven hangen toestemmingsklus ook nog beweert.

De dashboardwidget gebruikt dezelfde classifier voor zijn compacte tellerstrip, dus de getallen op het dashboard kloppen met de kolommen op de standalone pagina. Een prospect telt één keer, in één kolom, hoeveel klussen er ook openstaan.

## Het gezin vragen, en vastleggen dát je het gevraagd hebt

Tussen "ik heb een kind bij een andere club gezien" en "het gezin heeft ja gezegd" zit een echte stap: de eigen club van het kind vragen het verzoek door te geven. Die stap heeft nu een eigen plek.

**De klus.** *Toestemming vragen aan het gezin* is een workflow-klus, toegewezen aan de scout die de prospect vond, met een deadline van 21 dagen. Zolang die openstaat, staat de prospect in de kolom **Toestemming gevraagd**. Er volgt niets automatisch op: een verzoek dat *afgewezen* terugkomt, mag geen uitnodiging opleveren.

**Het logboek.** Het afronden van de klus, of een `POST /prospects/{id}/consent-requests`, schrijft een gedateerde regel: de datum waarop je het vroeg, de club of coördinator die je vroeg, de uitkomst en optionele notities. Alle regels van een prospect staan op het focuspaneel als je op het kaartje klikt, nieuwste eerst. Dat spoor is het antwoord op "zijn de toestemmingsverzoeken eruit gegaan?", dat tot nu toe alleen in het hoofd van een scout zat.

Vier uitkomsten: *wacht op antwoord*, *het gezin ging akkoord*, *het gezin wees af*, *geen reactie*.

**Het logboek bevat niets over het gezin.** Geen naam, geen e-mail, geen telefoon, geen adres — alleen de route die de academie gebruikte. Dat is precies waar de stap voor bestaat: de academie ging via de club van het kind en legde geen gezinsgegevens vast voordat dat mocht. Oudercontact hoort op het prospectrecord, achter de toestemmingsregel die het altijd al beschermde.

**Een openstaand verzoek zet de bewaarklok stil.** Een prospect zonder voortgang wordt na 90 dagen verwijderd. Een regel met de uitkomst *wacht op antwoord* telt als voortgang, zodat een academie die echt zit te wachten het kind niet onder zich vandaan verliest. De klok loopt vanaf die regel, niet vanaf de prospect, dus een verzoek dat nooit is nagebeld verjaart alsnog volgens de normale regel. Wordt een prospect verwijderd, dan gaan de toestemmingsregels mee.

**Een wachttijd die oploopt wordt hardop gezegd.** De talentenlijst heeft een kolom **Toestemming wacht** met het aantal dagen dat elk openstaand verzoek al wacht, en na vijf dagen — de instelling `alerts_prospect_consent_awaiting_days` van je academie — gaat er een melding naar wie talenten mag bewerken, met de naam van de club die is gevraagd. Elke vastgelegde uitkomst ruimt de melding direct op. Ze bestaat vanwege de alinea hierboven: zonder haar was de waarschijnlijkste uitkomst van een verzoek dat niemand najoeg dat het dossier van het kind stil werd verwijderd, terwijl de wachttijd nergens te zien was. Zie *Toestemmingsverzoek wacht nog* in het onderwerp Meldingen.

## Geen uitnodiging zonder toestemming

*Uitnodigen voor testtraining* weigert te verzenden zolang er geen toestemming vastligt — óf een toestemmingsdatum op de prospect, óf een toestemmingsverzoek dat *akkoord* terugkwam.

**Er is geen uitzondering en geen omweg.** Een kind van wie het gezin niet akkoord is, wordt niet uitgenodigd voor een testtraining, en er is geen knop die iets anders zegt. Kwam de toestemming echt binnen langs een route die het systeem niet kent — een gesprek langs de lijn, een antwoord op de mail van een club — dan is de weg vooruit: **vastleggen**, op de prospect of als toestemmingsregel met uitkomst akkoord, en dán de uitnodiging versturen. Het afronden van de uitnodigingsklus is wat een prospect naar *Uitgenodigd* brengt, dus dit is dezelfde naad als de faseovergang.

## Een vastgelopen prospect vlot trekken

Een prospect bereikt **Uitgenodigd** pas als iemand de klus **Uitnodigen voor testtraining** heeft, en die klus ontstond maar op één plek: op het moment dat een prospect via de wizard werd vastgelegd. Een prospect van wie de keten is afgebroken, die is vastgelegd terwijl de pijplijn-workflow uit stond, die is geïmporteerd of die door de demogenerator is aangemaakt, bleef dus in de eerste kolom staan zonder iets om op te klikken.

Klik je op zijn kaart, dan biedt het paneel boven het bord nu een weg vooruit:

- **Testtraining voorstellen** — voor een scout, of iedereen die wel aan de trechter mag toevoegen maar de uitnodiging niet mag versturen. Het vraagt de Hoofd Opleidingen er een te regelen. De volgende actie van de prospect wordt meteen *Uitnodigen voor testtraining*, en de kaart schuift naar **Uitgenodigd** zodra de Hoofd Opleidingen hem verstuurt.
- **Testtraining regelen** — voor de Hoofd Opleidingen en iedereen met `tt_invite_prospects`. Die hoeft zichzelf niets te vragen, dus de knop brengt hem naar het formulier Nieuwe testtraining in plaats van een klus aan te maken. **De prospect gaat mee**: het formulier opent met dat kind al gekozen, en bij opslaan wordt de uitnodiging op naam vastgelegd.

### De prospect op het formulier Nieuwe testtraining

**Configuratie → Testtrainingen → Nieuw**, of de knop *Testtraining regelen* in de pijplijn, wat hetzelfde formulier is op `?tt_view=test-trainings&action=new&prospect_id=…`.

- **Kom je van het kaartje van een prospect**, dan opent het veld **Prospect** met dat kind geselecteerd. Sla je op, dan gaat het naar **Uitgenodigd** — precies alsof de klus *Uitnodigen voor testtraining* was afgerond, want dat is wat eronder wordt vastgelegd.
- **Kom je er koud binnen**, dan is het veld een gewone keuzelijst op *Nog niemand*. Een testtraining inplannen en er later kinderen aan koppelen is heel normaal, dus hier is niets verplicht.
- **Een id dat niets oplevert** — een vertikte URL, een prospect die iemand anders heeft vastgelegd en die jij niet mag zien, of een prospect die al is doorgestroomd of gearchiveerd — opent het veld leeg en zegt verder niets. Het vertelt bewust niet of die prospect bestaat.
- De keuzelijst toont alleen prospects die jij mag uitnodigen. Zonder `tt_invite_prospects` verschijnt het veld helemaal niet, en plant het formulier nog steeds een testtraining in zonder dat er iemand aan hangt.
- **De toestemmingsregel geldt hier ook.** Een kind koppelen van wie het gezin niet akkoord is, wordt geweigerd met dezelfde melding als bij de uitnodigingsklus, en er wordt niets opgeslagen — ook de testtraining niet.

De twee routes komen samen: of de uitnodiging nu vanuit de klus of vanuit dit formulier is geregeld, wat er blijft staan is dezelfde afgeronde klus *Uitnodigen voor testtraining*, zodat het bord, de faseclassifier en de rapportages één vorm van hetzelfde feit lezen.

Twee keer voorstellen doet de tweede keer niets, en twee scouts die dezelfde prospect voorstellen leveren samen één verzoek op — de Hoofd Opleidingen wordt één keer over een kind gevraagd.

De knop verschijnt alleen als er niets anders te doen is: een prospect met een openstaande pijplijnklus, een die al is uitgenodigd, een die is gepromoveerd naar een speler of een proefperiode, en een gearchiveerde tonen allemaal hun eigen volgende actie. Staat de feature `onboarding_pipeline_workflow` uit, dan is er helemaal geen knop, want er zou geen klus aangemaakt kunnen worden.

## Wat de wizard overslaat

De legacy-keten startte met een `LogProspectTemplate`-klus en gaf daarna door aan `InviteToTestTrainingTemplate`. De wizard *is* het formulier dat de LogProspect-klus omhulde, dus die klus aanmaken om data te vragen die de wizard al verzamelde, was een overbodige stap. De wizard gaat direct naar `InviteToTestTrainingTemplate`.

`LogProspectTemplate` en het `/prospects/log` REST-endpoint blijven bestaan voor backward compat — externe integraties (bijv. het ouder-zelfbevestigingstoken) en elke custom workflow-trigger die ze aanroept blijven werken.

## Een talent vastleggen van buiten TalentTrack

`POST /wp-json/talenttrack/v1/prospects` legt een talent direct vast: voor- en achternaam (verplicht), geboortedatum, huidige club, waar je hem zag, je aantekeningen, het scoutingbezoek waar hij is gevonden, het contactblok van de ouder met bijbehorende toestemming, en `duplicate_override`. Het endpoint antwoordt met **201** en het id van het talent, en het talent telt daarna mee op zijn scoutingbezoek, net als een talent dat via de wizard is aangemaakt. Het vraagt hetzelfde recht als de wizard en zet dezelfde vervolgklus voor het Hoofd Opleiding open.

**`prospects/log` is iets anders en verdwijnt niet.** Dat endpoint maakt geen talent aan — het opent een klus *Talent vastleggen* voor de aanroeper en antwoordt met een `task_id`. Externe integraties en de zelfbevestiging door de ouder gebruiken dat, dus het blijft. Wil je een talentdossier, post dan naar `/prospects`.

De wizard, de klus *Talent vastleggen* en dit endpoint leggen alle drie vast via één aanmaakservice, dus ze gebruiken dezelfde veldindeling en dezelfde dubbelcheck. Een waarschijnlijke dubbele komt terug als **409** met de kandidaten en `duplicate_override: false`; post opnieuw met `duplicate_override: true` zodra iemand ernaar heeft gekeken. Dat spiegelt de wizard met opzet — twee kinderen kunnen echt dezelfde naam hebben, en de check bestaat om iemand te laten kijken, niet om de tweede onvastlegbaar te maken.
