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

Alles staat standaard aan, en een berichtsoort die in een latere versie bijkomt komt aan staan binnen. Iets uitzetten is altijd een bewuste keuze.

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

## Wat dit niet is

- **Het is geen afmelding.** Dit is de keuze van de academie, voor iedereen. De eigen voorkeuren van een gebruiker staan onder **Mijn instellingen → Berichten die je ontvangt**.
- **Het is geen kanaalschakelaar.** Wil je een heel kanaal uitzetten (sms bijvoorbeeld), gebruik dan **Modules → Communicatie**.
- **Het houdt geen veiligheidsberichten tegen.** Die zijn operationeel en worden altijd bezorgd.

## Zie ook

- [Modules](modules.md) — de module Communicatie en de kanalen aan- en uitzetten.
- [Configuratie — Algemeen](configuration-general.md) — de naam en het adres waarvandaan berichten verstuurd worden.
