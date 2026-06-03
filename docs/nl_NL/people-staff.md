<!-- audience: admin -->

# Personen (staf)

De pagina **Personen** is je register van niet-spelende mensen: hoofdcoaches, assistent-coaches, fysiotherapeuten, keeperstrainers, teammanagers, bestuursleden.

## Waarom Personen apart staat van WP-gebruikers

Een **persoon** is een echte rol bij de club. Een **WordPress-gebruiker** is een inlogaccount. Ze zijn verwant, maar niet hetzelfde:

- Een hoofdcoach kan zowel een persoon ÉN een WP-gebruiker zijn (zodat hij/zij kan inloggen en evalueren).
- Een fysiotherapeut kan een persoon ZONDER WP-gebruiker zijn (hij/zij logt nooit in).
- Een WP-beheerder kan een gebruiker ZONDER persoonsrecord zijn (hij/zij onderhoudt alleen het systeem).

Koppel ze als beide bestaan. De koppeling maakt dingen mogelijk als "coach X mag team Y zien" en "wie coacht dit team".

## Functionele rollen

Elke persoon kan een of meerdere functionele rollen hebben, zoals **Hoofdcoach**, **Assistent-coach**, **Fysio**. Deze worden gekoppeld aan autorisatierollen via de pagina [Toegangsbeheer](?page=tt-docs&topic=access-control) — door iemand de functionele rol Hoofdcoach toe te kennen, krijgt hij/zij automatisch de benodigde rechten.

## Personen toewijzen aan teams

Voeg via de bewerkpagina **Teams** een persoon toe met een functionele rol. Een persoon kan in meerdere teams actief zijn (bijvoorbeeld een assistent-coach die twee leeftijdscategorieën ondersteunt).

## Archiveren

Als staf vertrekt, archiveer ze. Gearchiveerde personen verdwijnen uit de keuzelijsten voor teamtoewijzing, maar historische gegevens blijven intact.

## Definitief verwijderen (opschonen)

Om een persoon definitief te verwijderen én tegelijk alle verwijzingen op te schonen, open je de **Personen**-beheerpagina (`wp-admin → TalentTrack → Personen`), selecteer je de rijen die je wilt verwijderen, kies je **Definitief verwijderen** uit de bulkactie-dropdown en klik je op **Toepassen**.

Voordat er iets wordt geschreven, opent een **impact-preview-dialoog** die exact toont wat er gaat gebeuren, per geselecteerde persoon:

- **Verwijderd**: teamtoewijzingen, scopetoekenningen op functionele rollen, staf-ontwikkelingsentries, certificaten, staf-evaluaties, staf-doelen, mentorkoppelingen en openstaande uitnodigingen.
- **Leeggemaakt (bovenliggende rij blijft staan)**: "toegekend door"-attributie op scopes die deze persoon heeft toegekend, geaccepteerde uitnodigingen die op deze persoon waren gericht (historie blijft staan; de doelverwijzing wordt leeggemaakt), en spelerrecords die deze persoon als ouder-contact noemden.

Bij bevestiging draait één database-transactie de schoonmaak; als één stap mislukt, wordt de hele batch teruggedraaid — een gedeeltelijke delete is onmogelijk.

Voor batches van 3+ personen OF bij een persoon met 5+ getroffen verwijzingen vraagt een tweede stap om **DELETE** te typen ter bevestiging — bescherming tegen onbedoelde klikken op grote opschoningen.

**WordPress-gebruikersaccounts worden NIET aangeraakt.** Als een verwijderde persoon ook een WP-login had, blijft dat account bestaan en kan nog steeds inloggen. Verwijder het apart via het WordPress **Gebruikers**-beheer als je de toegang wilt blokkeren.
