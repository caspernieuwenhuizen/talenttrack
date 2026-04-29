<!-- audience: user, admin -->

# Doelen als gesprek

Bij elke ontwikkelingsdoel van een speler hoort nu een chat-achtige gesprekslijn. Trainers, spelers en gekoppelde ouders posten korte berichten, stellen vragen en reflecteren op voortgang — het gesprek blijft bij het doel staan in plaats van te verdwijnen in WhatsApp of mail.

Deze pagina beschrijft wat je kunt posten, wie wat ziet, hoe meldingen werken en de regels voor bewerken en verwijderen.

## Wat je ziet

Open een doel (*Mijn doelen*, *Doelen* op het coachoppervlak, of admin-zijdig `?tt_view=goals&id=...`) en scroll voorbij de formuliervelden. De sectie **Gesprek** toont de thread:

- **Je eigen berichten** verschijnen rechts in een gekleurde bubbel.
- **Berichten van anderen** staan links met de auteursnaam, tijd en (waar relevant) een kleine "Alleen trainers"-label.
- **Systeemberichten** (doel aangemaakt, status gewijzigd) staan gecentreerd en cursief zodat duidelijk is dat ze van het systeem komen, niet van een persoon.
- Een markering "Nieuwe berichten" blijft staan op berichten die zijn geplaatst sinds je laatste bezoek.

Het tekstvak staat onderaan: schrijven, versturen, je bericht verschijnt onderaan de thread.

## Wie ziet wat

Doelen zijn zichtbaar voor:

- De **trainer** die het doel beheert (of elke trainer toegewezen aan het team van de speler).
- De **speler** wiens doel het is.
- **Gekoppelde ouders** — gekoppeld via het guardian-emailadres van de speler.
- **Beheerders / Head of Development** — standaard alleen-lezen, maar mogen ook posten.

Trainers en beheerders kunnen een bericht markeren als **Alleen trainers** door het vinkje *Alleen trainers* aan te zetten voor verzending. Berichten in deze modus blijven onzichtbaar voor spelers en ouders (en triggeren ook geen mailmelding voor hen).

## Meldingen

Elk publiek bericht stuurt een mail naar elke andere deelnemer — behalve de auteur zelf, die nooit een melding krijgt over een eigen bericht. "Alleen trainers"-berichten gaan alleen naar trainers en beheerders.

Onderwerp: *Nieuw bericht op het doel van Marcus: "Eerste-balcontact onder druk verbeteren"*. De body toont de auteur, een korte preview en een link terug naar het doel.

Wanneer pushmeldingen volgen (gepland via `#0042`), krijgen deelnemers met een actieve push-abonnement een push in plaats van mail; de rest blijft mail krijgen.

Beheerders kunnen de meldingsuitwaaier helemaal uitzetten door `threads.notify_on_post=0` te zetten in `tt_config`.

## Bewerken en verwijderen

- Je kunt je eigen bericht **5 minuten** na plaatsing **bewerken**. Na dat venster sluit het — soft-delete is de enige optie.
- Je kunt je eigen bericht op elk moment **soft-deleten**. De bubbel blijft staan in de thread, maar de inhoud wordt vervangen door "Bericht verwijderd." Beheerders kunnen elk bericht soft-deleten; de oorspronkelijke tekst blijft bewaard in de audit-log zodat hij herstelbaar is.
- Systeemberichten zijn niet bewerkbaar.

## Systeemberichten

Status-wijzigingen op een doel schrijven automatisch een systeembericht in de thread:

- "Doel aangemaakt: Eerste-balcontact onder druk verbeteren."
- "Status gewijzigd naar: In uitvoering."
- "Status gewijzigd naar: Voltooid."

Zo vertelt de thread het verhaal van het doel ook zonder dat iemand iets typt.

## Polling en live updates

De thread vraagt elke 30 seconden om nieuwe berichten zolang de pagina open is. Bij tabwissel of achtergrond pauzeert het pollen; bij terugkomst wordt het hervat. Geen websocket / SSE in v1 — gesprekken op doel-tempo hebben dat niet nodig.

## Audit-log

Elke plaatsing / bewerking / verwijdering schrijft een regel in de audit-log (`thread_message_posted`, `thread_message_edited`, `thread_message_deleted`). Verwijderde berichten houden hun oorspronkelijke tekst in de audit-payload zodat beheerders kunnen herstellen wat er stond.

## REST API

De thread-primitive is bereikbaar via:

```
GET    /wp-json/talenttrack/v1/threads/{type}/{id}                berichten lezen, gelezen-markeren
POST   /wp-json/talenttrack/v1/threads/{type}/{id}/messages       bericht plaatsen
PUT    /wp-json/talenttrack/v1/threads/{type}/{id}/messages/{m}   bewerken (5-min venster, alleen auteur)
DELETE /wp-json/talenttrack/v1/threads/{type}/{id}/messages/{m}   soft-delete
POST   /wp-json/talenttrack/v1/threads/{type}/{id}/read           expliciete gelezen-marker
```

v1 registreert alleen `goal` als thread-type. Toekomstige epics (#0017 trialcases, #0014 scout-rapporten, #0044 POP-gesprekken) registreren hun eigen types.

## Wat niet in v1 zit

- **Bestand- of afbeeldingsbijlagen.** Voorlopig alleen platte tekst.
- **Reacties / emoji.** Een "dank!"-comment is genoeg.
- **@-mentions** met autocomplete. Meldingen gaan al naar alle deelnemers.
- **Live websocket-updates.** 30-seconde polling.
- **Bewerken na 5 minuten.** Soft-delete + opnieuw plaatsen is de workflow.
- **Hard-delete per bericht.** GDPR-verwijdering loopt via het bestaande retentiepad.
