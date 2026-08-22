---
title: Meldingen
group: performance
summary: Situaties in je gegevens die aandacht vragen — niet afgeronde activiteiten, ontbrekende aanwezigheid, spelers zonder evaluatie — automatisch zichtbaar en opgelost zodra je ze aanpakt.
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

### Activiteiten

| Melding | Wat het betekent |
| --- | --- |
| **Activiteit uit het verleden staat nog op gepland** | De datum is voorbij, maar niemand heeft de activiteit afgerond of geannuleerd. Zolang dat niet gebeurt, ontbreken de aanwezigheid en de speelminuten in elk rapport. |
| **Aanwezigheid niet geregistreerd** | De activiteit staat op afgerond, maar niemand heeft ingevuld wie erbij was. Overal lijkt het klaar, behalve in de rapportages. |
| **Aankomende activiteit zonder trainer** | Voor een activiteit binnen een week is niemand ingedeeld. Activiteiten zonder trainer worden vaak laat afgezegd, en dat kost elke speler in de selectie een trainingsmoment. |

### Evaluaties

| Melding | Wat het betekent | Welke spelersvraag het beantwoordt |
| --- | --- | --- |
| **Speler recent niet geëvalueerd** | Er is voor een speler langer geen evaluatie vastgelegd dan de drempel van je academie. | *Waar staat deze speler nu?* Een academie die twee maanden niets over een speler heeft vastgelegd, kan die vraag niet beantwoorden — niet in een selectieoverleg, niet richting ouders, niet richting de speler zelf. |
| **Evaluatieperiode sluit bijna** | Een evaluatieperiode loopt bijna af en spelers in je team hebben daarin nog geen evaluatie. | *Waar staat deze speler nu, volgens de beoordeling die de academie beloofd heeft?* Zodra de periode sluit is het gat definitief: voor die periode is er simpelweg niets vastgelegd. |
| **Evaluatie niet gedeeld met de speler** | Er is een evaluatie vastgelegd, maar er is niets ingevuld in het veld dat de speler te zien krijgt. | *Wat heeft deze speler nu nodig?* Een evaluatie die de speler nooit ziet, kan die vraag voor hem niet beantwoorden. De scores en de interne notities blijven bij de staf, dus voor de speler is er niets gebeurd. |

### Doelen en PDP

| Melding | Wat het betekent | Welke spelersvraag het beantwoordt |
| --- | --- | --- |
| **Doel over de streefdatum** | Een ontwikkeldoel is voorbij de datum waarop het gehaald zou zijn en staat nog open. | *Wat heeft deze speler nu nodig?* Een doel over de datum dat nog openstaat is precies het onderdeel van het dossier dat die vraag zou moeten beantwoorden en dat niet meer doet. Of de speler is er, en niemand heeft het vastgelegd, of het plan moet worden bijgesteld. |
| **Geen PDP-gesprek deze cyclus** | Het PDP-dossier van een speler voor dit seizoen staat open, maar er is nog geen gesprek gevoerd. | *Waar gaat deze speler naartoe?* De PDP-cyclus is waar de academie belooft met de speler om tafel te gaan en dat samen vast te leggen. Een cyclus waarin alle gesprekken zijn ingepland en geen enkel gesprek is gevoerd, ziet er in elke lijst compleet uit en heeft de speler niets verteld. |

### Mensen

| Melding | Wat het betekent | Welke spelersvraag het beantwoordt |
| --- | --- | --- |
| **Speler wordt binnenkort 18** | De achttiende verjaardag van een speler valt binnen de aankondigingstermijn. | *Waar gaat deze speler naartoe?* Achttien worden verandert het papierwerk, niet het voetbal — toestemming van ouders is niet langer de grondslag om zijn gegevens te bewaren, een jeugdovereenkomst wordt misschien een contract, en de toegang van het ouderaccount wordt een keuze in plaats van vanzelfsprekend. Een maand te vroeg is makkelijk, een maand te laat is lastig. |
| **Ouder uitgenodigd maar nooit geactiveerd** | Een ouder is uitgenodigd, heeft nooit een account aangemaakt, en aan de speler hangt nog helemaal geen ouder. | *Waar komt deze speler vandaan, en wie thuis kan meekijken?* Een ouder zonder account kan de evaluaties niet lezen, de PDP-gesprekken niet zien en de club niets bevestigen over toestemming. |
| **Certificaat verloopt** | Een van je eigen certificaten verloopt binnenkort, of is net verlopen. | *Wat heeft deze speler nu nodig?* — van de andere kant bekeken. Elke speler in de selectie heeft er belang bij dat wie zijn training geeft daarvoor gekwalificeerd is. |

