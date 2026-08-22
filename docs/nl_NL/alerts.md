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

Meer meldingen — over doelen, PDP-cycli, metingen en spelersdossiers — volgen in latere releases. Ze komen module voor module, en elke release benoemt welke meldingen erbij komen — zie "Nieuwe meldingen staan meteen aan" hieronder.

### Instellingen die bepalen wanneer deze meldingen verschijnen

Deze staan in de academie-instellingen en niet in de code, omdat academies echt verschillen over wat "recent" betekent. Wie de verkeerde drempel heeft, gaat de melding niet meer vertrouwen.

| Instelling | Standaard | Wat het regelt |
| --- | --- | --- |
| `alerts_eval_stale_weeks` | 8 weken | Hoe lang een speler zonder evaluatie mag blijven voordat de melding verschijnt. Bij een speler die nog nooit geëvalueerd is, telt de teldatum vanaf de dag dat hij binnenkwam. |
| `alerts_eval_window_closing_days` | 3 dagen | Hoeveel dagen van tevoren je hoort dat een evaluatieperiode sluit. |
| `alerts_eval_share_grace_days` | 7 dagen | Hoe lang na het vastleggen van een evaluatie de melding "niet gedeeld" verschijnt. |
| `alerts_eval_share_lookback_days` | 60 dagen | Hoe ver terug die melding nog kijkt. Oudere evaluaties blijven met rust: in april horen wat je in september had moeten schrijven is een achterstand, geen actie. |

## Nieuwe meldingen staan meteen aan

Als een release een melding toevoegt, geldt die direct voor iedereen die er iets aan kan doen — je hoeft hem niet zelf aan te zetten. Dat is bewust: een melding die niemand aanzet, is een melding die niemand ziet.

De rem zit erin dat nieuwe meldingen **module voor module** komen, nooit de hele catalogus in één keer, en dat de releasenotities steeds benoemen welke meldingen erbij komen en wat je ervan gaat zien. Twee nieuwe meldingen mét uitleg is informeren; twaalf zonder uitleg is spam van je eigen systeem.

## Wie een melding krijgt

De mensen die het daadwerkelijk kunnen oplossen: de hoofdtrainer van het team, plus wie er verder direct bij betrokken is — de trainer die aan de activiteit is gekoppeld, of de trainer die de evaluatie schreef.

Hoofden Opleiding en beheerders krijgen **niet** voor elk team een melding. Meldingen van twintig teams bij de persoon met de minste tijd om ze te lezen is precies hoe een systeem genegeerd raakt. Een overzicht voor die rol volgt in een latere release.

Je krijgt bovendien alleen meldingen over records die je mag zien. Dat wordt elk uur opnieuw gecontroleerd, dus een trainer die van team wisselt krijgt de meldingen van dat team vanzelf niet meer.

## Waarom een melding soms een uur blijft staan

TalentTrack controleert elke situatie één keer per uur, op de achtergrond. Dat is een bewuste keuze: alles controleren tijdens het laden van je dashboard zou inloggen voor iedereen trager maken, en bij elk nieuw meldingstype nog trager.

Rond je om 10:15 een activiteit af, dan kan de melding er tot de volgende controle nog staan. Hij verdwijnt vanzelf. Je hoeft niets te doen, en je kunt het ook niet versnellen.

## Meldingen uitzetten

Nog niet. Instellingen per persoon en per club — kiezen welke meldingen je ziet en waar — komen in een latere release. Tot die tijd krijgt iedereen die iets kan oplossen daar bericht over.

## Voor beheerders

- Meldingen worden bijgewerkt door een achtergrondtaak die elk uur draait. Verschijnt er nooit iets, controleer dan of de geplande taken van WordPress op deze site werken.
- Bij een nieuwe installatie draait de controle direct bij activering, zodat het dashboard meteen een kloppend beeld toont.
- Alle meldingen zijn ook beschikbaar via de REST API op `/wp-json/talenttrack/v1/alerts`.
