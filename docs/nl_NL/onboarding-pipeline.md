<!-- audience: user -->

# Aannamepijplijn

De **Aannamepijplijn** is de werving-trechter — elke speler die bij de academie binnenkomt, doorloopt deze. Open hem via de tegel op het dashboard (groep Academie) of direct via `?tt_view=onboarding-pipeline`.

## Wat zie je

Zes kolommen naast elkaar, één per fase van de reis van "scout heeft hem gespot" tot "speelt voor de academie":

| Kolom | Wat erin zit |
|---|---|
| **Prospects** | Geconcept maar nog niet doorgegeven aan de Hoofd Ontwikkeling. Nieuwe inschrijvingen via de wizard slaan deze kolom over — die landen direct in *Uitgenodigd*. Wat hier staat, is óf een legacy `log_prospect`-conceptklus óf een keten die halverwege werd afgebroken. |
| **Uitgenodigd** | De HoD stelt de uitnodiging voor de testtraining op of heeft hem verstuurd, of de bevestiging van de ouder is in afwachting. |
| **Testtraining** | De testtraining is gepland of heeft plaatsgevonden — de HoD legt de uitkomst vast. |
| **Trialgroep** | De prospect is in de trialgroep opgenomen en wordt daar beoordeeld. |
| **Teamaanbod** | Een coach heeft de prospect een plek in het team aangeboden; wachten op beslissing (ouder + speler). |
| **Aangesloten** | De prospect is in de afgelopen 90 dagen gepromoveerd tot een spelersrecord. |

Elke kolom toont een teller en een stapel kaartjes — één kaartje per prospect. Op kaartjes staan de naam, leeftijd (of geboortedatum), huidige club en een contextregel per fase. Klik op een kaartje om te openen wat nu actie vraagt voor die prospect (het openstaande takenformulier voor de actieve fase; het spelersprofiel voor wie al gepromoveerd is).

Een lichtoranje kaartje met een *stale*-badge betekent dat de openstaande klus voor die prospect meer dan 30 dagen over zijn deadline is.

## Een nieuwe prospect toevoegen

Klik op **+ Nieuwe prospect** bovenaan. De wizard loopt door:

1. **Identiteit** — voornaam / achternaam, geboortedatum, huidige club. Duplicaatdetectie draait hier — als er al een prospect met dezelfde naam bestaat, moet je het vinkje "dit is een nieuwe inschrijving" zetten voordat je verder kunt.
2. **Ontdekking** — waar je hem hebt gespot (evenement / wedstrijd), korte scoutnotities.
3. **Oudercontact** — naam, e-mail, telefoon. Minimaal een e-mail of een telefoonnummer is vereist zodat de HoD de ouder kan bereiken. Vink het toestemmingsvakje aan (verplicht om als academie oudercontactdata te mogen bewaren).
4. **Controleren** — bevestig de antwoorden en maak aan.

Bij verzenden:

- Het prospectrecord wordt aangemaakt.
- Er wordt een klus naar de Hoofd Ontwikkeling gestuurd om de prospect uit te nodigen voor een testtraining.
- Je komt terug op de pijplijnpagina, waar het nieuwe kaartje in de kolom **Uitgenodigd** verschijnt.

De wizard is het canonieke startpunt voor "+ Nieuwe prospect" — klikken op de knop maakt niet langer als bijwerking een workflow-klus aan (een regressie die in v3.110.48 is opgelost; voorheen POSTte de klik naar `/prospects/log` en kwam je in een `Prospect inloggen`-klus terecht onder Mijn taken, wat gebruikers verraste en de inschrijving dubbel telde).

## Rechten

- **`tt_view_prospects`** — vereist om de pijplijn te openen. Standaard toegekend aan Academy Admin, Hoofd Ontwikkeling en Scout.
- **`tt_edit_prospects`** — vereist om de Nieuwe prospect-wizard te starten. Zelfde standaardtoekenningen.
- **`tt_invite_prospects`** — vereist om de klus *Uitnodigen voor testtraining* af te ronden (HoD-pad).

Scouts zien alleen hun eigen prospects (gefilterd op `discovered_by_user_id`); HoD en Academy Admin zien elke prospect in de academie.

## Faseregels

Elke prospect hoort in **precies één** kolom. De classifier loopt in deze volgorde:

1. Gepromoveerd tot speler in de laatste 90 dagen → **Aangesloten**.
2. Heeft een openstaande klus *Wachten op teambeslissing* → **Teamaanbod**.
3. Is opgenomen in een trialgroep → **Trialgroep**.
4. Heeft een openstaande klus *Uitkomst testtraining vastleggen* → **Testtraining**.
5. Heeft een openstaande klus *Uitnodigen voor testtraining* of *Bevestiging testtraining* → **Uitgenodigd**.
6. Anders (geen openstaande klus, niet gepromoveerd, niet gearchiveerd) → **Prospects**.

De dashboardwidget gebruikt dezelfde classifier voor zijn compacte tellerstrip, dus de getallen op het dashboard kloppen met de kolommen op de standalone pagina. Voor v3.110.48 telde de widget rijen klussen op over templates heen, dus een enkele prospect met zowel een Uitnodig- als een Bevestigingsklus open verscheen tegelijk als 2 in de kolom Uitgenodigd — opgelost.

## Wat de wizard overslaat

De legacy-keten startte met een `LogProspectTemplate`-klus en gaf daarna door aan `InviteToTestTrainingTemplate`. De wizard *is* het formulier dat de LogProspect-klus omhulde, dus die klus aanmaken om data te vragen die de wizard al verzamelde, was een overbodige stap. De wizard gaat direct naar `InviteToTestTrainingTemplate`.

`LogProspectTemplate` en het `/prospects/log` REST-endpoint blijven bestaan voor backward compat — externe integraties (bijv. het ouder-zelfbevestigingstoken) en elke custom workflow-trigger die ze aanroept blijven werken.
