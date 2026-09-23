---
title: Dossier completeness
group: basics
summary: Per team, whose guardian contact, parent account or photo consent is still missing.
audience: [admin, user]
views: [dossier-completeness]
module: TT\Modules\Players\PlayersModule
capability: tt_view_players
order: 22
---

# Dossier completeness

Every player's file has a part that is not football: an adult the club can
reach, a parent who can read their record, and a recorded answer to whether
the club may photograph them. **Dossier completeness** is that part of a
whole squad on one screen.

It exists because the office used to answer the question one player at a
time. The players list shows name, foot and shirt number; finding out the
state of sixteen files meant opening sixteen records.

## Opening it

Open the **Dossier completeness** tile and choose a team. You see one card
per check, each saying how many of the squad are complete and naming the
players who are not. Every name links to that player's record, and the back
link brings you straight back to the list you were working through.

You see the teams you may already read players for. An administrator sees
every team; a coach sees their own. There is no club-wide version, on
purpose — see *What this page does not show* below.

## The six checks

| Check | Complete when |
| --- | --- |
| **Guardian name** | A guardian name is on the player's record. |
| **Guardian e-mail address** | A guardian e-mail address is on the record. |
| **Guardian phone number** | A guardian phone number is on the record. |
| **Parent account linked** | A parent account is connected to the player. |
| **Photo and video consent recorded** | Consent is on record. The line also says the date it was recorded. |
| **Pictures on file with no consent** | Either consent is on record, or there are no pictures to consent to. |

**A linked parent account and the guardian fields are two different
things, and they are reported separately.** An account is how a parent reads
their child's record; the guardian name, e-mail and phone are how somebody
at the club telephones a family on a Saturday morning. A player can easily
have one and not the other, and a report that merged them would tell you a
file was complete when there is still nobody to call.

## Families we can reach

Above the six checks is one line saying how many of the squad's families the
club can reach at all: **"3 of 21 families reachable"**. A family counts as
reachable when the player has a guardian e-mail address, a guardian phone
number **or** a linked parent account — any one of the three is a route to
somebody.

It is there because the two facts above read as though they disagreed. A
squad with no guardian e-mail addresses and three linked parent accounts
shows "0 of 21" on one card and "3 of 21" on another, and both are true. The
line says what they add up to, so nobody has to work it out on a phone.

It does not replace the checks and it never will. Reachable means "there is
some way to contact this family", not "this file is complete" — a player
whose parent has an account but whose guardian phone number is empty is
reachable by e-mail and still nobody you can telephone on a Saturday
morning. That is why the cards stay separate underneath.

For the same count across the whole academy, see *The overview for Heads of
Development and admins* in the Alerts topic.

**"Pictures on file with no consent" counts items as well as players.** One
player with nine photographs is a different-sized conversation from nine
players with one each, and that is what you are deciding between when you
pick up the phone. A photograph linked to eleven children counts once for
each of them, because it is eleven conversations.

## What this page does not show

**It never shows a guardian's name, e-mail address or phone number.** It
says the field is empty; it does not say what is in it when it is filled.
The details live on the player's own record, behind that record's
permissions, one player at a time. This page is a checklist, and a checklist
that printed every family's contact details would be an export with a
friendlier heading.

For the same reason there is no club-wide version. A squad is the unit the
question is actually asked in.

## Consent is recorded, never enforced

Nothing on this page hides, blurs or blocks a picture, and neither does the
alert described below. A coach who cannot see a photograph cannot judge
whether they may use it, and a file whose images quietly disappear reads as
broken rather than careful. The academy's answer to a missing consent is to
ask the family — this page is how you find out who to ask.

## The alert

**Pictures on file with no consent** is also an alert. It fires for an
active player who has at least one photo or video on file and no consent on
record, and goes to the team's head coach and to whoever can edit players.
It clears itself the moment consent is recorded or the last item is
archived. Like the page, it tells a human to go and ask — it changes nothing
about what anybody can see.

It sits next to **Player with no guardian contact**, which is a different
question: that one says the club cannot reach anybody about this player at
all. A player can have a perfectly reachable parent and still have no
consent on record.

## For a non-WordPress front end

The same answer is available at
`GET /wp-json/talenttrack/v1/teams/{team_id}/dossier-completeness`. It
returns `player_count`, `family_reachable` and one entry per check with
`total`, `complete`, `counts` and a `needs` list naming the players — the
same envelope `GET /teams/{team_id}/measurement-coverage` answers in. It is
gated on a global or team-scoped player read, and it carries no contact
values either.

The academy-wide count is `GET /wp-json/talenttrack/v1/alerts/family-reachability`,
which answers with totals and team names only — no player is named there,
which is why there is still no club-wide dossier route.
