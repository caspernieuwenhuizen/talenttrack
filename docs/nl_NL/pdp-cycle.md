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

## Cyclusvoortgang + bevestigingsoverzicht (v3.79.0)

Op de POP-detailpagina staat de cyclusvoortgang nu als **(X van N ondertekend)** naast de cyclusgrootte, zodat je in één oogopslag ziet hoe ver de cyclus is. Elke gespreksregel laat zien:

- een afgeleide **status**-badge (Gepland / Gehouden / Ondertekend) in plaats van drie aparte datum/ja-kolommen
- een **Bevestigingen**-kolom met iconen voor ouder (👤) en speler (⚽) — `✓` na bevestiging, `·` zolang de bevestiging nog ontbreekt

De samenvattingskaart heeft een hulpknop die het PDP-onderwerp direct in de docs-drawer opent.

## Archiveren versus definitief verwijderen

POP-dossiers kennen **twee** verwijderpaden zodat destructief opruimen nooit per ongeluk de verkeerde regel raakt.

- **Archiveren** — soft-delete. Het dossier verdwijnt uit de standaardlijst, maar elke rij blijft in de database staan. Coaches met bewerkrechten kunnen een actief dossier archiveren (knop *Archiveren* in de actiekolom). Beheerders zetten de schakelaar *Gearchiveerd tonen* aan op de POP-lijst en klikken op *Herstellen* om het dossier terug te halen. Dit is het juiste antwoord wanneer een speler halverwege het seizoen vertrekt of de cyclus per ongeluk is geopend.
- **Definitief verwijderen** — onomkeerbare hard-delete. Alleen beschikbaar voor operators met de capability `tt_delete_pdp` (standaard alleen voor beheerders). Twee ingangen: de knop *PDP definitief verwijderen* op de detailpagina van het POP-dossier, **en** een actie *Permanent verwijderen* per rij bij gearchiveerde dossiers (zet de schakelaar *Gearchiveerd tonen* aan op de POP-lijst — operators met `tt_delete_pdp` zien deze schakelaar ook zonder herstelrechten). Beide openen dezelfde bevestigingspagina die:
  - een **cascade-samenvatting** toont — hoeveel gesprekken / eindoordelen / kalenderkoppelingen / POP-blokken / doel-koppelingen er verdwijnen.
  - vereist dat de operator de **naam van de speler** letterlijk overtypt voordat de knop *PDP definitief verwijderen* actief wordt (hoofdletterongevoelig, met tolerantie voor extra spaties).
  - een **CSV-momentopname vóór verwijdering** schrijft naar `wp-content/uploads/tt-pdp-deletes/pdp-<dossier-id>-<tijdstempel>.csv` voordat de cascade wordt uitgevoerd. Het absolute pad wordt vastgelegd in de audit-log-entry `pdp.deleted_with_cascade`, samen met de rijaantallen per tabel.
  - de cascade over vijf tabellen draait binnen één transactie. Elke fout draait alles terug; gedeeltelijke status na een fout is onmogelijk.

Gebruik standaard *Archiveren*. Grijp alleen naar *Definitief verwijderen* voor AVG-wisbeleid, ouderverzoeken of andere legitieme bewaartermijn-zaken. Het CSV-bestand is je audit trail — bewaar het.
