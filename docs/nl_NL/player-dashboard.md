<!-- audience: user -->

# Speler-dashboard (frontend)

Spelers die inloggen op de frontend-shortcode zien een tegelgebaseerd dashboard dat volledig op henzelf is gericht. (v3.0.0 — het tegelraster heeft nu echte bestemmingen; v2.21's tegellanding is een volwaardige landingspagina, niet meer louter decoratie.)

## Hoe kom je er

De shortcode `[talenttrack_dashboard]`. Als een speler (gekoppeld aan een WordPress-gebruiker via `wp_user_id`) inlogt en die pagina bezoekt, komt hij/zij op het tegelraster met de sectie **Mij** zichtbaar.

## De Mij-tegels

Elke tegel duikt in een gerichte subweergave met bovenin een link "← Terug naar dashboard".

### Mijn kaart
FIFA-achtige kaart met overall-beoordeling, radarchart per hoofdcategorie, belangrijkste attributen, aangepaste veldwaarden en een knop **Rapport afdrukken** voor een nette printversie.

### Mijn team
Je eigen kaart centraal, gevolgd door het podium met de top-3 van het team (huidige topprestaties) en een roster van teamgenoten (namen + foto's, geen beoordelingen — om degenen die buiten de top-3 vallen te beschermen). Tik op een teamgenoot voor een alleen-lezen kaart met speelgegevens (positie, rugnummer, voet, lengte, gewicht). Individuele evaluaties, doelen en beoordelingen blijven privé.

### Mijn evaluaties
Tabel van elke evaluatie die over jou is vastgelegd, meest recent eerst. Toont datum, type (Training / Wedstrijd / enz.), coach en de beoordelingen per categorie. Wedstrijd-evaluaties tonen ook tegenstander en uitslag.

### Mijn sessies
Aanwezigheidslogboek van trainingen. Tabel met datum, sessietitel, aanwezigheidsstatus (Aanwezig / Afwezig / Laat / Afgemeld — in kleur gecodeerd) en eventuele notities.

### Mijn doelen
Ontwikkelingsdoelen die je coaches voor je hebben gesteld, gegroepeerd op status. Elke doelkaart toont titel, omschrijving, statusbadge en, indien ingesteld, een streefdatum.

### Mijn profiel
Je persoonlijke gegevens (naam, team, leeftijdscategorie, posities, voet, rugnummer, lengte, gewicht, geboortedatum) in alleen-lezen layout. Onderhouden door je coaches — neem contact op voor correcties. Ook een link om WordPress-account-instellingen (weergavenaam, e-mail, wachtwoord) te bewerken.

## Privacy

Spelers zien **alleen hun eigen gegevens**. Ze kunnen de evaluaties of persoonlijke info van andere spelers niet zien. De teamroster toont de namen van teamgenoten, maar niet hun individuele beoordelingen.

## Mobiel

Het tegelraster valt terug naar 1 kolom op telefoons, 2 op tablets. Alle subweergaven zijn responsief — ontworpen voor een speler die op een telefoon kijkt tijdens of na de training.

## Wat spelers niet kunnen

Spelers hebben alleen `read` — geen `tt_manage_*`- of `tt_evaluate_*`-rechten en geen `tt_edit_*`-rechten. Ze kunnen hun eigen WordPress-account (weergavenaam, e-mail, wachtwoord) bewerken, maar geen evaluaties of sessies aanmaken en niets aan het team wijzigen. Zelfs het lezen van pagina's van andere spelers wordt op controller-niveau geblokkeerd.
