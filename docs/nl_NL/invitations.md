---
title: Uitnodigingen
group: configuration
summary: Nodig spelers, ouders en staf uit via deelbare WhatsApp-links — wachtwoorden worden bij de eerste opvolging ingesteld.
audience: [admin]
views: [invitations-config]
module: TT\Modules\Invitations\InvitationsModule
order: 70
---

# Uitnodigingen

Onboard de mensen rondom een speler zonder handmatig WP-accounts aan te maken. Genereer een eenmalige getekende link, deel via WhatsApp (of kopieer + e-mail), de ontvanger kiest een wachtwoord en landt op zijn dashboard. Drie rolvarianten: **speler**, **ouder**, **staf**.

## Wanneer versturen

De plugin genereert een uitnodiging **automatisch** wanneer iemand bij een team komt:

- **Spelersuitnodiging** — aangemaakt wanneer een speler aan de teamselectie wordt toegevoegd. De knop "Uitnodiging delen" op de selectierij deelt de link.
- **Staf-uitnodiging** — aangemaakt wanneer staf aan een team wordt toegewezen via een functionele rol. De knop verschijnt op de toewijzingsrij.
- **Ouder-uitnodiging** — er is geen selectiestap voor ouders, dus twee oppervlakken:
 - Auto-prompt bij het toevoegen van een speler (onderdrukt tijdens CSV-bulkimports — na de import verschijnt één batchactie voor de net aangemaakte spelers).
 - Handmatige knop **Ouder uitnodigen** op het bewerkformulier van de speler, altijd beschikbaar.

## Vanaf waar delen

| Oppervlak | Waar | Doelgroep |
| - | - | - |
| Selectierij op de frontend | Coach dashboard → Mijn teams → selectie | Coach die vanaf zijn telefoon deelt, geschikt voor langs de zijlijn |
| wp-admin spelerbewerking | Spelers → bewerken | Admin die seizoensonboarding doet |
| wp-admin personenbewerking | Personen → bewerken | Admin die staf-uitnodigingen verstuurt |

De popover toont de accept-URL, een live preview van het berichtstekst en drie deelknoppen: **WhatsApp** (standaard — opent `wa.me/?text=...`), **E-mail** (opt-in — opent de mailclient van de ontvanger), **Link kopiëren**.

## Automatische e-mail

Wanneer een uitnodiging wordt aangemaakt **met een e-mailadres**, wordt de accept-link ook **automatisch naar de genodigde gemaild** — de beheerder hoeft niet langer elke link met de hand te versturen. De e-mail gaat via de Comms-module (en wordt dus gelogd zoals elk ander bericht) in de taal van de genodigde, met een "stel je wachtwoord in"-actie en de vervaldatum van de link. Hij is transactioneel: opt-out / stille uren / rate-limits worden overgeslagen, zodat een genodigde zijn uitnodiging nooit wordt onthouden. De WhatsApp- / link-kopiëren-knoppen blijven werken voor gevallen zonder e-mailadres of wanneer de beheerder liever handmatig deelt.

## Inloggegevens nog even vasthouden

Een uitnodiging kan worden aangemaakt **zonder hem meteen te versturen**. De genodigde krijgt niets totdat iemand hem uitdrukkelijk verstuurt — handig bij het inrichten van een club, waar je eerst je trainers wilt toevoegen en wilt controleren of alles werkt voordat er iemand een uitnodiging binnenkrijgt.

Vastgehouden uitnodigingen staan in de lijst als **nog niet verstuurd**, met een teller boven de tabel en een knop **Alle uitnodigingen versturen**, of **Nu versturen** op een losse rij. Tot je verstuurt, heeft de genodigde helemaal niets ontvangen.

Versturen mag je gerust herhalen: een uitnodiging die al is verstuurd wordt overgeslagen in plaats van dubbel bezorgd, en bij een bulkverzending zie je hoeveel er zijn verstuurd en hoeveel er zijn overgeslagen in plaats van één totaaluitslag.

Vastgehouden uitnodigingen verlopen niet anders, gedragen zich na verzending niet anders en kunnen net als elke andere worden ingetrokken. Verlaat je de wizard of de pagina zonder te versturen, dan gaan ze niet verloren — ze blijven in de lijst staan.

## Acceptatieflow

