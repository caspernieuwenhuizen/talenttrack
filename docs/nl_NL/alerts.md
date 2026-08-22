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

## Meldingen uitzetten

Nog niet. Instellingen per persoon en per club — kiezen welke meldingen je ziet en waar — komen in een latere release. Tot die tijd krijgt iedereen die iets kan oplossen daar bericht over.

## Voor beheerders

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
