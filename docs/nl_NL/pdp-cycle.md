<!-- audience: user -->

# Persoonlijk Ontwikkelplan (PDP)

Een **PDP-dossier** is een seizoens­gebonden ontwikkelplan voor één speler. Het brengt samen wat anders verspreid raakt over evaluaties, doelen en losse aantekeningen — en geeft de academie een herhaalbare cadans: instelbare gespreks­momenten over het seizoen, polymorfe koppelingen tussen doelen en de methodische woordenlijst, en een doelbewust eindeseizoens­oordeel ondertekend door het hoofd academie.

## Wie ziet wat

- **Coaches** — volledige bewerking van PDP-dossiers voor spelers in hun eigen teams. Tegel: **Performance → PDP**.
- **Hoofd academie** — globale bewerking van alle dossiers plus exclusieve schrijftoegang tot het eindeseizoens­oordeel.
- **Spelers** — alleen-lezen op het eigen dossier, plus een bewerkbare zelfreflectie­sectie vóór elk gesprek. Tegel: **Mijn → Mijn PDP**.
- **Ouders / verzorgers** — alleen-lezen op het dossier van hun kind (na ondertekening) plus een per-gesprek bevestigings­knop.
- **Read-only observer** — alleen-lezen op alle dossiers; geen bewerking, geen bevestiging.

## De flow

### 1. Open het dossier

Klik op de **PDP**-tegel, kies een speler en klik op *Nieuw PDP-dossier openen*. Het dossier wordt aangemaakt met één gesprek per cyclus (2, 3 of 4 — instelbaar per club, te overschrijven per team). Elk `scheduled_at` wordt evenredig over de start- en einddatum van het seizoen verdeeld.

Voor elk gesprek wordt automatisch een native agenda-item bijgehouden. Zodra de Spond-integratie (#0031) live is, worden dezelfde items naar de Spond-agenda gepusht.

### 2. Voer de gesprekken

Elk gesprek heeft twee fases:

- **Vóór het gesprek** — agenda + de zelfreflectie van de speler.
- **Na het gesprek** — notities, afgesproken acties en ondertekening.

De **bewijszijbalk** in het formulier toont elke evaluatie, activiteit en doelen­wijziging voor de speler sinds het vorige gesprek — alleen-lezen, puur ter verankering van het gesprek.

De speler kan op elk moment vóór ondertekening zijn zelfreflectie invullen. Zodra de coach ondertekent, wordt het veld vergrendeld.

### 3. Bevestiging

Na ondertekening verschijnt het gesprek op het *Mijn PDP*-overzicht van de speler (en de ouder, indien gekoppeld). Beiden kunnen op *Bevestigen* klikken — een lichte "ik heb het gezien"-timestamp.

### 4. Eindeseizoens­oordeel

Wanneer het laatste gesprek van de cyclus is ondertekend, legt het hoofd academie (of de hoofdcoach in sommige configuraties) een eindoordeel vast: **doorstromen**, **behouden**, **uitsluiten**, of **transfer**. Het eindoordeel is een aparte rij, los ondertekend van de gesprekken.

## Carryover

Bij het instellen van een nieuw huidig seizoen draait een eenmalige taak: elk open PDP-dossier uit het vorige seizoen wordt voor het nieuwe seizoen gerepliceerd — verse gesprekken, vers `created_at`, maar de open doelen van de speler (alles behalve `completed` of `archived`) worden meegenomen.

Tekstuele inhoud van gesprekken wordt **niet** meegenomen. Elk seizoen begint schoon; de geschiedenis blijft staan waar het stond.

## Doelen­koppelingen

Een doel kan nu gekoppeld worden aan één of meer methodische entiteiten:

- een **principe** (bv. *opbouwen vanaf achteren*)
- een **voetbalhandeling** (bv. *passen onder druk*)
- een **positie** (bv. *nummer 8*)
- een **spelerswaarde** (toewijding, leerbaarheid, leiderschap, veerkracht, communicatie, werkethiek, fairplay, ambitie — bewerkbaar via Configuratie → Lookups)

De koppelingen verschijnen op het spelerprofiel en in de printsjabloon; ze maken queries mogelijk als "alle doelen gekoppeld aan *veerkracht* in de academie" of "elke speler die werkt aan *opbouwen vanaf achteren*".

## Printen

De **Printen / PDF**-knop in het detailoverzicht opent een schone A4-layout: foto, seizoenslabel, huidige doelen + status, afgesproken acties per gesprek, en handtekening­regels voor coach / speler / ouder. Schakel *Opnieuw renderen met bewijspagina* in voor een tweede A4 met recente evaluaties en activiteiten.

## Configuratie

- **Configuratie → Lookups → Spelers­waarden** — bewerk de waarde-woordenlijst.
- **Hoofdmenu → Seizoenen** — lijst, toevoegen, huidig instellen. Een nieuw huidig seizoen instellen activeert de carryover.
- **Configuratie → Systeem** — *PDP-cyclusstandaard* (2 / 3 / 4) en de *Print: standaard bewijs meenemen*-knop.
- **Per-team override** — op de team-bewerk­pagina kun je *PDP-cyclus­grootte* afwijkend instellen.

## Werkflow­herinneringen

Er zijn twee taaktemplates geregistreerd:

- `pdp_conversation_due` — herinnert de verantwoordelijke coach wanneer `scheduled_at` van een gesprek nadert.
- `pdp_verdict_due` — herinnert het hoofd academie aan het einde van het seizoen.

Beide gebruiken de standaard werkflow- en takenmotor van #0022 — dezelfde inbox, dezelfde herinnerings­cadans die je instelt via Configuratie → Werkflow.
