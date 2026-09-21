---
title: Player dashboard (frontend)
group: frontend
summary: What players see when they log into the frontend shortcode.
audience: [user]
views: [overview, profile, my-development, my-team, my-evaluations, my-activities, my-goals]
order: 10
---

# Player dashboard

When you log in as a player you land on a tile grid scoped to you. Each tile opens one part of your own profile. Use the **← Back** link at the top of every page to return here.

## At a glance

Above your tiles you'll see a row of small **progress cards** drawn from your own data:

- **My rating trend** — your rolling evaluation average and how it moved since last month.
- **My activities attended %** — your attendance over the last four weeks.
- **My evaluations received** and **My goals completed** — running totals.
- **My PDP conversations done** — how many development conversations you've had.
- **My next milestone** — your nearest goal and its due date.

A card shows a dash until there's data behind it (e.g. before your first evaluation). When your academy has turned on visible team ranking, a **podium position** card appears here too.

If a coach has left you a note — player-facing feedback on an evaluation, or a comment on one of your goals — it shows in a **"A note from your coach"** card. The card only appears when there's something new.

The **My check-ins** tile is where your weekly self-evaluation and any other tasks land — it's the one place the academy asks something *of* you.

## Your tiles

### My development
Your **development home** — one page that pulls your whole development picture together so you can see *what to do now* at a glance. It's the first tile in the group. It opens with your player hero, then a **Today** band driven by your PDP cycle (prepare for an upcoming talk, review a talk you've just had, or simply your next-talk date), followed by short previews of **Your focus** (top goals), **How you're doing** (your rating and momentum), **Your playing time** (below), **Coming up** (next activities), and **Your journey** (your latest milestone). Each block has a **see all** link into the matching deep view, and inside the focus, coming-up and journey blocks each listed goal, activity and milestone is itself tappable — open it directly and a **← Back to My development** pill brings you straight back. (Evaluations open via their **see all** link only.) The seven deep-view tiles below (My profile, My team, My evaluations, My activities, My goals, My PDP, My journey) stay exactly as they are — the home is an anchor, not a replacement. The first time you open it, a short **welcome** card greets you (a parent sees a version for their child); tap **Got it** to dismiss it for good.

Parents who open their child's dashboard land on **&lt;Child&gt;'s development** — the same home, read-only and scoped to their own child.

#### Your playing time

How many minutes you have actually played over the past twelve months, the number of matches they came from, and your three most recent matches with the minutes you got in each. Tap a match to open it.

The figures are the ones your coach sees — they come from the same recorded minutes the academy's minutes report reads, so your total and your coach's always agree. They are **your minutes only**: no percentage of the team's playing time, and never a team-mate's figure. Minutes appear once a coach has recorded the match; a match nobody has recorded yet counts as nothing rather than as a guess.

If you are not currently in a team, the block says you have no minutes recorded rather than showing an error.

### My profile
**My profile** now opens your **player profile** — the same unified, tabbed profile your coach sees, framed for you — and lands on the **Player card** tab. That tab shows your FIFA-style player card, your skills radar, and your rating KPIs (latest, last 5 with its trend, all-time, evaluations). A **Print report** action on the card gives a clean printable version.

The other tabs hold the rest of your picture: **Profile** (your playing details — position, foot, jersey, status — and academy info), **Goals**, **Activities**, and **Strava**. You only see what's yours: coach-only surfaces (evaluations workbench, PDP, trials, notes, the guardians and discovery cards, and staff flags) don't appear on your view. A parent opening their child's card sees the same profile for that child.

> Account details (display name, email, password) live in **My settings** under the username dropdown in the top-right corner — see below.

### My team
The team podium leads — top 3 players by rolling rating, gold/silver/bronze. Below the podium your own player card sits with a **personal growth trend** — how your rolling rating has moved since last month and the skill category you're improving most. By default you do *not* see a numeric "#N of M" team rank: academies can turn that on (Configuration → Rating scale → "Show each player their team rank"), and when on it appears alongside the trend. Either way, no other teammate's rank is ever shown — only the podium positions are public. The team's **next match** and a **recent results** form line (win/draw/loss, most recent first) also appear — non-sensitive team info, with no individual teammate ratings. Tapping a podium player opens that teammate's **minimal team profile** — the same authorised page the roster links use, not a coach-only profile. The teammate roster at the bottom shows names + photos only; tap a teammate to see their basic playing details (position, jersey, foot, height, weight). Their evaluations, goals and ratings stay private.

**Parents see the same page without the podium.** A parent opening their child's team gets the next match, the form line, their child's own card and growth trend, and the teammate roster — but not the top-three ranking. The podium is a dressing-room thing for the players in it; handed to another family's parent it becomes a league table of other people's children, so it stays with the players.

Throughout these pages, a parent is addressed as the parent: headings read "Bas's goals" and "How Bas is doing" rather than "My goals" and "How you're doing", and the parent's navigation drops the "My" from the labels that are about their child.

