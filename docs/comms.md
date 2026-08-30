---
title: Messaging
group: configuration
summary: How the academy's outgoing messages work — templates, channels, quiet hours, opt-outs and the send log.
audience: [user, admin]
views: [messages, my-messages]
order: 55
---

# Messaging

Every message TalentTrack sends a family, a player or a staff member goes out through one place. That is what makes it possible to answer the question the whole thing exists for: *did the parents actually get the cancellation message?*

This page explains what sends, who receives it, what can stop it, and where to look when something did not arrive.

## What a message is made of

Four things decide whether a message leaves the building and what it says.

**A template.** Every kind of message has one — a cancelled training, a development plan ready to read, an invitation to create an account. The template owns the wording in English and Dutch. A handful of the most-used ones can be reworded per academy so the tone matches how you already talk to families; the rest are fixed.

**A recipient rule.** You never address a message at a child directly. You address it at a *player*, and the youth-contact rules decide who actually receives it: for the youngest age groups the parents, for the middle groups both, and from U12 up the player themselves. That rule lives in one place and every message obeys it, so no individual feature can get it wrong.

**A channel.** Email, push notification, SMS, WhatsApp link, or the in-app inbox. Each template declares which channels suit it, and the first one that can actually reach that recipient is used. Someone with no phone number on file gets email; someone with the app installed gets a push.

**A message type.** This is what an opt-out and the quiet-hours rule act on, and what the send log is grouped by.

## What can stop a message

Five things, in this order. Each of them is recorded, so a message that did not arrive always has a reason attached to it.

| Reason | What happened |
| --- | --- |
| Template switched off | Someone turned this kind of message off for the whole academy. |
| Opted out | The recipient asked not to receive this kind of message. |
| Quiet hours | It is between 21:00 and 07:00 and this message can wait until morning. |
| Rate limited | One sender has sent an unusual number of messages in an hour. |
| No address | Nobody on the record has an email address or phone number this channel could use. |

Two exceptions are deliberate. **Safeguarding messages and account-recovery email cannot be opted out of** — those are not preferences. And **a cancelled training ignores quiet hours**, because a training called off tonight is useless news tomorrow.

## Quiet hours

By default nothing non-urgent goes out between **21:00 and 07:00**. A message caught by the window is recorded as deferred rather than sent. The window is configurable per academy.

## Opting out

Each person controls their own preferences from **My settings**. The list is per message type, not all-or-nothing: a parent can mute goal reminders and still hear about a cancelled training. Safeguarding and account-recovery messages are not on the list, because they are not optional.

## Turning a kind of message off for everyone

An academy-wide switch exists per template. Use it when a kind of message does not fit how you work — an academy that never sends goal nudges can switch that one off without losing attendance flags with it.

Switching a template off suppresses the message and **not** the evidence: the send log still records that the message would have been sent and that the switch stopped it. That is on purpose. "We turned it off" and "it silently failed" must not look the same six months later.

There is a second, coarser switch under Modules: **Scheduled messaging** turns off the daily cron that sends goal nudges, attendance flags, onboarding nudges and staff-development reminders. Event-driven messages — the ones that fire the moment something happens — are unaffected by it.

## The send log

**Configuration → Message log**, or from a player's record under **⋯ → Messages sent**.

Every send attempt writes a row, whatever the outcome. The row records who sent it, who received it, which player it was about, which template and channel, the subject line, and the status.

The screen filters by player, kind of message, outcome and date range. The player filter offers only players the log has actually carried a message about — a list of every player in the academy would mostly be options that return nothing.

Outcomes are shown in words, not in database keys, and in three tones rather than two: delivered, deliberately withheld, and a problem. An opt-out the product honoured and an address that bounced are both "not delivered" and want opposite reactions, so they are not painted the same colour.

If a scheduled detector has been failing, a warning sits above the table naming it and when it last ran. That is the only place that difference shows: a detector with nothing to send and a detector crashing every night both leave no rows behind.

**The message body is never stored.** The log keeps a fingerprint of it so the record cannot be quietly altered, and nothing more. This is a deliberate limit: it means the log can tell you that a message about a child was sent, to whom, and whether it arrived — and cannot be used to read what a coach wrote about them.

Log rows are kept for **18 months** by default. After that a daily job blanks the recipient address and subject line while keeping the row itself, so the fact of the message survives as safeguarding evidence without the personal detail attached.

## The in-app inbox

**My messages**, under Me on your dashboard. The tile carries the unread count.

Messages sent on the in-app channel land in the recipient's own inbox rather than in their email. Unread ones are marked, and **Mark as read** clears the marker without reloading the page.

A person only ever sees their own inbox. A parent sees messages about their own child and never another family's — that is enforced by the query itself, not by a permission check that could be worked around.

## Which messages send today

Messages fall into three groups.

**Event-driven** — they fire the moment something happens in the product. A training is cancelled; a development plan is signed off; a trial is opened for a player; an invitation is sent; a coach writes a direct message; a scout report is delivered; a trial input reminder goes out; a scheduled report is delivered.

The trial welcome is worth one note, because it promises less than you might expect. A trial case records the player, the trajectory and the dates — it has no place and no kit list — so the message names the start date and says a coach will be in touch with the time, the place and what to bring. That is what actually happens, and it is better than a message with two empty headings on it. It goes to the parents of a youth player and to the player themselves once they are old enough, like every other message about a player.

**Scheduled** — a daily job looks for a condition and sends: goals that have gone quiet, repeated absences, parents who have not logged in for a month, staff development reviews coming due.

**Registered but not yet connected** — a small number of templates ship with their wording ready and no trigger behind them yet. They send nothing. You will see them in the template list, and switching them on or off changes nothing until the feature that raises them lands.

## When someone says they did not get it

Work down the list — start on the player's record, open **⋯ → Messages sent**, and you are already filtered to them:

1. **Find the message in the send log.** If there is no row at all, nothing was attempted — the trigger did not fire, and that is a different problem from a delivery failure.
2. **Read the status.** Opted out, deferred, template disabled and no-address each say plainly what happened, and each has a different fix.
3. **Check the address on the record.** "No address" means nobody on the player's record — parent or player — had a usable one for that channel.
4. **Check the template switch** if the status says the template was disabled.

The one answer the log cannot give you is whether a delivered email was read. Delivery to the mail provider is where TalentTrack's visibility ends.

## For developers

The REST surface is documented in `rest-api.md`: the send log at `GET /comms/messages` and `GET /players/{id}/messages`, the inbox at `GET /comms/inbox` and `PATCH /comms/inbox/{id}`, the template switch at `GET /comms/templates` and `PATCH /comms/templates/{key}`, and the caller's own preferences at `GET|PUT /comms/preferences`.
