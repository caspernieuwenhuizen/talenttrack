---
title: Meldingen
group: performance
summary: Situaties in je gegevens die aandacht vragen — niet afgeronde activiteiten, ontbrekende aanwezigheid, activiteiten zonder trainer — automatisch zichtbaar en opgelost zodra je ze aanpakt.
audience: [user, admin]
order: 35
---

# Meldingen

Een **melding** wijst je op iets in je gegevens dat op dit moment waar is en aandacht vraagt. Een training van vorige dinsdag die nog op gepland staat. Een afgeronde training waarvan niemand de aanwezigheid heeft ingevuld. Een activiteit donderdag zonder trainer.

Je vinkt een melding nooit af. Je lost op waar de melding over gaat, en de melding verdwijnt vanzelf.

## Meldingen zijn geen taken

TalentTrack kent twee dingen die op je kunnen wachten, en ze gedragen zich bewust verschillend:

| | Taak | Melding |
| --- | --- | --- |
| Wat het is | werk dat aan jou is toegewezen | een situatie die nu waar is |
| Hoe het eindigt | jij rondt het af | het is niet langer waar |
| Wie het krijgt | één persoon | iedereen die het kan oplossen |
| Waar je het oplost | in de taak zelf | in het gewone scherm van dat record |

Een taak is "schrijf de wedstrijdevaluatie voor Daan". Een melding is "deze activiteit staat nog op gepland". Als meldingen taken waren, zou je de activiteit in het activiteitenoverzicht afronden en daarna nog steeds een taak in je inbox hebben die zegt dat je dat moet doen.

## Waar je meldingen ziet

- **De bel**, rechtsboven. Het getal telt nu zowel je open taken als je open meldingen.
- **Een balk** bovenaan het dashboard met de meldingen die het meest aandacht vragen.

Elke melding linkt rechtstreeks naar het record waar het over gaat, dus oplossen is één klik.

## Waar TalentTrack nu op meldt

| Melding | Wat het betekent |
| --- | --- |
| **Activiteit uit het verleden staat nog op gepland** | De datum is voorbij, maar niemand heeft de activiteit afgerond of geannuleerd. Zolang dat niet gebeurt, ontbreken de aanwezigheid en de speelminuten in elk rapport. |
| **Aanwezigheid niet geregistreerd** | De activiteit staat op afgerond, maar niemand heeft ingevuld wie erbij was. Overal lijkt het klaar, behalve in de rapportages. |
| **Aankomende activiteit zonder trainer** | Voor een activiteit binnen een week is niemand ingedeeld. Activiteiten zonder trainer worden vaak laat afgezegd, en dat kost elke speler in de selectie een trainingsmoment. |

Meer meldingen — over evaluaties, doelen, PDP-cycli en spelersdossiers — volgen in latere releases.

## Wie een melding krijgt

De mensen die het daadwerkelijk kunnen oplossen: de trainer die aan de activiteit is gekoppeld, en de hoofdtrainer van het team.

Hoofden Opleiding en beheerders krijgen **niet** voor elk team een melding. Meldingen van twintig teams bij de persoon met de minste tijd om ze te lezen is precies hoe een systeem genegeerd raakt. Een overzicht voor die rol volgt in een latere release.

Je krijgt bovendien alleen meldingen over records die je mag zien. Dat wordt elk uur opnieuw gecontroleerd, dus een trainer die van team wisselt krijgt de meldingen van dat team vanzelf niet meer.

## Waarom een melding soms een uur blijft staan

TalentTrack controleert elke situatie één keer per uur, op de achtergrond. Dat is een bewuste keuze: alles controleren tijdens het laden van je dashboard zou inloggen voor iedereen trager maken, en bij elk nieuw meldingstype nog trager.

Rond je om 10:15 een activiteit af, dan kan de melding er tot de volgende controle nog staan. Hij verdwijnt vanzelf. Je hoeft niets te doen, en je kunt het ook niet versnellen.

## Kiezen welke meldingen je ziet

**Account → Meldingsinstellingen** toont elke melding, gegroepeerd per onderdeel van het systeem, met een vinkje per plek waar hij kan verschijnen:

- **In de bel** — meegeteld in het getal rechtsboven.
- **Balk op het dashboard** — een balk bovenaan de pagina.

