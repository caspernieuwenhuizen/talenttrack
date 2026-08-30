---
title: License & account
group: configuration
summary: Tier, usage caps, and how a plan is set on an install.
audience: [admin]
module: TT\Modules\License\LicenseModule
order: 100
---

# License and account

TalentTrack runs on two plans — **Standard** and **Pro** — plus a **Not activated** state for an install whose plan has not been recorded or has lapsed. Which plan an install is on is decided when it is provisioned, not inside the plugin: there is no checkout here, no license key to paste, and nothing a club admin can toggle to change what their install is entitled to.

## How an install learns its tier

Your TalentTrack operator records the plan when the install is set up. The install keeps a local copy of that answer so it keeps working normally if the operator's systems are briefly unreachable — a plan does not evaporate because a server was down for an afternoon.

Resolution order, first match wins:

1. **Developer override** — only on installs where the owner has configured `TT_DEV_OVERRIDE_SECRET`. See below.
2. **The recorded plan.**
3. **Not activated** — when no plan has been recorded, or the recorded one has gone unrefreshed for so long that it is no longer trusted.

If the Account page says no plan is recorded and that looks wrong, contact your operator. It is a one-line fix on their side and nothing in your data is affected.

## Changing plan

Ask your operator. Your install moves to the new tier in place: the same site, the same URL, the same data, with more room and more features. Nothing is migrated, exported, re-imported, or rebuilt, and there is no downtime.

Going the other way works the same, with one caveat worth knowing: dropping to a tier whose caps you are already over does not delete anything. Existing teams and players stay readable; you just cannot add past the cap until you are back under it.

## Two plans

**Standard** is the academy product. **Pro** adds match day, training, media, the analytics platform and the integrations.

There is no Free plan. TalentTrack is hosted — your club has a subdomain the operator runs — so an install exists because somebody is paying for it. What you will still see named in the account page is *Not activated*, which is the state an install is in before its plan has been recorded or after it has lapsed. It is not something anyone is sold.

### Standard — run the academy

Players, teams, staff. Evaluations with the full category tree, weights and coverage windows. Development plans and the conversation cycle. Goals. The player journey and cohort transitions. The status light and behaviour ratings. Measurements and testing. Trials, prospects and scout access. Attendance and minutes. The standard reports, radar, player comparison and rate cards. Methodology, the planner, holidays, season rollover. Excel and CSV import, backups, translations, custom fields and club branding.

### Pro — everything 2026 added

| | |
| - | - |
| **Match day** | Match analysis and its share link, match preparation, the live match surface, tournaments, and the three match-day PDF exports |
| **Training** | Training plans, the exercise library, per-player training exposure, photo extraction |
| **Media** | The media library — photo and video on a player's record |
| **Analytics** | The dimension explorer, scheduled reports, custom widgets, the persona-dashboard editor |
| **Reaching people** | Scheduled sends, the SMS channel, push notifications |
| **Integrations** | Spond, Strava |
| **Coach development** | Courses |
| **Squad construction** | Team chemistry and blueprint sharing |
| **Bulk entry** | The attendance, minutes and ratings grids |
| **Backup** | Object-storage destinations |

### What is never a paid feature

The audit log, the permission matrix, two-factor authentication, record deletion, the recycle bin, the impersonation log, media consent and subject-access requests are available on **every** plan, including an install that is not activated.

These are how an academy meets its obligations to the children in it. Selling child-data safety as an add-on is not something this product does.

For the same reason, a club whose plan has lapsed keeps the dashboard, player cards, local backup and export. You can always read and take out your own data.

## What a feature you are not on looks like

Three answers, and they are deliberate. Together they are why a plan change never feels like something breaking.

### It is locked, not hidden

A feature your plan does not include still appears where it lives. Open it and you get a panel naming the feature, naming the plan it is on, and linking to the account page — not a missing menu item and not an error.

Hiding it would be tidier and worse. A coach who cannot find match analysis has no way to tell whether the club never had it, whether it was switched off, or whether something is broken. Locked-and-visible answers that question on the spot, and it means the Plan tab's feature matrix matches what people actually see.

### What you already recorded stays readable

Dropping to a plan without a feature does not take away what that feature produced. Old match analyses, old training plans, old media stay readable and exportable exactly as they were. What stops is writing new ones — a save, an upload, a create button.

So a club that moves from Pro to Standard loses capability, never history. Nothing is deleted, hidden, or held back, and nothing needs restoring if the club moves back.

### "Not on your plan" and "not allowed" are different answers

Two different refusals exist and they never share a sentence:

| You see | What it means | What fixes it |
| - | - | - |
| A locked panel naming a plan | The install is not on that plan | Ask your operator about the plan |
| A permission message | Your role does not have this, on any plan | Ask an academy admin about your permissions |

An integration reading the API sees the same split: a plan refusal comes back as HTTP **402 Payment Required**, a permission refusal as **403 Forbidden**. If something fails and you cannot tell which happened, that is a bug worth reporting.

### What that looks like, feature by feature

The match-day and training features enforce the three answers above. What a
Standard club can and cannot do on each:

| Feature | Standard can | Standard cannot |
| - | - | - |
| **Match analysis** | Read and export every analysis already written | Write or edit one |
| **Match preparation** | Read and print a plan already made | Start a new plan, or change an existing one |
| **Live match** | Read the result, minutes and events of matches already run | Open the live console to run another |
| **Tournaments** | Browse every tournament, its matches, squads and totals | Create, edit or plan one |
| **Auto-balance** | Plan a tournament grid by hand | Have the grid filled automatically |
| **Training plans** | Read every plan the club built, and its history | Build a new plan, or run one |
| **Exercise library** | Browse and search every exercise | Add, edit or import exercises |
| **Media** | See, play and download every photo and video the club holds — and **delete** them | Upload anything new, or attach an existing item to another record |