De ontvanger tikt op de link → komt op de accept-invite-route van het dashboard → ziet een klein formulier met drie secties:

1. **Account** — recovery-e-mail + wachtwoord (verplicht).
2. **Rolspecifieke toewijzing**:
 - Speler → optioneel rugnummer; profiel is al voorgevuld.
 - Ouder → relatielabel (ouder / moeder / vader / voogd), checkbox voor meldingen.
 - Staf → bevestiging van rol + team (door de inviter ingesteld; niet bewerkbaar hier).
3. **Versturen** — de plugin maakt de WP-gebruiker aan, voert de koppelstap uit, logt ze in en stuurt ze door naar hun dashboard.

Als de ontvanger **al ingelogd is en het e-mailadres overeenkomt** met de uitnodiging, draait het silent-link-pad: geen volledig formulier, één klik op "Accepteren en doorgaan". Bij een **ouder**-uitnodiging wordt nog steeds om de relatie gevraagd (ouder / moeder / vader / verzorger), zodat een verzorger nooit met een aangenomen rol wordt gekoppeld. Op het volledige acceptatieformulier is het herstel-e-mailadres vooraf ingevuld vanuit de uitnodiging, met een korte uitleg dat het alleen voor wachtwoordherstel wordt gebruikt (en aangepast kan worden).

## Rechten

| Capability | Standaard toegekend aan |
| - | - |
| `tt_send_invitation` | administrator + Hoofd Opleidingen + Club Admin + Coach |
| `tt_revoke_invitation` | administrator + Hoofd Opleidingen + Club Admin |
| `tt_manage_invite_messages` | administrator + Club Admin |

Er komt een nieuwe WP-rol `tt_parent` met `read` + `tt_view_parent_dashboard`. Ouders zien een "Kinderen"-overzicht beperkt tot hun gekoppelde spelers via de nieuwe `tt_player_parents`-pivottabel.

## Configuratie

`Configuratie → Uitnodigingen` heeft twee tabs:

- **Uitnodigingen** — gepagineerde lijst van elke uitnodiging met filter op status, link-kopie, intrekken (alleen admin / Hoofd Opleidingen / Club Admin).
- **Berichten** — zes berichtsjablonen (3 rollen × 2 locales — Engels + Nederlands). Elk bewerkbaar als platte tekst met placeholder-validatie. Placeholders:
 - `{club}`, `{role}`, `{team}`, `{player}`, `{sender}`, `{url}`, `{ttl_days}`
 - `{url}` is **verplicht** bij opslaan.

## Locale-volgorde

Het bericht wordt gerenderd in de locale van de ontvanger, gekozen via deze keten:

1. Het `locale`-veld op de doelrij in `tt_players` / `tt_people` (per rij door een admin gezet als bekend).
2. Standaard van de club — `tt_config.invite_default_locale` (standaard `nl_NL` op nieuwe installaties).
3. WP-locale van de uitnodiger als laatste fallback.

## Token + levensduur

- Tokens zijn 32 tekens URL-veilig random (~192 bits entropie). Eenmalig.
- Standaardlevensduur is **14 dagen**, per club configureerbaar via `tt_config.invite_token_ttl_days`.
- Wachtende uitnodigingen worden bij elke lijstweergave + acceptatiepoging veegt naar **Verlopen**.

## Rate-limit + override

Een zachte limiet van **50 uitnodigingen per admin per 24 uur** wordt afgedwongen. Twee override-paden:

- **Filter** — `apply_filters('tt_invitation_daily_cap', 50, $user_id)` — voor hosts die de limiet permanent willen verhogen.
- **Toch doorgaan** — wanneer een admin de limiet halverwege raakt, biedt de share-popover een inline redenveld en een "Toch doorgaan"-knop. De override + reden wordt vastgelegd in de audit log.

## Een gezin om hun contactgegevens vragen

Niet elk gezin heeft een account nodig. Soms wil de academie alleen een naam, een e-mailadres en een telefoonnummer op het dossier van de speler, zodat medewerkers naar huis kunnen bellen als een training vervalt of een kind een blessure oploopt — en juist die gegevens komen op papier binnen, worden bij het hek overgetypt en zijn binnen een seizoen verouderd.

