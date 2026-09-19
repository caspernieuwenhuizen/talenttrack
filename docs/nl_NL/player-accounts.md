---
title: Spelersaccounts
group: configuration
summary: Koppel een spelersdossier aan het inlogaccount dat bij die speler hoort.
audience: [admin]
views: [accounts, player-accounts, parent-accounts]
order: 126
---

# Spelersaccounts

De weergave **Spelersaccounts** (`?tt_view=player-accounts`) is waar een
academiebeheerder een speler aan een login op de site koppelt. Het is de
primaire manier om een speler (en daarmee zijn gegevens) een account te
geven; uitnodigingen blijven het secundaire, zelfbedieningspad.

Bereik de weergave via de dashboardtegel **Spelersaccounts**, of via de
knop **Spelersaccounts** op de Spelerslijst.

## Wat je ziet

Een lijst van elke speler in je academie, met per rij:

- De naam en foto van de speler (het ankerpunt van de rij) plus team en
 leeftijdscategorie.
- Een **accountstatus**:
 - **Geen account** — nog niemand gekoppeld.
 - **Uitgenodigd (in behandeling)** — een uitnodiging is verstuurd maar
 nog niet geaccepteerd.
 - **Gekoppeld** — een WordPress-account is verbonden (de accountnaam
 wordt getoond).

Filter op status of zoek op spelersnaam met de bedieningselementen boven
de lijst.

## Koppelen en ontkoppelen

- **Een bestaande gebruiker koppelen.** Kies op een rij *Geen account* (of
 *Uitgenodigd*) een account in de keuzelijst **Kies account** en druk op
 **Koppelen**. De keuzelijst toont alleen accounts die nog niet aan een
 andere speler of aan een staf-/ouderrecord zijn gekoppeld, zodat je één
 login niet dubbel kunt boeken. Bij koppelen krijgt dat account ook de
 spelersrol.
- **In plaats daarvan uitnodigen.** Gebruik **Uitnodiging genereren /
 Uitnodiging delen** op dezelfde rij om de speler (of zijn ouder) een
 zelfaanmeldlink te sturen.
- **Direct een nieuw account aanmaken.** Op de weergave **Ouderaccounts**
 maakt het paneel *Een nieuw ouderaccount aanmaken* een gloednieuw account
 aan (naam + e-mail), koppelt het aan de gekozen speler en mailt de persoon
 een link om een **wachtwoord in te stellen** - jij ziet of stelt nooit een
 wachtwoord in. Voor het zeldzame geval zonder bruikbaar e-mailadres vink je
 *Geen bruikbaar e-mailadres* aan om een tijdelijk wachtwoord in te stellen
 (deel dit veilig). Elke directe aanmaak wordt gelogd. Uitnodigen blijft de
 standaard met weinig frictie; direct aanmaken is het gemakspad voor de
 beheerder.
- **Ontkoppelen.** Druk op een *Gekoppelde* rij op **Ontkoppelen** en
 bevestig. Het spelersrecord blijft; alleen de koppeling verdwijnt. De
 spelersrol wordt **alleen** van het account verwijderd als dat account
 niet ook aan een andere speler of aan een staf-/ouderrecord is gekoppeld
 — zo verliest een coach die ooit speelde zijn coachtoegang niet.

## De ouders van een speler

Een ouder koppel je aan een speler door het account van de ouder aan die speler te verbinden in de weergave **Ouderaccounts**. Die koppeling is de enige plek waar staat wie de ouders van een speler zijn. Ze bepaalt wat een ouder kan zien, en de spelerslijst toont haar in de ouderkolom: de primaire ouder, plus een aantal als er meer zijn ("Anna de Vries +1").

Een account dat een speler of een stafmedewerker is, kun je niet ook als ouder koppelen. Een account dat onder Mensen alleen een *ouder*-record heeft, wel.

Een verzorger zonder account zet je in plaats daarvan in de contactvelden voor de verzorger bij de speler (naam, e-mail en telefoon). Berichten aan een gezin vallen op die velden terug.

Het spelersformulier in wp-admin had een eigen ouderkiezer, die naar een record onder Mensen wees in plaats van naar een account. Die is verdwenen. Bij de update wordt elke koppeling daaruit naar een persoon *met* een account automatisch overgezet naar de koppeling in Ouderaccounts. Een koppeling naar een persoon zonder account kan niet worden overgezet. Elk daarvan staat in het **Foutenlog**, zodat je die verzorger opnieuw kunt invoeren in de contactvelden van de speler.

## Waarom één account, één speler

Een login is aan **maximaal één** speler gekoppeld. Het systeem dwingt dit
af zodat een ouder, speler of coach die "hun" record opent nooit op de
gegevens van het verkeerde kind belandt. Als je een account probeert te
koppelen dat al in gebruik is, meldt de weergave dit in plaats van de
koppeling stilletjes te verplaatsen.

## Een team in bulk uitnodigen

Boven de lijst genereert **Een team in bulk uitnodigen** een
speleruitnodiging voor elke speler in een gekozen team die nog geen account
of openstaande uitnodiging heeft. Kies het team en klik op **Uitnodigingen
genereren** — je krijgt een samenvatting van hoeveel nieuwe uitnodigingen
zijn aangemaakt en hoeveel spelers er al een hadden. De dagelijkse
uitnodigingslimiet geldt nog steeds; als een groot team die bereikt, vertelt
de samenvatting hoeveel er verstuurd zijn zodat je de rest de volgende dag
kunt uitnodigen (of de limiet verhoogt).

Het team dat je kiest moet een team zijn dat je zelf coacht. Wie
academiebreed uitnodigingen mag aanmaken — een hoofd opleiding, een
academiebeheerder — kan elk team kiezen; ieder ander wordt geweigerd,
of hij de keuzelijst gebruikte of het verzoek anders in elkaar zette.

## Wie het kan gebruiken

Academie- en clubbeheerders (de rechten die ook het aanmaken en verwijderen
van spelersrecords regelen). Coaches en scouts zien de weergave en de tegel
niet.
