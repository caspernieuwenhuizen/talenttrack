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

Heeft de wedstrijdactiviteit een **geplande selectie** (de verwachte
spelers die je bij het aanmaken koos), dan begint de
beschikbaarheidsstap vanuit dat plan in plaats van iedereen op
Aanwezig te zetten: geplande spelers staan standaard op Aanwezig, en
teamspelers die je uit het plan liet staan vooraf op Afwezig met de
reden "niet in geplande selectie." Pas elke chip aan — de
standaardwaarden zijn slechts een voorzet. Activiteiten zonder
geplande selectie zetten nog steeds iedereen standaard op Aanwezig.

## Formatie

In het dropdown **Formatie** staat elke regel uit
`tt_formation_templates`. De standaard is **4-2-3-1** — de meest
gebruikte vorm van de pilot. Een andere formatie kiezen hervormt de
posities op de velden; toewijzingen die op een doorlopende positie
blijven gaan mee, de rest valt terug op de bank.

De beschrijvende naam staat in je eigen taal (de cijfers van de vorm
blijven in elke taal gelijk) — bijv. **Neutraal 4-3-3**, **Balbezit
4-3-3**. Naast de standaard 3-4-3 is er nu ook een **Aanvallend 3-4-3
(ruit)**: een 3-4-3 met een middenveldruit (DM, twee centrale
middenvelders en een aanvallende middenvelder achter een voorhoede van
drie). Die tekent nu ook echt als ruit op het veld — een opstelling
draagt zijn eigen posities, dus twee opstellingen met hetzelfde
vormgetal (de platte 3-4-3 en de ruit) vallen niet langer op dezelfde
plaatsing terug.

## Spelernamen — korte vorm

Elke spelernaam op het scherm — selectielijst, posities op de velden,
Doen per speler, Rollen-paneel, beschikbaarheidsmodal — wordt
weergegeven als de **voornaam** van de speler (`Daan`, `Senna`,
`Javi`). De volledige naam blijft voor het spelersprofiel en de
teampagina.

Hebben twee spelers in hetzelfde team dezelfde voornaam, dan worden
beiden weergegeven als `<voornaam> <achternaaminitiaal>` (`Daan P`,
`Daan A`) zodat de coach ze in één oogopslag uit elkaar kan houden.
Een derde `Daan` in een ander team heeft geen invloed op de labels
van dit team. Dezelfde speler verschijnt overal op het match-prep
scherm met dezelfde korte vorm.

Heeft een speler geen voornaam in het systeem, dan wordt de
achternaam getoond; ontbreken beide dan toont het scherm `—` tot het
recordprobleem is verholpen.

## Opslaan

Iedere wijziging wordt live opgeslagen via REST — er is geen
opslaanknop. De rechterkant van de werkbalk toont de huidige status
("Alle wijzigingen opgeslagen.", "Niet-opgeslagen wijzigingen…",
"Opslaan…", "Opslaan mislukt. Probeer opnieuw."). Mislukt een save
dan kun je de wijziging opnieuw doen; het netwerk kan kuren hebben.

## Afdrukken (of opslaan als PDF)

De knop **Exporteren als PDF (A4)** in de werkbalk maakt een afbeelding
van het wedstrijdvoorbereidingsraster precies zoals het op het scherm
staat — beide opstellingsvelden, de tabel **Selectie · minuten** (minuten
per helft + totalen), de **Wedstrijddoelen**, **Doen per speler** en
**Rollen & standaardsituaties** — en plaatst die op **A4 staand**,
geschaald op paginabreedte. De positie-nummers, spelernamen, het
`!`-icoon (rood) en het camera-icoon (groen) behouden hun schermkleuren.
Past de inhoud niet op één pagina, dan wordt hij automatisch over
meerdere pagina's verdeeld. De PDF wordt direct naar je apparaat
gedownload. Omdat de pagina als afbeelding wordt vastgelegd is het
resultaat pixelgetrouw aan wat je ziet — het nadeel is dat de tekst in
de PDF niet selecteerbaar is.

De afbeeldingsmotor laadt pas wanneer je voor het eerst op Exporteren
klikt (hij wordt niet eerder gedownload), zodat de match-prep-pagina
zelf niet zwaarder wordt. Lukt het vastleggen niet op je apparaat, dan
verschijnt een korte melding; val dan terug op de eigen afdrukdialoog
van de browser (**Ctrl/Cmd+P → Opslaan als PDF**), die de pagina
rechtstreeks afdrukt.

De knop **Wedstrijdformulier afdrukken** ernaast opent het teamblad
voor langs de lijn (basiself / bank / selectie met handtekeningregels)
op een schone afdrukpagina in een nieuw tabblad, met een eigen keuze
**Exporteren als PDF (A4 liggend)** / **Afdrukken**. De centrale
`?tt_view=exports`-pagina heeft nog steeds een server-side match-prep /
teamblad PDF-exporter als terugvaloptie voor wie liever vandaaruit
werkt.

## Wat hier niet kan

- De selectie aanpassen (spelers toevoegen / verwijderen) — dat doe
  je op de pagina **Teams**.
- De wedstrijd zelf draaien — dat is **Match Execution**, de live
  telefoon-app voor de assistent-coach. De knop **Start match** wordt
  pas actief op de wedstrijddag zelf — daarvoor is hij wel zichtbaar
  maar uitgeschakeld, met een tooltip die de datum noemt waarop hij
  vrijkomt ("Beschikbaar op wedstrijddag (14 jun)"). Zo wordt een
  wedstrijd niet per ongeluk te vroeg gestart.
- Analist-feedback vastleggen — de camera-vlag markeert alleen wie er
  is aangewezen; het vastleggen van de feedback zelf is een aparte
  workflow.
