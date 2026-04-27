<!-- audience: admin -->

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

## Acceptatieflow

De ontvanger tikt op de link → komt op de accept-invite-route van het dashboard → ziet een klein formulier met drie secties:

1. **Account** — recovery-e-mail + wachtwoord (verplicht).
2. **Rolspecifieke toewijzing**:
   - Speler → optioneel rugnummer; profiel is al voorgevuld.
   - Ouder → relatielabel (ouder / moeder / vader / voogd), checkbox voor meldingen.
   - Staf → bevestiging van rol + team (door de inviter ingesteld; niet bewerkbaar hier).
3. **Versturen** — de plugin maakt de WP-gebruiker aan, voert de koppelstap uit, logt ze in en stuurt ze door naar hun dashboard.

Als de ontvanger **al ingelogd is en het e-mailadres overeenkomt** met de uitnodiging, draait het silent-link-pad: geen formulier, één klik op "Accepteren en doorgaan".

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

## Audit log

Elke gebeurtenis wordt geregistreerd in `tt_audit_log` met de actor + entiteit:

- `invitation.created` — actor heeft de rij aangemaakt.
- `invitation.accepted` — ontvanger volgde de link; IP + user-agent geregistreerd voor forensisch onderzoek.
- `invitation.revoked` — admin heeft ingetrokken.
- `invitation.cap_overridden` — admin klikte door de dagelijkse limiet (legt de reden vast).

## Hooks voor uitbreidingen

De InvitationsModule vuurt drie acties voor plugin-uitbreidingen:

- `do_action( 'tt_invitation_created', $id, $kind )` — vuurt nadat de rij is opgeslagen.
- `do_action( 'tt_invitation_accepted', $id, $kind, $user_id )` — vuurt nadat de WP-gebruiker is aangemaakt en de koppelstap is geslaagd.
- `do_action( 'tt_invitation_revoked', $id )` — vuurt na intrekken.

Fase 1 levert geen workflow-template dat zich abonneert op `tt_invitation_accepted`; de hook is gereserveerd voor de v1.5 "welkom / rugnummer instellen"-taak die in #0022 Fase 2 landt.

## Zie ook

- [Toegangsbeheer](access-control.md) — voor de vier uitnodiging-gerelateerde capabilities.
- [Workflow-motor](workflow-engine.md) — voor het abonnementspatroon op `tt_invitation_accepted`.