Open het bewerkformulier van de speler en ga naar **Vraag het gezin**, onder de contactvelden. Typ het adres waar de vraag heen moet en druk op **Verzoek versturen**. Het gezin krijgt een kort bericht met de naam van hun kind en een link naar een formulier van één pagina waar ze hun eigen naam, e-mailadres en telefoonnummer invullen en bevestigen dat de academie die mag gebruiken.

De melding **Speler zonder contactpersoon** linkt rechtstreeks naar dat formulier, zodat het kantoor de lijst vanuit de meldingen kan afwerken.

Wat er gebeurt als ze antwoorden:

- De gegevens komen **direct** op het spelersrecord. Er is geen goedkeuringswachtrij — een tweede postvak zou alleen vertragen waar het kantoor toch al mee achterloopt.
- Alleen de velden die ze invullen worden weggeschreven; wat ze leeg laten blijft precies zoals het was.
- De audit log legt vast wat er wijzigde, van wat naar wat, en met welk verzoek. Daarmee is een verkeerd antwoord te herstellen: de vorige waarde staat in het spoor, dus een beheerder kan het terugzetten.

Wat de link **niet** doet:

- Hij maakt nooit een account aan en geeft geen rechten. Het is geen uitnodiging en kan ook niet als uitnodiging worden gebruikt.
- De pagina toont **de naam van het kind en verder niets** — geen team, geen geboortedatum, geen evaluaties, en nooit de contactgegevens die al op het dossier staan; die kunnen van de andere ouder zijn.
- Hij werkt **één keer** en vervalt na evenveel dagen als een uitnodiging (**Geldigheid uitnodigingslink**, standaard 14 dagen). Een vervallen, gebruikte of onbekende link toont dezelfde zin: *deze link is niet meer geldig, vraag de academie om een nieuwe*. Stuur er gerust een nieuwe.

Een ouder met een account ziet zijn eigen kant hiervan bij **Mijn instellingen** → *Wat de academie van jou heeft*.

## Audit log

Elke gebeurtenis wordt geregistreerd in `tt_audit_log` met de actor + entiteit:

- `invitation.created` — actor heeft de rij aangemaakt.
- `invitation.accepted` — ontvanger volgde de link; IP + user-agent geregistreerd voor forensisch onderzoek.
- `invitation.revoked` — admin heeft ingetrokken.
- `invitation.cap_overridden` — admin klikte door de dagelijkse limiet (legt de reden vast).
- `guardian_contact.requested` — medewerker vroeg een gezin om contactgegevens; vastgelegd bij de **speler**, met waar het verzoek heen ging.
- `guardian_contact.submitted` — het gezin antwoordde; vastgelegd bij de speler, met per veld de vorige en de nieuwe waarde, zodat de wijziging terug te draaien is.

## Hooks voor uitbreidingen

De InvitationsModule vuurt vier acties voor plugin-uitbreidingen:

- `do_action( 'tt_invitation_created', $id, $kind )` — vuurt nadat de rij is opgeslagen, ongeacht of er iemand is gemaild.
- `do_action( 'tt_invitation_sent', $id )` — vuurt nadat een vastgehouden uitnodiging is verstuurd en `sent_at` is gezet.
- `do_action( 'tt_invitation_accepted', $id, $kind, $user_id )` — vuurt nadat de WP-gebruiker is aangemaakt en de koppelstap is geslaagd.
- `do_action( 'tt_invitation_revoked', $id )` — vuurt na intrekken.

En twee voor de contactlink van het gezin:

- `do_action( 'tt_guardian_contact_requested', $request_id, $player_id, $email )` — vuurt nadat de verzoekrij is opgeslagen, vóór het versturen.
- `do_action( 'tt_guardian_contact_submitted', $request_id, $player_id, $changes )` — vuurt nadat het antwoord van het gezin is weggeschreven, met per gewijzigd veld de vorige en de nieuwe waarde.

Fase 1 levert geen workflow-template dat zich abonneert op `tt_invitation_accepted`; de hook is gereserveerd voor de v1.5 "welkom / rugnummer instellen"-taak die in #0022 Fase 2 landt.

## Zie ook

- [Toegangsbeheer](access-control.md) — voor de vier uitnodiging-gerelateerde capabilities.
- [Workflow-motor](workflow-engine.md) — voor het abonnementspatroon op `tt_invitation_accepted`.