Meer meldingen — over metingen, datakwaliteit en aanmeldingen — volgen in latere releases. Ze komen module voor module, en elke release benoemt welke meldingen erbij komen — zie "Nieuwe meldingen staan meteen aan" hieronder.

### Instellingen die bepalen wanneer deze meldingen verschijnen

Deze staan in de academie-instellingen en niet in de code, omdat academies echt verschillen over wat "recent" betekent. Wie de verkeerde drempel heeft, gaat de melding niet meer vertrouwen.

| Instelling | Standaard | Wat het regelt |
| --- | --- | --- |
| `alerts_eval_stale_weeks` | 8 weken | Hoe lang een speler zonder evaluatie mag blijven voordat de melding verschijnt. Bij een speler die nog nooit geëvalueerd is, telt de teldatum vanaf de dag dat hij binnenkwam. |
| `alerts_eval_window_closing_days` | 3 dagen | Hoeveel dagen van tevoren je hoort dat een evaluatieperiode sluit. |
| `alerts_eval_share_grace_days` | 7 dagen | Hoe lang na het vastleggen van een evaluatie de melding "niet gedeeld" verschijnt. |
| `alerts_eval_share_lookback_days` | 60 dagen | Hoe ver terug die melding nog kijkt. Oudere evaluaties blijven met rust: in april horen wat je in september had moeten schrijven is een achterstand, geen actie. |
| `alerts_goal_overdue_grace_days` | 3 dagen | Hoeveel dagen na de streefdatum van een doel de melding verschijnt. Een doel dat je maandag bespreekt voor een deadline van zondag is normale praktijk. |
| `alerts_goal_overdue_lookback_days` | 365 dagen | Hoe lang na de streefdatum een doel nog de moeite waard is om achteraan te gaan. Daarna is het niet te laat maar opgegeven, en is opruimen de oplossing, geen melding. |
| `alerts_pdp_no_conversation_days` | 45 dagen | Hoe ver in een PDP-cyclus voordat "nog geen gesprek gevoerd" een melding wordt. |
| `alerts_player_turns_18_days` | 30 dagen | Hoeveel dagen van tevoren je hoort dat een speler achttien wordt. De leeftijd zelf is geen instelling: dat is een gegeven van het rechtsgebied waarin de academie werkt, geen voorkeur. |
| `alerts_parent_invite_stale_days` | 14 dagen | Hoe lang een ouderuitnodiging ongebruikt mag blijven voordat de melding verschijnt. |
| `alerts_staff_cert_expiring_days` | 60 dagen | Het venster rond vandaag voor de certificaatmelding. Het kijkt zowel vooruit als terug: een certificaat dat vorige week verliep is juist het meest urgent, en eentje dat een jaar geleden verliep "verloopt" niet meer maar vraagt een ander gesprek. |

## Nieuwe meldingen staan meteen aan

Als een release een melding toevoegt, geldt die direct voor iedereen die er iets aan kan doen — je hoeft hem niet zelf aan te zetten. Dat is bewust: een melding die niemand aanzet, is een melding die niemand ziet.

De rem zit erin dat nieuwe meldingen **module voor module** komen, nooit de hele catalogus in één keer, en dat de releasenotities steeds benoemen welke meldingen erbij komen en wat je ervan gaat zien. Twee nieuwe meldingen mét uitleg is informeren; twaalf zonder uitleg is spam van je eigen systeem.

## Wie een melding krijgt

De mensen die het daadwerkelijk kunnen oplossen: de hoofdtrainer van het team, plus wie er verder direct bij betrokken is — de trainer die aan de activiteit is gekoppeld, de trainer die de evaluatie schreef, wie het doel heeft gesteld, wie de uitnodiging heeft verstuurd.

