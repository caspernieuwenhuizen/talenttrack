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

## Wat dit niet is

- **Het is geen afmelding.** Dit is de keuze van de academie, voor iedereen. De eigen voorkeuren van een gebruiker staan onder **Mijn instellingen → Berichten die je ontvangt**.
- **Het is geen kanaalschakelaar.** Wil je een heel kanaal uitzetten (sms bijvoorbeeld), gebruik dan **Modules → Communicatie**.
- **Het houdt geen veiligheidsberichten tegen.** Die zijn operationeel en worden altijd bezorgd.

## Zie ook

- [Modules](modules.md) — de module Communicatie en de kanalen aan- en uitzetten.
- [Configuratie — Algemeen](configuration-general.md) — de naam en het adres waarvandaan berichten verstuurd worden.
