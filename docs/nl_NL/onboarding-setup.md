---
title: Installatie (eerste keer)
group: basics
summary: De route die een nieuwe installatie door de eerste configuratie loodst.
audience: [user]
module: TT\Modules\Onboarding\OnboardingModule
order: 15
---

# Installeren (eerste keer instellen)

Wanneer je TalentTrack installeert, leidt de **Installatie**-flow je door de
essentie: je academie een naam geven, je eerste team aanmaken, je
beheerdersprofiel registreren en de dashboardpagina aan de voorkant
aanmaken. Je kunt dit als allereerste doen, of later opnieuw uitvoeren om
de onderdelen toe te voegen die je had overgeslagen.

Open het via **Configuratie → Installeren**. De tegel opent de
Installatie-weergave aan de voorkant op `?tt_view=setup` — er is geen
omleiding naar wp-admin. Je hebt de rechten **Instellingen
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
3. **Hoeveel product** — kies een installatieprofiel: **Basis**, dat de
 ontwikkelcyclus houdt en wedstrijddag, trainingsplannen, de
 kennisbibliotheek, de koppelingen en de ontwikkelaarsschermen uitzet; of
 **Volledige academie**, dat alles is. Elke kaart toont wat erin zit,
 gegroepeerd zoals de Modulespagina groepeert. **Overslaan geeft je de
 volledige academie**, wat een installatie krijgt als er geen profiel is
 gekozen. Kies je er een, dan zie je wat er is omgezet voordat je verder
 gaat, en er wordt niets verwijderd — een uitgeschakelde module behoudt
 zijn gegevens. De vraag staat hier, vóór de import, zodat je niet
 importeert in een vorm die op het punt staat te veranderen. Is de
 installatie al met de hand ingericht, dan past deze stap niets toe: hij
 stuurt je naar Modules → Installatieprofiel, waar je de volledige lijst
 met wijzigingen ziet voordat er iets gebeurt.
4. **Je selectie importeren** — houd je je teams, spelers en staf al in een
 spreadsheet bij, haal ze hier dan binnen in plaats van ze opnieuw te
 typen. Download het sjabloon met drie tabbladen, vul het in en upload
 het. **Er wordt niets opgeslagen tot je bevestigt**: de eerste upload
 laat alleen zien wat er in het bestand staat en wat er nog niet klopt, en
 bij een bestand met problemen blijft de wizard staan waar hij stond.
 **Overslaan** als je geen spreadsheet hebt.
5. **Eerste team** — geef je eerste team een naam en kies de
 leeftijdscategorie. Spelers, evaluaties, activiteiten en doelen hangen
 allemaal aan een team, dus je hebt er minstens één nodig. Heeft de
 importstap al teams binnengehaald, dan meldt deze stap dat en kun je
 gewoon doorgaan. Je kunt **Deze stap overslaan** als je liever later
 teams toevoegt onder Teams.
6. **Eerste beheerder** — maakt een TalentTrack-medewerkersrecord aan voor
 het ingelogde account en koppelt dit aan je WordPress-gebruiker, zodat
 evaluaties, activiteiten en meldingen naar de juiste persoon verwijzen.
 Vink **Geef mij de rol Clubbeheerder** (aanbevolen) aan om jezelf volledige
 beheerstoegang te geven.
7. **Je staf toevoegen** — voeg de trainers en staf toe die TalentTrack gaan
 gebruiken. Geef iemand een e-mailadres en er wordt een uitnodiging voor
 diegene klaargezet. **Er wordt nog niemand gemaild**: uitnodigingen worden
 vastgehouden tot jij ze verstuurt, zodat je het inrichten kunt afmaken en
 rond kunt kijken voordat er iemand binnenkomt. Ben je zover, dan verstuurt
 **N uitnodigingen versturen en doorgaan** ze allemaal. Je kunt ook
 doorgaan zonder te versturen — de uitnodigingen blijven klaarstaan onder
 Configuratie → Uitnodigingen, en er gaat niets verloren.
8. **Wat we versturen** — vink aan welke berichten TalentTrack namens jou
 mag versturen. Een nieuwe academie begint met **alles uit**, dus tot je
 hier kiest, krijgt niemand iets te horen — ook niet dat een training is
 afgelast. Er staat niets voorgevinkt, omdat de keuze aan jou is en niet
 aan ons; de groep waar mensen het meest van balen als die uitblijft, is
 gemarkeerd als **Aanbevolen**. Je kunt **Overslaan — voorlopig niets
 versturen** en het later instellen onder Configuratie → Berichten.
 Uitnodigingen voor staf staan hier los van: dat is accountinrichting en
 geen bericht uit deze lijst, dus wie je in de vorige stap uitnodigde,
 wordt gewoon binnengelaten.
9. **Dashboardpagina** — maakt de pagina aan de voorkant aan die de
 `[talenttrack_dashboard]`-shortcode host en stelt deze in als de homepage
 van de site, zodat iedereen op het dashboard belandt na het inloggen.
 Bestaat er al een pagina met de shortcode, dan wordt die hergebruikt en
 niet gedupliceerd. Je kunt dit **Overslaan** en de homepage later zelf
 instellen onder Instellingen → Lezen.
10. **Klaar** — een samenvatting van wat is ingesteld, met **Naar dashboard**
 en een knop **Opnieuw uitvoeren**.

## Twee schermen, dezelfde voortgang

Setup bestaat op twee plekken en ze delen één opgeslagen voortgang, dus je
kunt er vrij tussen wisselen en doet nooit een stap dubbel:

- **Configuratie → Setup** (`?tt_view=setup`) — binnen TalentTrack.
- **TalentTrack → Welkom** in de WordPress-beheeromgeving — waar een
 gloednieuwe installatie bij de eerste keer opstarten belandt.

Drie stappen zijn voorlopig alleen in de WordPress-beheeromgeving
beschikbaar: **Hoeveel product**, **Importeer je selectie** en **Voeg je
staf toe**. Elk daarvan draagt iets wat het scherm in de app nog niet heeft
— een bestandsupload met kolomtoewijzing, of de vastgehouden
uitnodigingen. Staat je opgeslagen voortgang op zo'n stap, dan zegt het
scherm in de app dat, meldt dat je voortgang bewaard blijft, en biedt aan
verder te gaan in de beheeromgeving. Kom je daarna terug, dan pakt de flow
op waar de beheeromgeving hem liet.

De rest — Welkom, Academiebasis, Eerste team, Eerste beheerder, Wat we
versturen, Dashboardpagina en Klaar — werkt op allebei de schermen.

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
