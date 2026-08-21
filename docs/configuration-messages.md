---
title: Configuration — Messages
group: configuration
summary: Which messages your academy sends, one switch per message type.
audience: [admin]
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

Everything is on by default, and a message type introduced by a later release arrives switched on. Turning one off is always a deliberate act.

## What this is not

- **It is not an opt-out.** This is the academy's decision for everyone. An individual's own preferences live under **My settings → Messages you receive**.
- **It is not a channel switch.** To stop a whole channel (SMS, say), use **Modules → Communication**.
- **It does not stop safeguarding messages.** Those are operational and are always delivered.

## Related

- [Modules](modules.md) — turning the Communication module or its channels on and off.
- [Configuration — General](configuration-general.md) — the name and address messages are sent from.
