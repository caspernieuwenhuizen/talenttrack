<!-- audience: user -->

# Installeren (eerste keer instellen)

Wanneer je TalentTrack installeert, leidt de **Installatie**-flow je door de
essentie: je academie een naam geven, je eerste team aanmaken, je
beheerdersprofiel registreren en de dashboardpagina aan de voorkant
aanmaken. Je kunt dit als allereerste doen, of later opnieuw uitvoeren om
de onderdelen toe te voegen die je had overgeslagen.

Open het via **Configuratie → Installeren**. De tegel opent de
Installatie-weergave aan de voorkant op `?tt_view=setup` — er is geen
omleiding naar wp-admin (sinds #1938). Je hebt de rechten **Instellingen
bewerken** (`tt_edit_settings`) nodig om de tegel te zien en de flow uit te
voeren.

## De stappen

Een stappenbalk bovenaan laat zien waar je bent. Elke stap wordt onderweg
opgeslagen, zodat je kunt stoppen en later vanaf dezelfde plek verdergaan.

1. **Welkom** — een korte introductie, daarna **Mijn academie instellen** om
   te beginnen.
2. **Basisgegevens academie** — academienaam, primaire kleur, seizoenslabel
   en de datumnotatie die in de hele plugin wordt gebruikt. Deze verschijnen
   in de dashboardkop, op spelerskaarten en in afgedrukte rapporten. Je kunt
   ze later aanpassen onder Configuratie.
3. **Eerste team** — geef je eerste team een naam en kies de
   leeftijdscategorie. Spelers, evaluaties, activiteiten en doelen hangen
   allemaal aan een team, dus je hebt er minstens één nodig. Je kunt **Deze
   stap overslaan** als je liever later teams toevoegt onder Teams.
4. **Eerste beheerder** — maakt een TalentTrack-medewerkersrecord aan voor
   het ingelogde account en koppelt dit aan je WordPress-gebruiker, zodat
   evaluaties, activiteiten en meldingen naar de juiste persoon verwijzen.
   Vink **Geef mij de rol Clubbeheerder** (aanbevolen) aan om jezelf volledige
   beheerstoegang te geven.
5. **Dashboardpagina** — maakt de pagina aan de voorkant aan die de
   `[talenttrack_dashboard]`-shortcode host en stelt deze in als de homepage
   van de site, zodat iedereen op het dashboard belandt na het inloggen.
   Bestaat er al een pagina met de shortcode, dan wordt die hergebruikt en
   niet gedupliceerd. Je kunt dit **Overslaan** en de homepage later zelf
   instellen onder Instellingen → Lezen.
6. **Klaar** — een samenvatting van wat is ingesteld, met **Naar dashboard**
   en een knop **Opnieuw uitvoeren**.

## Stoppen en hervatten

Je voortgang wordt automatisch opgeslagen. Sluit het tabblad en kom op elk
moment terug naar **Configuratie → Installeren** — je belandt op de stap
waar je was gebleven.

## Opnieuw uitvoeren

Zodra de installatie voltooid is, toont **Configuratie → Installeren** de
samenvatting. Klik op **Opnieuw uitvoeren** om opnieuw te beginnen vanaf de
welkomststap. Opnieuw uitvoeren verwijdert **niet** de gegevens die je al
had aangemaakt — je teams, medewerkersrecords en pagina's blijven behouden;
je loopt de flow alleen opnieuw door. Dezelfde **Opnieuw beginnen**-optie is
midden in de flow beschikbaar als je je voortgang wilt resetten zonder af te
ronden.

## Annuleren

Elke stap biedt **Annuleren** naast de doorgaan-/opslaanknop. Annuleren
brengt je terug naar Configuratie zonder iets te verliezen wat je al had
opgeslagen.

## Voor ontwikkelaars — REST-oppervlak

De flow is een gewone weergave aan de voorkant (niet het wizardframework
voor recordcreatie); hij hergebruikt de bestaande onboarding-domeinlaag
(`OnboardingState` + `OnboardingHandlers`) via `OnboardingRestController`.
Elk eindpunt controleert zijn `permission_callback` op `tt_edit_settings`.
Zie `docs/rest-api.md` voor de routetabel.
