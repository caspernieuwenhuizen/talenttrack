<!-- audience: user -->

# Persoonlijk Ontwikkelingsplan (POP)

Een **POP-dossier** is een seizoensÂ­gebonden ontwikkelplan voor Ã©Ã©n speler. Het brengt samen wat anders verspreid raakt over evaluaties, doelen en losse aantekeningen â€” en geeft de academie een herhaalbare cadans: instelbare gespreksÂ­momenten over het seizoen, polymorfe koppelingen tussen doelen en de methodische woordenlijst, en een doelbewust eindeseizoensÂ­oordeel ondertekend door het hoofd academie.

## Wie ziet wat

- **Coaches** â€” volledige bewerking van POP-dossiers voor spelers in hun eigen teams. Tegel: **Performance â†’ POP**.
- **Hoofd academie** â€” globale bewerking van alle dossiers plus exclusieve schrijftoegang tot het eindeseizoensÂ­oordeel.
- **Spelers** â€” alleen-lezen op het eigen dossier, plus een bewerkbare zelfreflectieÂ­sectie vÃ³Ã³r elk gesprek. Tegel: **Mijn â†’ Mijn POP**.
- **Ouders / verzorgers** â€” alleen-lezen op het dossier van hun kind (na ondertekening) plus een per-gesprek bevestigingsÂ­knop.
- **Read-only observer** â€” alleen-lezen op alle dossiers; geen bewerking, geen bevestiging.

## De flow

### 1. Open het dossier

Klik op de **POP**-tegel, kies een speler en klik op *Nieuw POP-dossier openen*. Het dossier wordt aangemaakt met Ã©Ã©n gesprek per cyclus (2, 3 of 4 â€” instelbaar per club, te overschrijven per team). Elk `scheduled_at` wordt evenredig over de start- en einddatum van het seizoen verdeeld.

Voor elk gesprek wordt automatisch een native agenda-item bijgehouden. Zodra de Spond-integratie (#0031) live is, worden dezelfde items naar de Spond-agenda gepusht.

### 2. Voer de gesprekken

Elk gesprek heeft twee fases:

- **VÃ³Ã³r het gesprek** â€” agenda + de zelfreflectie van de speler.
- **Na het gesprek** â€” notities, afgesproken acties en ondertekening.

De **bewijszijbalk** in het formulier toont elke evaluatie, activiteit en doelenÂ­wijziging voor de speler sinds het vorige gesprek â€” alleen-lezen, puur ter verankering van het gesprek.

De speler kan op elk moment vÃ³Ã³r ondertekening zijn zelfreflectie invullen. Zodra de coach ondertekent, wordt het veld vergrendeld.

### 3. Bevestiging

Na ondertekening verschijnt het gesprek op het *Mijn POP*-overzicht van de speler (en de ouder, indien gekoppeld). Beiden kunnen op *Bevestigen* klikken â€” een lichte "ik heb het gezien"-timestamp.

### 4. EindeseizoensÂ­oordeel

Wanneer het laatste gesprek van de cyclus is ondertekend, legt het hoofd academie (of de hoofdcoach in sommige configuraties) een eindoordeel vast: **doorstromen**, **behouden**, **uitsluiten**, of **transfer**. Het eindoordeel is een aparte rij, los ondertekend van de gesprekken.

## Carryover

Bij het instellen van een nieuw huidig seizoen draait een eenmalige taak: elk open POP-dossier uit het vorige seizoen wordt voor het nieuwe seizoen gerepliceerd â€” verse gesprekken, vers `created_at`, maar de open doelen van de speler (alles behalve `completed` of `archived`) worden meegenomen.

Tekstuele inhoud van gesprekken wordt **niet** meegenomen. Elk seizoen begint schoon; de geschiedenis blijft staan waar het stond.

## DoelenÂ­koppelingen

Een doel kan nu gekoppeld worden aan Ã©Ã©n of meer methodische entiteiten:

- een **principe** (bv. *opbouwen vanaf achteren*)
- een **voetbalhandeling** (bv. *passen onder druk*)
- een **positie** (bv. *nummer 8*)
- een **spelerswaarde** (toewijding, leerbaarheid, leiderschap, veerkracht, communicatie, werkethiek, fairplay, ambitie â€” bewerkbaar via Configuratie â†’ Lookups)

De koppelingen verschijnen op het spelerprofiel en in de printsjabloon; ze maken queries mogelijk als "alle doelen gekoppeld aan *veerkracht* in de academie" of "elke speler die werkt aan *opbouwen vanaf achteren*".

## Printen

De **Printen / PDF**-knop in het detailoverzicht opent een schone A4-layout: foto, seizoenslabel, huidige doelen + status, afgesproken acties per gesprek, en handtekeningÂ­regels voor coach / speler / ouder. Schakel *Opnieuw renderen met bewijspagina* in voor een tweede A4 met recente evaluaties en activiteiten.

## Configuratie

- **Configuratie â†’ Lookups â†’ SpelersÂ­waarden** â€” bewerk de waarde-woordenlijst.
- **Hoofdmenu â†’ Seizoenen** â€” lijst, toevoegen, huidig instellen. Een nieuw huidig seizoen instellen activeert de carryover.
- **Configuratie â†’ Systeem** â€” *POP-cyclusstandaard* (2 / 3 / 4) en de *Print: standaard bewijs meenemen*-knop.
- **Per-team override** â€” op de team-bewerkÂ­pagina kun je *POP-cyclusÂ­grootte* afwijkend instellen.

## WerkflowÂ­herinneringen

Er zijn twee taaktemplates geregistreerd:

- `POP_conversation_due` â€” herinnert de verantwoordelijke coach wanneer `scheduled_at` van een gesprek nadert.
- `POP_verdict_due` â€” herinnert het hoofd academie aan het einde van het seizoen.

Beide gebruiken de standaard werkflow- en takenmotor van #0022 â€” dezelfde inbox, dezelfde herinneringsÂ­cadans die je instelt via Configuratie â†’ Werkflow.
