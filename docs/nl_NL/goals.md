---
title: Doelen
group: performance
summary: Ontwikkelingsdoelen per speler met status en prioriteit.
audience: [user]
views: [goals]
module: TT\Modules\Goals\GoalsModule
capability: tt_view_goals
order: 50
---

# Doelen

Een **doel** is iets waar een speler aan werkt — bijvoorbeeld "nauwkeurigheid van de zwakke voet verbeteren" of "altijd op tijd zijn voor de training". Doelen vormen de verhalende kant van spelersontwikkeling, naast de numerieke beoordelingen.

## Wat staat er op een doel

- De **speler** voor wie het doel is.
- Een korte **titel**.
- Een **omschrijving** met meer detail, oefeningen of coachingnotities.
- Een **status** (Niet gestart, In uitvoering, Behaald, Gestopt).
- Een **prioriteit** (Laag, Gemiddeld, Hoog).
- Een optionele **streefdatum**.

## Een doel toevoegen

1. Open de tegel **Doelen**.
2. Kies de speler.
3. Vul titel, omschrijving, status en prioriteit in en optioneel een streefdatum.
4. Opslaan.

## Een doel bewerken slaat zichzelf op

Een doel toevoegen eindigt met **Opslaan**, zoals hierboven. Een doel
**bewerken** niet: dat slaat op terwijl je schrijft, en op de plek van de
opslaanknop staat een statusregel die vertelt hoe ver dat is —
*Niet-opgeslagen wijzigingen…*, *Opslaan…*, *Alle wijzigingen opgeslagen*.

Ernaast draait **Ongedaan maken** de laatst opgeslagen wijziging terug en zet
**Wijzigingen terugdraaien** het formulier terug naar hoe het was toen je het
opende. Beide staan volledig beschreven in
[hoe opslaan werkt](save-model.md).

Er is geen Annuleren meer op het bewerkformulier, want er staat niets open om
te annuleren. Toevoegen vraagt nog wel om Opslaan, en dat is met opzet: er
mag geen leeg doel in het dossier van een speler achterblijven omdat je het
formulier opende en je bedacht.

## Voortgang volgen

Werk de status en omschrijving in de loop van de tijd bij naarmate de speler vordert. Het **Status**-filter op de doelenlijst groepeert doelen in **Actief**, **Behaald** en **Gemist**, en staat standaard op Actief zodat de lijst opent met wat er nog loopt. Archiveren staat daar los van: de lijst opent met niet-gearchiveerde doelen, en met de **⋯**-knop aan het eind van de filterrij schakel je naar de gearchiveerde.

## Wie ziet wat

- Spelers zien hun eigen doelen.
- Coaches zien de doelen van spelers in de teams die zij coachen.
- Beheerders zien alle doelen.

## Op het spelersdossier

Het tabblad **Doelen** van een spelersdossier opent op de doelen waar de speler
nog aan werkt, op urgentie: eerst de dichtstbijzijnde streefdatum, doelen zonder
datum onderaan. Behaalde en gestaakte doelen staan niet langer door die lijst
heen; ze staan eronder onder **Afgeronde doelen**, standaard dichtgeklapt. Klap
het open om de doelhistorie van de speler te zien zonder het profiel te
verlaten.

Het getal op het tabblad, de kop **Actieve doelen** en het doelencijfer in het
overzichtspaneel van de speler tellen alle drie hetzelfde: doelen die niet
gearchiveerd, niet behaald en niet gestaakt zijn. Verdwijnt een doel uit de
lijst nadat je het op behaald zet, dan is het naar het dichtgeklapte blok
verhuisd — niet uit het dossier van de speler.

## Op de spelersreis

Elk doel komt op de [spelersreis](player-journey.md), vanuit welk scherm het ook
is geschreven: het doelformulier, het snel-toevoegvak, de doelenwizard, het
wp-adminformulier, en de twee die de club nooit met de hand typt — de
seizoensovergang die openstaande doelen meeneemt naar een nieuw seizoen, en een
ontwikkelidee dat een doel opent zodra het wordt opgepakt. Doelen die via de
wizard werden gesteld, ontbraken voorheen volledig op de reis: een coach zag het
doel wel op het tabblad Doelen en nergens op de tijdlijn.

De regel vermeldt welke van die routes hem schreef, want ze betekenen niet
hetzelfde:

- *Doel gesteld: zwakke voet trainen* — iemand heeft het besloten.
- *Doel meegenomen: zwakke voet trainen* — de seizoensovergang heeft een
  openstaand doel meegenomen naar het nieuwe seizoen.