**Certificaatmeldingen zijn de uitzondering en gaan alleen naar degene van wie het certificaat is.** Dat is iemands eigen beroepsdossier, geen selectie-informatie. Heeft een staflid geen account, dan is er niemand om het aan te melden en gaat er niets uit; daarvoor is het clubbrede overzicht van verlopende certificaten van het Hoofd Opleiding.

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
- Alle meldingen zijn ook beschikbaar via de REST API op `/wp-json/talenttrack/v1/alerts`.

## Meldingen op het record zelf

Een melding staat nu ook **bij het onderdeel waar hij over gaat**, als een klein gekleurd label: een aantal, een woord en een link.

- **De activiteitenlijst** — een label bij elke activiteit waar iets open staat.
- **De activiteit zelf** — hetzelfde label, in de kop.
- **De teampagina** — alles wat over dat team open staat.
- **De spelerspagina** — alles wat over die speler open staat, bovenaan de linkerkolom.

Het label vertelt in woorden hoeveel en hoe dringend. Nooit alleen met kleur, en nooit met een tooltip die je pas ziet als je er met de muis overheen gaat — op een telefoon bestaat dat niet.

Twee dingen zijn bewust zo gemaakt:

**Je kunt dit label niet uitzetten.** Elke andere plek waar meldingen verschijnen — de bel, de banner, de samenvatting per mail — komt straks onder je eigen instellingen te vallen. Dit label niet, want het is geen melding: het is de actuele toestand van het record, getekend naast dat record. Het verbergen zou betekenen dat je iemand de werkelijke staat van een regel onthoudt terwijl hij er recht naar kijkt.

**Je ziet alleen wat nog open staat.** Zodra je het opgelost hebt, verdwijnt het label. Er blijft niets bewaard. Op de spelerspagina in het bijzonder: afgehandelde meldingen zijn er gewoon niet meer, en **er wordt nooit iets van een melding in de tijdlijn van de speler gezet**. De tijdlijn is het verhaal van wat de speler is overkomen; een melding is een aantekening over wat een volwassene niet heeft ingevuld. Dat zijn twee verschillende dingen, en ze door elkaar halen zou "aanwezigheid is nooit ingevuld" in de ontwikkelgeschiedenis van een kind zetten, waar het niet thuishoort.

## De meldingenlijst

Alles wat open staat, op één plek, onder **Meldingen** (`?tt_view=alerts`). Filter op:

- **Onderdeel** — Activiteiten, Evaluaties, en wat er later bij komt.
- **Urgentie** — dringend, vraagt aandacht, ter informatie.
- **Status** — open, ongelezen, of recent opgelost.

Klik je op een label bij een record, dan land je hier meteen toegespitst op dat ene record; met "Alle meldingen tonen" zet je dat weer open.

## Het overzicht voor Hoofd Opleiding en beheerders

Dit is het overzicht dat eerdere releases aankondigden. Ben je verantwoordelijk voor meer dan één team, dan staat bovenaan de meldingenlijst een samenvatting per team: *"4 teams hebben records die aandacht vragen"*, met daaronder een regel per team met een aantal. Elke teamnaam opent dat team.

Je krijgt nog steeds niet per team een eigen melding, en dat is met opzet. Meldingen van twintig teams bij de persoon met de minste tijd om ze te lezen is precies hoe een systeem genegeerd raakt. Wat je in plaats daarvan krijgt is de vorm van het probleem — welke teams, hoeveel, hoe dringend — en een ingang naar het team dat je wilt bekijken.

De samenvatting telt alleen teams waar je al verantwoordelijk voor bent. Elk betrokken record telt één keer mee, ook als twee trainers er allebei bericht over kregen: er staat dus "drie niet-afgeronde activiteiten", nooit "zes".

## Voor beheerders (meldingen op records)

- Labels tonen op een lijst kost **één** databasequery voor de hele pagina, hoeveel regels er ook een label dragen. Elke plek die meldingen op een lijst toont moet ze in één keer ophalen; per regel ophalen is een fout, geen langzamere variant van hetzelfde.
- De samenvatting per team is een gegroepeerde leesactie over de meldingen die er al zijn. Er wordt niets aangemaakt, en juist dat maakt de regel "geen eigen melding per team voor het Hoofd Opleiding" houdbaar.
- Dezelfde filters zitten op de API: `GET /alerts?subject_type=activity&subject_id=12`, `GET /alerts?player_id=7`, en `GET /alerts/rollup` voor de samenvatting per team.
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