The media row is the shape of the whole set: *the club keeps every photo it
has, and cannot add more.* Deleting is never refused over a plan — removing
a child's photo is an obligation, not a feature.

### Channels and integrations

These cost money every time they run, so they are priced and they refuse
where they would otherwise spend.

| Feature | Standard can | Standard cannot |
| - | - | - |
| **SMS** | Send by email, in-app, push-free WhatsApp links | Use SMS as a channel — it is not offered in the channel picker at all |
| **Push notifications** | Receive the same notifications by email | Have them delivered as phone push |
| **Scheduled sends** | Every event-driven message — invitations, account mail, the ones a click causes | The four daily nudges (goal, attendance, inactive parent, staff review) |
| **Photo to plan** | Build a training plan by hand, as always | Have a photographed plan read for you |
| **Spond** | Read every fixture already imported — they are ordinary activities | Sync again |
| **Strava** | Read every activity already shared, on the player's record | Connect another player |
| **Object-storage backup** | Local and email backup destinations | An S3-style destination *(not built yet)* |

Two of these run in the background where nobody is watching, so a refusal
is **written down** rather than shown: the scheduled-send refusal appears
against each nudge in the message log's health record, and a refused Spond
sync appears in that team's sync history. Both name the plan, so it reads as
a plan question rather than as something broken.

Nothing already imported is touched. Spond fixtures and Strava activities
stay exactly where they are, readable and exportable, and come back to life
if the plan changes.

### Screens you can see and not open

Seven surfaces render locked. That is the decision working as intended: the
tile stays where it is, and opening it explains itself.

| Feature | Standard can | Standard cannot |
| - | - | - |
| **Analytics explorer** | Every standard report, every dashboard figure — they read the engine directly | Ask an ad-hoc question of it |
| **Custom widgets** | See the widgets the club already built, on the dashboards they sit on | Build or edit one |
| **Dashboard layouts** | Use the layouts already saved | Edit them |
| **Courses** | See which courses exist and what they cover | Open or complete one |
| **Attendance grid** | **Record attendance**, one activity at a time | Enter a whole week's squad in one screen |
| **Minutes grid** | **Record minutes** per activity; a live match still writes them | Correct a whole squad in one screen |
| **Ratings grid** | **Rate a player** from their profile and from the activity | Rate a whole squad in one screen |

The three grids are worth reading twice. They are the fast desktop way to
enter a squad's worth of data — **not the only way to record it**. Attendance,
minutes and ratings are all Standard features and stay exactly where they
were; what the plan buys is doing twenty of them at once. Each locked grid
says so on the panel, because "attendance is a paid feature" would be false.

Courses lock at the module's own gate rather than at a screen, so the course
list, a lesson page and the API all give the same answer — and the courses
stay **listed**, so a club can see what the plan would open.

## Usage limits

Player count, team count and storage are **priced against what they cost to run**, not bundled into the plan. A large Standard club can cost more than a small Pro one, and that is deliberate — the plan says which features you have, the size of your academy says what it costs to host.

The caps in `FreeTierCaps` (1 team, 25 players) are now demo furniture: they are what stops the public demo subdomain being used as a free academy. They are configured on the demo install and nowhere else.

## Account page

Clicking **TalentTrack** in the wp-admin sidebar lands on the Account page. It has three tabs:

| Tab | Cap | What's there |
| - | - | - |
| **Account** | `tt_edit_settings` (operators only) | Current tier, usage versus caps, what the next tier adds, phone-home diagnostics |
| **Plan & restrictions** | `read` (everyone logged in) | Current plan, caps table with at-cap warnings, and the full Standard / Pro feature matrix with your effective tier highlighted |
| **MFA** | `read` (everyone logged in) | Your own two-factor enrollment and backup codes |

The Plan tab is open to everyone deliberately: a coach who cannot find a feature should be able to see for themselves whether it is missing or merely locked.

## Non-commercial test instances

`TT_COMMERCIAL_MODE` in `talenttrack.php` decides whether any of this is enforced.

When it is `false` — the default, and the case on every developer and demo install — the install is a **non-commercial test instance**: every feature is unlocked, caps do not apply, and the Account page renders a single explanatory notice instead of the plan UI. When it is `true`, the resolution order above applies.

## Developer tier override (owner-only)

For demos and local testing without provisioning a real plan.

**One-time setup on your demo / dev install:**

1. Generate a bcrypt hash of a password you'll memorize. In a PHP shell:
   ```php
   echo password_hash( 'your-password-here', PASSWORD_BCRYPT );
   ```
2. Add to `wp-config.php`:
   ```php
   define( 'TT_DEV_OVERRIDE_SECRET', '$2y$10$....your-hash-here....' );
   ```
3. Visit `wp-admin/admin.php?page=tt-dev-license` (no menu link — type the URL).
4. Enter your password, pick a tier, click Activate.

The override is stored as a 24h transient. A "🔓 DEV: Pro" pill appears in the wp-admin top bar so you remember it's on. Re-visit the URL to clear it early.

**Customer installs never see this code path** — without the constant defined, the admin page 404s and the gate ignores the override entirely.