- *Doel geopend vanuit een ontwikkelidee: …* — een idee met de speler eraan
  gekoppeld kwam op In behandeling.

Meegenomen regels krijgen de **startdatum van het nieuwe seizoen** als datum, niet
de dag waarop de overgang is uitgevoerd — een overgang die drie weken te laat
draait, leest een seizoen later nog steeds als de seizoensstart. Een overgang
schrijft in één keer een doel voor elke speler, dus verwacht op die datum een
reeks meegenomen regels; ze zijn gelabeld zodat ze niet lezen als een dag vol
coaching.

Doelen die hiervoor zijn aangemaakt blijven van de tijdlijn tot de reis opnieuw
wordt opgebouwd. Zo'n herbouw kan niet zien hoe een oud doel is ontstaan, dus
elke aangevulde regel leest als *Doel gesteld*.

## Methodologiekoppeling

Overal waar een doel geschreven wordt — het doelformulier, het snelinvoerblok op het coachdashboard, de nieuw-doel-wizard en het wp-admin-formulier — staat de vraag **Wat ontwikkelt dit doel?** met de principes van de actieve methodiek van de club. Vink er zoveel aan als van toepassing zijn: een doel kan meer dan één principe dienen, en één verplichte keuze zou de coach dwingen willekeurig te kiezen.

De keuze is **optioneel**. Een doel zonder principe ("een betere teamgenoot zijn") is nog steeds een goed doel en niets houdt je tegen het zo op te slaan. Maar juist door te koppelen kan de rest van het systeem zich op het doel richten: trainingsplannen rangschikken oefeningen op hoeveel openstaande ontwikkeldoelen van een selectie ze raken, en de rapportage per principe op het persona-dashboard (de Doelen-per-principe-widget en de KPI Doelen-gekoppeld-aan-principe over de afgelopen 90 dagen) telt alleen gekoppelde doelen.

Een doel kan daarnaast aan één voetbalhandeling gekoppeld worden, vanuit het doelformulier en het wp-admin-formulier.

Doelen van vóór deze koppeling houden het principe dat ze al hadden; er wordt niets geraden of achteraf ingevuld. Een dun dekkingsoverzicht vertelt je dus de waarheid over hoeveel doelen tot nu toe gekoppeld zijn — het vult zich vanzelf naarmate doelen opnieuw geschreven worden.

## Door spelers gemaakte doelen met goedkeuring

Als jouw installatie spelers de doelen-bewerken-rechten geeft, krijgt een door een speler aangemaakt doel de status **Wacht op goedkeuring**. De hoofdcoach van de speler kan goedkeuren (status wordt In afwachting) of afwijzen (Geannuleerd) via de bestaande statusdropdown. Andere coaches kunnen niet goedkeuren — alleen de hoofdcoach van de speler, hetzelfde vertrouwenspatroon als bij PDP-ondertekening.

## Voortgang en bewijslast

Elk doel kan een **voortgangspercentage** en **bewijslast** dragen. Op het
bewerkformulier van het doel:

- **Voortgang (%)** — een waarde van 0–100 die de coach instelt; dit stuurt
 de voortgangsbalk op de POP-kaart van de speler. Laat leeg om de balk te
 verbergen.
- **Bewijslast (beoordelingen)** — vink de beoordelingen van de speler aan die
 het doel onderbouwen. Elke gekoppelde beoordeling verschijnt op de
 POP-kaart als een scorechip (*Beoordeling 12 mrt · 6.5*), op basis van de
 datum en de gemiddelde score van de beoordeling.

Bewijslast wordt los van de methodiekkoppelingen van het doel opgeslagen, dus
de twee zitten elkaar niet in de weg.

## Op de doeldetailpagina

Bij het openen van een doel worden — naast status, prioriteit, streefdatum,
eigenaar en omschrijving — drie velden getoond die voorheen wel te bewerken
maar nooit zichtbaar waren:

- **Voortgang** — het voortgangspercentage als balk. Een doel zonder ingestelde
 voortgang toont een streepje (—) in plaats van een verzonnen 0%.
- **Gekoppeld principe** — het gekoppelde methodiekprincipe, wanneer ingesteld.
- **Gekoppelde voetbalhandeling** — de gekoppelde voetbalhandeling, wanneer
 ingesteld.

Zowel de coachweergave als de eigen-doelweergave van de speler tonen deze
velden, zodat coach en speler hetzelfde beeld hebben van waar het doel staat en
wat het ontwikkelt.
