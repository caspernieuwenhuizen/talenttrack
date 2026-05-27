<!-- audience: user -->

# Wedstrijdvoorbereiding

Het scherm **Wedstrijdvoorbereiding** laat een hoofdcoach een wedstrijd
plannen in één spreadsheet-achtig overzicht: wie er beschikbaar is,
wie er per helft op welke positie staat, wat de wedstrijddoelen per
fase zijn, wat elke speler moet onthouden, en wie de aanvoerdersband
plus elke standaardsituatie neemt.

Open het scherm vanaf de detailpagina van een wedstrijd-type
activiteit; de URL is `?tt_view=match-prep&activity_id=<id>`. De
eerste keer dat je het scherm voor een wedstrijd opent draait eerst
de **Beschikbaarheids-wizard** zodat de selectie de Aanwezig / Afwezig
/ Geblesseerd-chips krijgt die nodig zijn.

## Indeling

Het scherm bestaat uit drie kolommen die de werksheet van de pilot
volgen:

- **Links** — de beschikbare selectie met drie minutentellers per rij
  (`min 1e`, `min 2e`, `tot`) en totalen onderaan. De minuten worden
  automatisch berekend uit `helftduur × (op veld ? 1 : 0)`; pas je
  **Helftduur** boven aan, dan loopt iedere rij live mee.
- **Midden** — twee halve velden naast elkaar met een `→`-kopieerknop
  ertussen. Daaronder de **Wedstrijddoelen**-paneel — bovenaan één
  volledige *Algemeen*-box, daarna een 2×2 grid van *Aanvallen /
  Verdedigen* en *Spelhervattingen / Spelhervattingen*. Iedere box
  heeft vier korte tekstvelden (bullet-stijl) — geschreven voor de
  korte regels die de pilot gebruikt.
- **Rechts** — twee gestapelde panelen. **Doen per speler** bevat per
  speler een aandachtspunt, een `!`-vlag voor "dit is een specifiek
  doel voor deze speler", en een camera-icoontje voor "ik heb een
  videoanalist toegewezen aan deze speler." **Rollen &
  standaardsituaties** bevat zes rijen — Aanvoerder, Hoekschop links /
  rechts, Vrije trap links / rechts (voorzet), Strafschop.

## Spelers op posities zetten

Een positie op het veld is een cirkel. Klik op een cirkel — leeg of
gevuld — om de **spelerkiezer** te openen die naast de cirkel
verschijnt. Typ om te filteren; tik op een naam om toe te wijzen.
Een speler die al ergens anders in dezelfde helft staat wordt
automatisch verplaatst (maximaal één plek per helft).

Je kunt ook **een speler vanaf de linker selectie naar een cirkel
slepen**. Slepen is de desktop-verbetering; klikken is het hoofdpad en
werkt overal hetzelfde.

De `→`-knop tussen de twee velden **kopieert de opstelling van de 1e
helft naar de 2e helft** met één klik, zonder bevestiging. Pas
vervolgens de 2e helft aan (meestal één of twee wissels).

## Aanvoerder en standaardsituaties

Het paneel **Rollen & standaardsituaties** rechts werkt hetzelfde als
een positiecirkel. Klik op een rij — Aanvoerder, Hoekschop links,
Hoekschop rechts, Vrije trap links, Vrije trap rechts, Strafschop —
om dezelfde spelerkiezer te openen. De ×-pil op een gevulde rij maakt
de toewijzing leeg. Wordt een speler in de drawer op Afwezig gezet
dan wordt hij automatisch uit zowel rolrijen als opstelling
verwijderd, zodat het rollenpaneel nooit naar een onbeschikbare
speler verwijst.

## Beschikbaarheids-drawer

Klik **Beschikbaarheid** in de werkbalk om de drawer in te schuiven
met drie chips per speler: **Aanwezig**, **Afwezig (excused)**,
**Geblesseerd**. Voeg desgewenst een reden toe bij een afwezigheid.
**Iedereen aanwezig** is de snelkoppeling voor "vandaag is de hele
selectie er." Het sluiten van de drawer slaat op; wie als afwezig
wordt gemarkeerd wordt uit elke positie en rolrij verwijderd.

## Formatie

In het dropdown **Formatie** staat elke regel uit
`tt_formation_templates`. De standaard is **4-2-3-1** — de meest
gebruikte vorm van de pilot. Een andere formatie kiezen hervormt de
posities op de velden; toewijzingen die op een doorlopende positie
blijven gaan mee, de rest valt terug op de bank.

## Opslaan

Iedere wijziging wordt live opgeslagen via REST — er is geen
opslaanknop. De rechterkant van de werkbalk toont de huidige status
("Alle wijzigingen opgeslagen.", "Niet-opgeslagen wijzigingen…",
"Opslaan…", "Opslaan mislukt. Probeer opnieuw."). Mislukt een save
dan kun je de wijziging opnieuw doen; het netwerk kan kuren hebben.

## Print naar PDF

De knop **PDF (liggend A4)** opent een printklare PDF die de
opstelling + aandachtspunten + wedstrijddoelen naar het kleedkamerbord
brengt. Het rollenpaneel staat voorlopig alleen op het scherm — een
volgende release voegt het ook aan de PDF toe.

## Wat hier niet kan

- De selectie aanpassen (spelers toevoegen / verwijderen) — dat doe
  je op de pagina **Teams**.
- De wedstrijd zelf draaien — dat is **Match Execution**, de live
  telefoon-app voor de assistent-coach.
- Analist-feedback vastleggen — de camera-vlag markeert alleen wie er
  is aangewezen; het vastleggen van de feedback zelf is een aparte
  workflow.
