---
title: Configuratie — Berichten
group: configuration
summary: Welke berichten jouw academie verstuurt, met een schakelaar per berichtsoort.
audience: [admin]
views: [mail-compose]
module: TT\Modules\Comms\CommsModule
order: 115
---

# Configuratie — Berichten

**Dashboard → Configuratie → Berichten** (`?config_sub=messages`)

Welke berichten jouw academie verstuurt. Elk uitgaand bericht in TalentTrack — een afgelaste training, een selectiebesluit, een herinnering om een doel bij te werken — komt uit een template met een naam, en op deze pagina staan ze allemaal met een schakelaar erbij.

De lijst wordt opgebouwd uit de templates die de plugin heeft geregistreerd, dus een berichtsoort die in een latere versie bijkomt verschijnt hier vanzelf. Vereist `tt_edit_feature_toggles`; de instelling wordt per club opgeslagen in `tt_config`, zodat een toekomstige multi-tenant installatie de keuzes van elke academie gescheiden houdt.

## Een bericht uitzetten

Vink een bericht uit en sla op. Vanaf dat moment:

- Wordt het **naar niemand verstuurd**, via geen enkel kanaal — e-mail, push, WhatsApp-link of in-app.
- Wordt het **nog steeds vastgelegd** in het berichtenlogboek, met de status *uitgezet*. Je ziet dus dat een bericht verstuurd zou zijn en dat niet is — dat telt op het moment dat iemand vraagt waarom een gezin niets gehoord heeft.
- Krijgt iemand die het handmatig probeert te versturen **vooraf** te zien dat het uitstaat, en een foutmelding in plaats van een stille bevestiging als er alsnog verstuurd wordt.

## Waarmee een gloednieuwe academie begint

**Er wordt niets verstuurd.** Een academie die TalentTrack voor het eerst installeert begint met elk bericht op deze pagina uitgezet, en dat blijft zo tot iemand een keuze maakt.

Dat is bewust zo. Dit zijn berichten aan de ouders van minderjarigen, en "standaard aan" is een keuze die niemand gemaakt heeft. De installatiewizard vraagt welke berichten jouw academie wil, zodat het eerste bericht dat een ouder krijgt er een is die jullie zelf gekozen hebben. Heb je die stap overgeslagen, dan maak je de keuze hier — en tot dat moment hoort niemand iets, ook niet over een afgelaste training.

**Academies die al draaiden merken hier niets van.** Bijwerken naar de versie waarin dit is ingevoerd verandert niets: elk bericht dat eerst verstuurd werd, wordt daarna nog steeds verstuurd. De nieuwe standaard geldt voor installaties vanaf die versie, nooit met terugwerkende kracht.

## Wat er gebeurt met een berichtsoort uit een latere versie

Een nieuwe berichtsoort komt **aan** staan binnen bij een academie die al bestond, en **uit** bij een academie die pas na die versie is geïnstalleerd.

Dat komt doordat deze pagina de lijst bewaart van berichten die je hebt **uitgezet**, nooit de lijst die je hebt aangezet. Een berichtsoort die nog niemand gezien heeft staat op niemands uit-lijst, dus gedraagt hij zich zoals de rest van jouw installatie: hij verstuurt. Bij een nieuwe installatie wordt die uit-lijst weggeschreven zodra de plugin voor het eerst geactiveerd wordt, met de berichtsoorten die op dat moment bestaan — wat later bijkomt sluit op dezelfde manier aan.

Praktisch gevolg: kijk na een update even op deze pagina als een releasenotitie een nieuwe berichtsoort noemt. Die staat dan al aan.

## Alles wat de academie verstuurt staat op deze lijst

Elke uitgaande e-mail komt nu uit een berichtsoort met een naam, ook de berichten die vroeger op eigen houtje de deur uit gingen:

- **Herinnering stage-input** — port toegewezen medewerkers die hun input op een stagedossier nog niet hebben ingevuld.
- **Levering gepland rapport** — de analyse-export, met het bestand als bijlage.
- **E-mail geschreven door een medewerker** — alles wat via de opsteller in het product wordt getypt.
- **Spelersrapport voor een scout** — de vertrouwelijke eenmalige link naar buiten de academie.
- **Desktoplink die je hebt aangevraagd** — de knop "Mail mij de link" op de melding voor desktop-only pagina's.
- **Meldingen in het product** — nieuwe berichten in een gesprek, toegewezen taken, updates op ingediende ideeën.

Doordat ze op de lijst staan, volgen ze allemaal de schakelaar hierboven, respecteren ze de afmelding van een persoon, wachten ze op het einde van de stille uren en laten ze een regel achter in het berichtenlogboek. Daarvoor gold dat voor geen van deze berichten.

Twee e-mails blijven hier bewust buiten:

- **Wachtwoord herstellen.** Een afmelding, stille uren of een uitgezette schakelaar zouden iemand buitensluiten uit het eigen account, zonder manier om een nieuwe link te vragen.
- **Levering van back-ups.** Dat is een bestand naar degene die je back-ups bewaart, geen bericht over een persoon, en het mag nooit worden tegengehouden.

## Accountmail staat niet op deze lijst

De **uitnodigingsmail** — de mail met de link waarmee een ouder, speler of medewerker een wachtwoord instelt en voor het eerst inlogt — staat hier niet bij en heeft geen schakelaar.

Het is geen bericht dat jouw academie kiest te versturen over een speler. Het is de manier waarop iemand überhaupt een account krijgt. Een schakelaar ervoor zou eruitzien als een keuze over berichten en werken als een storing in de aanmelding: wie hem uitvinkt legt het verband niet tussen "we hebben een bericht uitgezet" en "nieuwe ouders kunnen niet inloggen", omdat dat niet op hetzelfde lijkt.

Daarom staat hij er niet — niet aangevinkt en op slot, maar helemaal afwezig. Hij gaat de deur uit omdat iemand een persoon heeft uitgenodigd, en dat is de enige voorwaarde. Verder verandert er niets aan: hij wordt nog steeds vastgelegd in het berichtenlogboek, de ontvanger wordt op dezelfde manier bepaald, en de eigen voorkeuren van een persoon blijven gelden waar dat wettelijk hoort.

Had jouw academie de uitnodigingsmail eerder uitgevinkt, dan heeft die keuze geen effect meer en wordt hij opgeruimd zodra je deze pagina de volgende keer opslaat.

Wachtwoord herstellen werkt om dezelfde reden zo, en heeft nooit op deze lijst gestaan.

## Wat dit niet is

- **Het is geen afmelding.** Dit is de keuze van de academie, voor iedereen. De eigen voorkeuren van een gebruiker staan onder **Mijn instellingen → Berichten die je ontvangt**.
- **Het is geen kanaalschakelaar.** Wil je een heel kanaal uitzetten (sms bijvoorbeeld), gebruik dan **Modules → Communicatie**.
- **Het houdt geen veiligheidsberichten tegen.** Die zijn operationeel en worden altijd bezorgd.

## Zie ook

- [Modules](modules.md) — de module Communicatie en de kanalen aan- en uitzetten.
- [Configuratie — Algemeen](configuration-general.md) — de naam en het adres waarvandaan berichten verstuurd worden.
