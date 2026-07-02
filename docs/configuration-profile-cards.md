<!-- audience: admin -->

# Configuration — Profile cards

**Dashboard → Configuration → Profile cards** (`?config_sub=profile-cards`)

The player-profile **Profile** tab shows a set of cards about the player. Not every academy uses every card — a club that does not run scouting has no use for the **Discovery** card, for example. This setting lets you choose, academy-wide, which of those cards the Profile tab shows.

Settings are stored per club in `tt_config`, so a future multi-tenant install keeps each academy's choice separate. Saving goes through `POST /wp-json/talenttrack/v1/config` like the other inline configuration forms; the page is admin / club-admin only.

## Which cards you can hide

| Card | What it shows | Hideable? |
| --- | --- | --- |
| **Identity** | The player's date of birth, position(s), preferred foot, jersey number, and status. | No — always shown. It anchors who the player is. |
| **Academy** | The player's team, age tier, and date joined. | Yes |
| **Parents · Guardians** | Linked parent / guardian contacts. Staff-only. | Yes |
| **Discovery** | How the player was discovered — the scout, discovery event, club, and date — for players who came in through the Prospects scouting funnel. Staff-only. | Yes |

The **PHV / VCT** panel is not listed here: it is governed by the VCT module toggle, so turning the VCT module off already removes it.

## How it works

Each card you want to show stays **checked**; uncheck a card to hide it everywhere. The default is that every card currently shown stays visible — you have to uncheck a card to hide it. Identity has no checkbox because it is always shown.

Hiding a card is a **display** choice only. It does not delete any data: re-checking the card brings it straight back, contents intact. The card is hidden for every viewer in the academy.

The staff-only cards (**Parents · Guardians** and **Discovery**) keep their existing rule on top of this setting: a player viewing their own profile, or a parent viewing their child's, never sees those cards even when they are enabled academy-wide. Hiding them here removes them for staff too.

## See also

- [Configuration — General](configuration-general.md)
- [Configuration and branding](configuration-branding.md)