Vink alles uit en je ziet die melding niet meer. Meldingen die je niet kunt wijzigen staan grijs met de reden erbij — je academie heeft het voor iedereen bepaald, of de melding gaat over de veiligheid van een kind en staat altijd aan. Ze blijven bewust zichtbaar: een instellingenlijst die stilletjes weglaat wat je niet kunt wijzigen, laat je denken dat de lijst compleet is terwijl dat niet zo is.

Berichten die de academie je *stuurt* — e-mails, pushberichten — staan apart, onder **Account → Instellingen → Berichten die je ontvangt**. Beide schermen verwijzen naar elkaar.

### Eén melding tijdelijk uitstellen

Heb je op dit moment niets aan een melding, stel hem dan een dag, een week of een maand uit. Hij verdwijnt en komt daarna terug, als het probleem dan nog bestaat.

### Eén melding wegklikken

Wegklikken verwijdert hem definitief — maar **alleen die ene keer**. Wordt hetzelfde probleem opgelost en gebeurt het daarna opnieuw, dan krijg je een nieuwe melding, want dat is echt nieuwe informatie. Wil je een *soort* melding permanent stoppen, vink hem dan uit bij Meldingsinstellingen.

## Voor beheerders

**Meldingsbeleid** (Instellingen → Meldingsbeleid) bepaalt wie welke melding beheert:

- **Iedereen kiest zelf** — de standaard, en voor bijna alles de juiste keuze.
- **Altijd aan voor iedereen** — niemand kan hem uitzetten. Gebruik dit als een melding te belangrijk is om optioneel te zijn.
- **Uit voor de hele club** — niemand ziet hem en er worden geen gegevens bewaard. Gebruik dit voor onderdelen die jullie academie niet gebruikt. Meldingen over de veiligheid van een kind kunnen niet uit.

Twee instellingen die alleen een beheerder kan zetten:

- **Mensen moeten dit eerst bevestigen** — de melding blokkeert de pagina tot iemand bevestigt dat hij hem gezien heeft. Reserveer dit voor echt ernstige gevallen; een onderbreking die mensen dagelijks zien wordt weggeklikt zonder gelezen te worden.
- **Omzetten in een taak na (dagen)** — hoe lang een genegeerde melding wacht voordat er een echte toegewezen taak van wordt. Laat leeg voor de ingebouwde standaard. Dit werkt zodra taakescalatie beschikbaar is.

Verder:

- Meldingen worden bijgewerkt door een achtergrondtaak die elk uur draait. Verschijnt er nooit iets, controleer dan of de geplande taken van WordPress op deze site werken.
- Bij een nieuwe installatie draait de controle direct bij activering, zodat het dashboard meteen een kloppend beeld toont.
- Een melding uitzetten voor de club ruimt ook op wat er al gemeld was, in plaats van rijen te laten staan die niemand meer kan zien.
- Alle meldingen zijn ook beschikbaar via de REST API op `/wp-json/talenttrack/v1/alerts`, samen met `/alerts/preferences` en `/alerts/policy`.

## Meldingen per e-mail ontvangen

Open je TalentTrack niet vaak, dan kun je je openstaande meldingen als samenvattingsmail laten sturen.

Dit staat **uit tot je het zelf aanzet**. Niemand wordt automatisch aangemeld — de app toont je meldingen in de bel en op het dashboard, maar mailt je pas als je erom vraagt.

Aanzetten doe je door bij **Account → Meldingsinstellingen** per melding **In de samenvattingsmail** aan te vinken.

Waar je op kunt rekenen:

- Je krijgt dezelfde melding nooit twee keer. Een melding blijft open tot het onderliggende is opgelost, en zonder dit zou je elke ochtend dezelfde drie punten krijgen.
- Er wordt niets gemaild dat je in de app al gelezen, uitgesteld of weggeklikt hebt.
- Is er niets te melden, dan wordt er geen mail verstuurd.
- Elke regel linkt rechtstreeks naar het record dat aandacht vraagt, niet naar een lijst.

## Hoe lang meldingen bewaard blijven

Een melding die is opgelost blijft **90 dagen** bewaard en wordt daarna verwijderd. Meldingen die nog openstaan worden nooit verwijderd, hoe oud ook — een melding waar een jaar lang niets mee gedaan is, wil je juist zien.

Dat betekent dat het meldingssysteem geen vragen kan beantwoorden die verder terugkijken dan ongeveer een kwartaal. Gebruik voor patronen over een heel seizoen de rapportages; die lezen de onderliggende gegevens in plaats van de meldingen erover.
