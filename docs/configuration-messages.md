---
title: Configuration — Messages
group: configuration
summary: Which messages your academy sends, one switch per message type.
audience: [admin]
views: [mail-compose]
module: TT\Modules\Comms\CommsModule
order: 115
---

# Configuration — Messages

**Dashboard → Configuration → Messages** (`?config_sub=messages`)

Which messages your academy sends. Every outgoing message in TalentTrack — a cancelled training, a selection decision, a reminder to update a goal — comes from a named template, and this page lists them all with a switch each.

The list is built from the templates the plugin has registered, so a message type added in a later release appears here automatically. Requires `tt_edit_feature_toggles`; the setting is stored per club in `tt_config`, so a future multi-tenant install keeps each academy's choices separate.

## Switching a message off

Untick a message and save. From then on:

- It is **not sent to anyone**, on any channel — email, push, WhatsApp link or in-app.
- It is **still recorded** in the message log, with the status *switched off*. You can see that a message would have gone out and did not, which matters when someone asks why a family was not told.
- If a person tries to trigger it by hand, they are told it is switched off **before** they write the message, and get an error rather than a silent success if they send anyway.

## What a brand-new academy starts with

**Nothing is sent.** An academy installing TalentTrack for the first time starts with every message on this page switched off, and stays that way until somebody chooses otherwise.

That is deliberate. These are messages to the parents of minors, and "on by default" is a decision nobody made. The setup wizard asks which of them your academy wants, so the first message a parent receives is one you chose to send. If you skipped that step, this page is where you make the choice instead — and until you do, nobody hears anything, including about a cancelled training.

**Academies that were already running are not affected.** Upgrading to the release that introduced this changes nothing: every message that was being sent before is still being sent afterwards. The new default applies to installations created from that release onward, never retroactively.

## What happens to a message type added in a later release

A new message type arrives **switched on** for an academy that already exists, and **switched off** for an academy that installs after it shipped.

The reason is that this page stores the list of messages you have switched *off*, never the list you have switched on. A message type nobody has ever seen is on nobody's off-list, so it behaves the way everything else on your install behaves — it sends. A fresh install gets its off-list written when the plugin is first activated, from the message types that exist at that moment, so anything shipped later joins the same way.

The practical consequence worth knowing: after upgrading, check this page when a release note mentions a new message type. It is already on.

## Everything the academy sends is on this list

Every outgoing email now comes from a named message type, including the ones that used to leave the building on their own:

- **Trial input reminder** — nudges assigned staff whose input on a trial case is still missing.
- **Scheduled report delivery** — the analytics export, with the file attached.
- **Email written by a staff member** — anything typed into the in-product composer.
- **Player report for a scout** — the confidential one-time link sent outside the academy.
- **Desktop link you asked for** — the "email me the link" button on the desktop-only prompt.
- **In-product notifications** — new thread messages, task assignments, development-idea updates.

Because they are on the list, all of them obey the switch above, honour a person's own opt-out, wait for quiet hours to end, and leave a row in the message log. Before, none of that was true of them.

Two emails deliberately stay outside this system:

- **Password reset.** An opt-out, a quiet-hours window or a switch left off would lock someone out of their own account with no way to ask for another link.
- **Backup delivery.** That is a file going to whoever holds your backups, not a message about a person, and it must never be held back.

## Account mail is not on this list

The **invitation email** — the one carrying the link a parent, player or staff member uses to set a password and log in for the first time — is not listed here and has no switch.

It is not a message your academy chooses to send about a player. It is how somebody gets an account at all. A switch for it would look like a messaging decision and behave like an onboarding outage: nobody who unticked it would connect "we switched off a message" to "new parents cannot log in", because those do not look like the same thing.

So it is absent rather than shown ticked and locked. It sends because somebody invited a person, and that is its only condition. Everything else about it is unchanged — it is still recorded in the message log, still resolves its recipient the same way, and still respects a person's own preferences wherever those legally apply.

If your academy previously unticked the invitation email, that choice no longer has any effect and is cleared the next time you save this page.

Password reset works the same way for the same reason, and never appeared on this list.

## What this is not

- **It is not an opt-out.** This is the academy's decision for everyone. An individual's own preferences live under **My settings → Messages you receive**.
- **It is not a channel switch.** To stop a whole channel (SMS, say), use **Modules → Communication**.
- **It does not stop safeguarding messages.** Those are operational and are always delivered.

## Related

- [Modules](modules.md) — turning the Communication module or its channels on and off.
- [Configuration — General](configuration-general.md) — the name and address messages are sent from.
