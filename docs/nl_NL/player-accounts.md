<!-- audience: admin -->

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

## Waarom één account, één speler

Een login is aan **maximaal één** speler gekoppeld. Het systeem dwingt dit
af zodat een ouder, speler of coach die "hun" record opent nooit op de
gegevens van het verkeerde kind belandt. Als je een account probeert te
koppelen dat al in gebruik is, meldt de weergave dit in plaats van de
koppeling stilletjes te verplaatsen.

## Wie het kan gebruiken

Academie- en clubbeheerders (de rechten die ook het aanmaken en verwijderen
van spelersrecords regelen). Coaches en scouts zien de weergave en de tegel
niet.