### My evaluations
Every evaluation a coach has recorded for you, most recent first. Each row shows the date, the type, the coach, and the ratings — compact by default, expand a row to see the full subcategory breakdown. The type is shown in your own language, using whatever label your academy gave it. A match evaluation also names the opponent, with the score in brackets when one was recorded and nothing in brackets when it wasn't.

### My activities
Two halves: what is coming up, and what you turned up to.

**Coming up** sits at the top — your team's next few activities, soonest first, each with the day and the time it runs (`18:30–20:00`, or just the start when no end has been set). Tap one to open its detail. It carries no attendance, because a training that has not happened yet is not something you have been to; if your coach has already picked a squad, that is their decision to tell you, not something this screen announces.

Below it, your **history**: the trainings, games and other activities you have attended — only your own, never another player's — most recent first, up to today. Search by title or location, narrow by date range, sort by date, title or team, and step through pages when there are more than fit on screen. The **Your status** column shows your recorded attendance per activity — present / absent / late / injured / excused — and stays blank where nothing has been recorded. Tap any row to open **My activity detail** — date, times, type, location, the coach's notes on the activity, your attendance status, and any per-row note your coach left for you.

The detail names the times your coach saved, so you can see when to be there without asking. A **presence time** shows whenever one is set. A game then reads **kick-off** and, when it is filled in, an **end time**; a training, tournament or other activity reads a single **time** covering the whole window. An activity with no times saved shows none — there are no empty placeholders. These are the same times staff see on the same activity.

**Show upcoming**, just above the list, turns the history around: everything still to come, soonest first, with the date filter working the same way. **Show what has happened** turns it back. Which mode you are in is in the address, so a link to "everything coming up" can be sent to somebody else. This is how you reach a session that is weeks away — **Coming up** only holds the next few, and a fixture beyond those used to be in the product and on no screen you could get to.

Widening the date range yourself shows activities beyond today again; they simply carry no attendance until one is recorded.

While your history loads, the list shows **Loading…**. The "nothing recorded yet" message only appears once loading has worked and there really is nothing. If loading fails, for example on a bad connection, you see an error with a **Retry** button; tap it to try again. The same goes for every list in TalentTrack, not just this one. The list needs JavaScript.

### My goals
The development goals your coaches have set for you, grouped by status. Tap a goal to open it: read the full description, see priority + due date, and join the conversation thread (you can post comments and your coach + parents will see them).

### My journey
A vertical timeline of your milestones, evaluations, injuries, age-group transitions and other life events at the academy, oldest at the bottom. Use the filter chips at the top to narrow down the events shown.

### My PDP
Your personal development plan — the long-form version of your active goals, with multi-conversation history, agreed actions, and the season verdict. Available where the PDP module is enabled.

## My settings (top-right username dropdown)

Click your name in the top-right corner of the dashboard to open the user menu. The first item, **My settings**, is a TalentTrack-rendered settings screen — it never bounces you out to the WordPress admin. It contains only what end users actually need:

- First name + last name + display name (how your name shows up to coaches and teammates)
- Email
- Phone number
- Change password (with current-password confirmation)

Application passwords, admin colour palettes and other WordPress-internal toggles stay in wp-admin where they belong; ignore them unless you're managing your own developer integrations.

### Your phone number

Type the number the academy should use to reach you, starting with the country code — `+31 6 12345678`, not `06 12345678`. A number without its country code is refused and whatever you had saved before stays as it was, so a half-typed entry can never quietly erase a working one. Leave the box empty and save to remove the number altogether.

You own this number: nobody has to approve it, and it takes effect as soon as you save. It is the number the academy's messages go to, and it appears on your child's file for coaches and academy staff — not for other parents, and not for other players.

### What your parent can see

If you have a parent or guardian linked to your account, **My settings** also shows a **"What your parent can see"** card. Everything is shared by default — but you can turn off individual sections (**Evaluations**, **Goals**, **Journey**, **Measurements**, **Playing time**, **Development plan**, **Training history**) so your parent no longer sees them. The change applies everywhere your parent looks, including the development home previews. When you hide a section, your parent sees a calm "kept private" note instead of the section — never an error. Your coaches and the academy are not affected by these choices, and safeguarding/medical information stays governed by separate academy rules.

## Reports

When a coach shares a report with you, it appears under **Reports** on your profile. A parent finds it under **Reports** on their child's profile. The newest is at the top, and each one opens the report as it was when the coach shared it.

A shared report shows attendance, playing time, goals, evaluation scores and tests. It never includes the coach's written notes, and it follows the same choices as the rest of your profile: a section you keep from your parent is left out of their copy.

You can't make a report yourself. Every report here is one a coach chose to share with you. Coaches make and share them from the player report; see [Player report](player-report.md).

## Privacy

You only see your own data. Other players' evaluations, goals and personal info stay private.

## On a phone

The dashboard works on phones, tablets and computers. On a phone the tiles stack into a single column and tables scroll sideways when needed.
