# Commercialization backlog — parked

**Audience:** operator / business. Not a development backlog.

This document is the home for the commercialization work that is **not
plugin development**: hosting infrastructure, fleet operations, legal
execution, billing, the sales funnel, and marketing collateral.

It exists because the issue board tracks development of the TalentTrack
WordPress plugin. Business and operations work sat there for a while
because the board was the only tracker available; it was never queue
work, and every item below carried an explicit *"not a `ready-for-dev`
item"* marker. Keeping it on the board made the development queue read
as larger and more blocked than it is, and invited a code agent to pick
up work that has no code in this repo.

The GitHub issues listed below were closed on 2026-08-27 and their
substance moved here. Nothing was dropped. If one of these items later
grows a genuine plugin-code component, file that component as its own
issue — scoped to the code, not to the business problem.

**A note on how to read the open questions.** Eight decisions were locked
on 2026-08-26 as a comment on the epic, but the child issue *bodies* were
never updated to absorb them — so those bodies still listed questions
that had already been answered. The decisions are reproduced below and
folded into each section; where a section says *"Settled by the
2026-08-26 decisions"*, that is the correction. Keep this document as the
single record so the same drift does not happen again.

---

## The model — decided 2026-08-26

**Managed hosting.** Each club gets its own WordPress install on a
subdomain of our own site (`<club>.<domain>`); MediaManiacs runs it.
Clubs never receive a plugin artifact, never install anything, never
manage WordPress.

What that settles:

- **The public repo is not a commercial risk.** Customers never receive
  the zip, so "anyone can download the paid product" no longer describes
  a lost sale. Repo visibility is a hygiene question, not a revenue one.
- **No in-plugin checkout.** Checkout only made sense when the customer
  owned the install. On a hosted install the operator knows what the club
  bought and sets it directly.
- **`club_id`-always-1 stays correct.** One install per club means one
  tenant per database. The plugin has zero `is_multisite()` /
  `switch_to_blog()` references — isolation comes from separate installs,
  not from in-app scoping.

What it does not settle: pricing numbers (deferred), and the relationship
to the `talenttrack-saas` port, which continues as the long-term rewrite.
Hosted-WP-per-club is the near-term revenue path.

## Decisions locked — 2026-08-26

| # | Decision |
|---|---|
| 1 | **Club subdomains hang off `mediamaniacs.nl`.** Trust and product pages at `mediamaniacs.nl/talenttrack/{privacy,security}`. The SaaS port keeps `.online`, so a later migration changes a club's URL. Clubs share the agency namespace, so provisioning needs a reserved-name list. DPA and trust pages are **Dutch**, English on request. |
| 2 | **One VPS, many WordPress installs.** All hosting operations are ours. |
| 3 | **Three tiers stay, Free included — and Free is the pilot vehicle.** No separate paid pilot. *(Superseded 2026-08-27 — see below.)* |
| 4 | **SLA: 99.5% uptime, one business day support response.** |
| 5 | **Isolation: separate MySQL database + scoped database user, and a separate system user / PHP-FPM pool, per install.** |
| 6 | **MediaManiacs signs the DPA and issues invoices** — no new entity. |
| 7 | **Billing: invoice + iDEAL / SEPA via Mollie.** Annual, aligned to the season. |
| 8 | **Entitlement is control-plane-owned and cached per install** — never a constant baked into `wp-config.php`. |

**Decision 8 is the constraint that protects the SaaS move.** Managed
hosting and SaaS are the same commercial shape: a customer has a
subscription, and an environment exists because that subscription does —
only the environment differs (a WordPress install now, a Postgres tenant
later). So the control plane owns customers, subscriptions, plans,
entitlement, provisioning and de-provisioning, and is written once. A
club's install *caches* its entitlement with a TTL and a grace window, so
it keeps working when the control plane is unreachable, but never owns
the answer. This is also why Freemius was retired rather than finished —
its SDK is WordPress-coupled, so completing it would have meant building
billing twice and migrating live subscriptions between them.

**A single VPS is a single point of failure, so decision 4's 99.5% is a
recovery-time commitment, not a redundancy one.** Roughly 3.5 hours of
allowed downtime a month on infrastructure with no failover. Off-server
backup and a rehearsed rebuild are what the SLA actually rests on — § 2
needs a stated RTO and RPO, not just "backups configured".

## Tier model — revised 2026-08-27

**This supersedes decision 3.**

- **Two tiers: Standard and Pro.** Free stops being a sellable tier — a
  club either pays for hosting or has no install. Free survives only as
  demo-subdomain furniture, and `FreeTierCaps` (1 team / 25 players) with
  it.
- **Usage allowances are bundled per tier, with overage billed.** Each
  tier ships a generous included allowance for players and media storage;
  a club that exceeds it is billed for the overage rather than blocked.
  This protects a club whose season-start media upload would otherwise
  break their install.

**Consequence that needs resolving: the pilot has lost its vehicle.**
Decision 3 made Free the pilot — a club ran on a Free install for a
season and converted. With Free removed as a tier, § 6's pilot path needs
a new mechanism: a time-boxed full-product install, a paid pilot, or a
first-year discount. This is the one thing today's reversal reopened, and
it should be settled before § 6 is picked up.

The upside of the reversal is that it removes the cost centre decision 3
created — every Free install consumed VPS resources, backup storage and
support attention indefinitely, on infrastructure that is now known to be
a single VPS.

The code half of the tier work — rewriting `FeatureMap::DEFAULT_MAP`,
adding the missing `LicenseGate` call sites, regenerating the
`docs/license-and-account.md` matrix — **stays on the development board**
as the one remaining code issue in this area.

## What already shipped

- Licence enforcement — `LicenseGate`, `FeatureMap`, `FreeTierCaps`,
  `DevOverride`, and the Account page with tier / usage / upgrade UI. 91
  gate references across 37 files in `src`.
- **The entitlement layer from decision 8**: `Entitlement`,
  `CachedEntitlement` and `EntitlementSourceInterface`. `FreemiusAdapter`
  and `TrialState` were **deleted** — neither exists any more, so neither
  can be cited as precedent.
- One clean switch: `TT_COMMERCIAL_MODE` at `talenttrack.php`, currently
  `false`. Every install today runs unlocked Pro.
- Fleet telemetry: `docs/phone-home.md` — install_id, version,
  team/player counts, DAU/WAU to an Admin Center endpoint.
- A drafted legal pack: `marketing/security/dpa-template.md`,
  `privacy-policy.md`, `security-page.md`, plus a GDPR operator guide.

---

## 1. Provisioning pipeline — signed club to running subdomain

*Was issue #2924. Infrastructure work outside this repo.*

Under managed hosting, "sell a club" and "stand up an install" are the
same moment. Today that moment is entirely manual —
`docs/go-live-runbook.md` is a careful human checklist for one install,
and the right seed for automation, but it assumes someone already has a
WordPress install in front of them. Nothing turns a signed club into a
running `<club>.<domain>`.

**What to build.** A repeatable pipeline taking a club name and a tier to
a working install:

1. **DNS + TLS** — subdomain record, wildcard or per-host certificate.
2. **WordPress install** — files, database, `wp-config.php`, salts, admin
   account. The tier is **not** written here: decision 8 makes entitlement
   control-plane-owned and cached per install, so provisioning registers
   the install against the control plane rather than baking a constant in.
3. **Plugin install + activation** — a pinned version from a release
   channel, migrations run and verified green.
4. **Seed** — the setup wizard's outcome, non-interactively: persona
   dashboard page, seasons, age groups, lookups, the club's teams.
   `docs/setup-wizard.md` and the DemoData module already do most of this.
5. **Branding** — club logo and colours via existing branding config.
6. **Handover** — admin invitation to the club's HoD, plus the go-live
   checklist items that remain human.

Then the inverse, which matters legally as much as the forward path:
**de-provisioning** — export the club's data, confirm receipt, destroy
the install and its backups on a defined clock.

**Done when.** A club goes from signed to "HoD can log in" without a
human running SQL or editing files. The result passes the blockers in
`docs/go-live-runbook.md` — licence state pinned, backups configured,
zero pending migrations, media folder not directly servable. Re-running
is idempotent or refuses clearly. De-provisioning exists, produces a
machine-readable export, and deletes on a documented clock. The pipeline
is documented well enough to be run by someone who is not its author.

**Settled by the 2026-08-26 decisions.** Hosting stack is **one VPS,
many WordPress installs** (decision 2). Database isolation is **separate
database + scoped database user per install**, plus a separate system
user / PHP-FPM pool (decision 5). The subdomain shape is
`<club>.mediamaniacs.nl` (decision 1), which means clubs share the agency
namespace and provisioning needs a **reserved-name list**.

**Open questions.**

1. **Plugin artifact source** — public GitHub release, or a private
   build? No longer a revenue risk, but pulling production installs from
   a public release deserves a deliberate decision.
2. **Who runs it** — a script on a laptop, a CI workflow, or an action in
   the Admin Center? The Admin Center already receives phone-home from
   every install and is the natural console, and under decision 8 it is
   also where entitlement lives — which argues for the control plane
   owning provisioning too.
3. **The exact subdomain shape** under `mediamaniacs.nl`, and the
   reserved-name list that goes with it.

---

## 2. Fleet operations — rollout, backups, monitoring, tested restore

*Was issue #2925. Operations work, not code in this repo.*

Selling hosting means selling uptime, updates and recovery. Today's
operational documentation is written for one install operated by the club
that owns it (`docs/go-live-runbook.md`, `docs/backups.md`). Under
hosting, every one of those responsibilities transfers to us, multiplied
by the number of clubs, and a mistake affects someone else's academy
mid-season.

The observability half already exists: `docs/phone-home.md` delivers
per-install version, WordPress/PHP/DB versions, team and player counts,
and DAU/WAU on a daily tick plus on activation and version change. That
is a fleet dashboard's data feed, already shipping.

**What to build.**

- **Update rollout.** Releases currently go to GitHub and installs pull
  them. Across a paying fleet that needs staging: a canary install first,
  then the rest, with a rollback path. Migrations are the risk — a failed
  migration on a club's live install during a season is the worst
  operational outcome available.
- **Backups, per club.** `docs/backups.md` is explicit that the plugin's
  table export is *not* a full-site backup — player photos live in
  `wp-content/uploads/` and users in `wp_users`. Host-level file +
  database backup for every club is ours to run, with off-server
  retention and a restore that has actually been performed at least once.
- **Monitoring and alerting.** Uptime per subdomain, error-rate signal,
  and the phone-home gap as a health check — an install silent for 48
  hours is a problem we should know about before the club calls.
- **Mail deliverability.** Every club sends from a subdomain. SPF/DKIM/
  DMARC per sending domain, bounce handling, and a sender reputation one
  club's bulk invite cannot wreck for the others.
- **Support process.** What a club does when something breaks, what we
  commit to, and how impersonation (`docs/impersonation.md`) is used and
  logged when we look at their data. That last one is a privacy boundary,
  not a support convenience.

**Done when.** A documented rollout procedure with canary, verification
and rollback. Automated off-server file + database backups per club with
a documented retention window. A restore performed end-to-end at least
once, written down. Uptime and phone-home-gap alerting reaching a human.
A support intake path the club's HoD knows about.
`docs/go-live-runbook.md` generalised or forked into a fleet runbook.

**Settled by the 2026-08-26 decisions.** The SLA is **99.5% uptime, one
business day support response** (decision 4). On a single VPS with no
failover that is a recovery-time commitment, so this section owes a
stated **RTO and RPO** from a rehearsed rebuild — "backups configured" is
not enough to stand behind the number.

**Open questions.**

1. What RTO and RPO does a rehearsed rebuild actually achieve? The SLA is
   already promised; this is the engineering that has to meet it.
2. Do all clubs run the same plugin version, or can a club be pinned
   during a critical period (a tournament weekend, a trial cycle)?
   Pinning is kind to the club and expensive to support.
3. Does the Admin Center become the fleet console, or is off-the-shelf
   monitoring the faster answer?
4. Season boundaries are the natural maintenance window for an academy
   product — should rollouts be scheduled against the football calendar
   rather than a fixed cadence?

---

## 3. Cross-club isolation review

*Was issue #2926. Depends on the hosting-stack decision in § 1.*

`CLAUDE.md` § 1 is unambiguous: "No player data leaks across academies,
age groups, or unauthorized roles." Until now that has been a
single-install concern. Managed hosting puts several clubs' minors'
records on infrastructure we operate, making cross-club isolation an
infrastructure property rather than an application one.

The plugin is genuinely single-tenant — zero `is_multisite()` or
`switch_to_blog()` references in `src`, and `club_id` scaffolded but
always 1. There is no in-app code path that could serve club B's data to
club A, because the application has no concept of a second club. The
isolation boundary is entirely below the application — which is exactly
why it needs reviewing rather than assuming. The risks live in the shared
layers.

**What to review.**

- **Database.** Separate per club, or shared with prefixes? If shared,
  whether each install's database user is scoped to its own prefix — an
  install whose credentials can read a sibling's tables is one SQL
  injection away from a cross-club breach.
- **Filesystem.** `wp-content/uploads/tt-media/` holds player
  photographs. Whether one install's PHP process can read another's
  uploads directory.
- **Backups.** Where per-club backups land, who can read them, and
  whether one club's restore can touch another's data.
- **Operator tooling.** Provisioning and the Admin Center reach across
  every club by design. Legitimate, and needs to be a named, documented,
  audited channel rather than an implicit one.
- **Media serving.** `docs/go-live-runbook.md` § 5b flags that the
  deny-all rule on the media folder works on Apache and does nothing on
  nginx, leaving TalentTrack's own permission check as the only
  protection. Under hosting the web server is our choice, so this is ours
  to get right rather than a caveat we warn a customer about.

**Done when.** The isolation boundary is written down, layer by layer. An
install's database credentials cannot read another install's tables. An
install's process cannot read another install's uploads. The operator's
cross-club access is named, minimised and logged. Media-folder protection
is verified on whatever web server the stack actually uses. The result
feeds the DPA's technical and organisational measures annex, which
currently describes a self-hosted world.

**Settled by the 2026-08-26 decisions.** Decision 5 chose **separate
MySQL database + scoped database user, and a separate system user /
PHP-FPM pool, per install** — so both the database and the filesystem
questions below are answered by design rather than by review. What
remains is verifying the implementation matches, and that the PHP-FPM
pool boundary actually holds for `tt-media/`, which is the sharper of the
two risks.

**Open questions.**

1. Does the operator's cross-club access appear in the club's own audit
   log, or only in ours? The impersonation log already sets a precedent
   for logging operator access in the club's view.
3. Does a club get to see where its data lives and who can reach it?
   Publishing that is a trust asset for exactly the customer who asks
   hard questions about child data.

---

## 4. Execute the legal and trust pack

*Was issue #2927. Legal and business work, not code. Hard gate on the
first paid club.*

Under managed hosting, MediaManiacs is the data processor *and* the host
for records that are mostly minors' data. No club with a competent board
signs that without an executed DPA.

The drafting is largely done. `marketing/security/dpa-template.md`
(17KB), `privacy-policy.md` and `security-page.md` exist and are
structured. What is missing is execution:

- The DPA carries an explicit banner: *"Draft pending legal review. Do
  not execute until reviewed."*
- The processor block has `[Registered address — fill in]` and
  `KvK: [number — fill in]`.
- `docs/privacy-operator-guide.md` tells academies the DPA, sub-processor
  list, hosting region and personal-data column table live at
  `mediamaniacs.nl/talenttrack/privacy`. The `docs/` references were
  repointed there by the domain decision, so the URL is now correct — but
  **the page still has to be published** before that sentence is true.
- The security and privacy documents were written for a self-hosted
  product. Under hosting the answer to "where is the data and who can
  reach it" changes completely, and so does the sub-processor list — the
  hosting provider, mail provider and backup target all become named
  sub-processors.

**What to do.** Legal review of the DPA against the hosted topology, not
the self-hosted one it was drafted for. Fill in registered address and
KvK. Write the sub-processor list — the VPS provider, the mail provider
and the off-server backup target — with a notification process when it
changes. State the hosting region (EU, and specifically where). Rewrite
the technical and organisational measures annex against § 3's findings.
Terms of service and an SLA carrying decision 4's numbers, plus what
happens to data on termination and the de-provisioning clock. **Publish**
the trust pages at `mediamaniacs.nl/talenttrack/{privacy,security}` —
`docs/` already points there, so the links are dead until the pages
exist. Decide the retention defaults we commit to as processor, distinct
from the retention policy each academy sets as controller.

**Done when.** A DPA reviewed by counsel and ready to counter-sign, no
placeholders, in Dutch. A published sub-processor list matching the real
stack. Terms and an SLA stating 99.5% and one business day. Live privacy
and security pages at `mediamaniacs.nl/talenttrack/`, every `docs/`
reference resolving. A documented breach-notification path with a named
responsible human and a clock. Data export and deletion on termination
documented and matching what de-provisioning actually does.

**Settled by the 2026-08-26 decisions.** **MediaManiacs signs and
invoices** — no new entity (decision 6). The **DPA and trust pages are
Dutch**, English on request (decision 1) — which means the Dutch is the
version that gets signed and therefore the version that needs the legal
review. The SLA to quote is **99.5% / one business day** (decision 4).
Trust pages publish at `mediamaniacs.nl/talenttrack/{privacy,security}`,
and `docs/` has already been repointed there.

The sub-processor list can now be drafted, because the stack is known:
the VPS provider, the mail provider and the off-server backup target.

**Open question.**

1. Is a DPIA needed for the hosted service as a whole?
   `docs/photo-capture-dpia.md` exists for one feature; hosting minors'
   records as a service is a broader processing activity.

---

## 5. Subscription billing and the non-payment lifecycle

*Was issue #2928. Business operations, not code in this repo.*

Under managed hosting, billing leaves the plugin entirely. There is no
checkout inside a club's install; there is a subscription between
MediaManiacs and the club, and an install that exists because that
subscription does. Nothing for this exists today.

**What to decide and build.**

- **Payment mechanism — decided.** Invoice + **iDEAL / SEPA via Mollie**
  (decision 7). A Dutch amateur club runs on a treasurer and a bank
  transfer, not a card form. Still to build: Dutch VAT handling and an
  invoice a club treasurer can file.
- **Subscription lifecycle — decided.** **Annual, aligned to the season**
  (decision 7). A club's budget year and its season are the same cycle,
  so renewal is a seasonal conversation rather than a silent charge.
- **The non-payment path.** The part that needs care, because the data
  belongs to children and the club is the controller. A reasonable
  ladder: reminder → grace period with full access → read-only → export
  offered → de-provision on a stated clock. Note that `TrialState`, which
  used to model a version of this shape, was **deleted** by the Freemius
  retirement — the read-only step has to be built on `Entitlement` /
  `CachedEntitlement`, and there is no existing precedent in the code to
  copy. What must never happen is a club losing access to its players'
  records because an invoice was missed by a volunteer treasurer in July.
- **Where the state lives — decided.** The control plane owns the
  subscription and the entitlement it implies (decision 8); the install
  caches it with a TTL and a grace window and never owns the answer. One
  place answers "should this install exist, at what tier", and it
  connects to provisioning (create) and de-provisioning (destroy).

**Done when.** A payment and invoicing path a Dutch amateur club will
actually use. VAT handled correctly, with invoices satisfying a club
treasurer and our own accounting. A written non-payment ladder with
stated timings, ending in export-then-delete rather than silent data
loss. Subscription state connected to provisioning and entitlement — one
place answers "should this install exist, at what tier". Renewal is a
deliberate seasonal conversation, not a surprise.

**Open questions.**

1. How long is the read-only grace before de-provisioning, and does the
   club get an automatic export at the read-only step rather than having
   to ask?
2. Is there a setup or onboarding fee? Provisioning plus data migration
   plus training is real work, and pricing it separately protects the
   recurring number. Folded into § 8.

---

## 6. Demo subdomain and the pilot-to-subscription path

*Was issue #2929.*

The strongest asset the product already has for selling is the one the
pitch leads with: *"I'll install it live on your laptop in 30 minutes,
with your age groups and your coaches' names."* Managed hosting makes
that dramatically cheaper — it becomes a URL, not a laptop visit.

Most of the machinery exists. The DemoData module generates a realistic
Dutch academy and wipes cleanly; the coverage epic took it to 70
generated tables with a manifest and CI gates. `docs/demo-data.md` and
the setup wizard cover the rest. What is missing is the funnel: a URL a
prospect can reach without a sales call, and a defined path from that to
a provisioned club.

**What to build.**

- **A demo subdomain** seeded with generated data, reset on a schedule so
  a prospect who mangles it does not spoil the next viewing. Ideally with
  a persona switcher so a club can see the HoD, coach and player views
  without three logins — the personas seed already exists.
- **A pilot path.** The distinction that matters commercially: a *demo*
  is our data, a *pilot* is their data. A pilot means a real provisioned
  install with the club's own teams and players, which is where a club
  decides whether this is real. It is also the point at which the DPA has
  to be signed, because from the first real player record we are
  processing minors' data.
- **The conversion moment.** At the end of a pilot the install continues
  under a subscription rather than being rebuilt — a strong advantage
  over the self-hosted trial model, because nothing is lost at
  conversion.

**Done when.** A demo URL reachable without a sales call, seeded with
generated data, resetting on a schedule and unable to leak into any real
club's install. The demo makes it obvious the data is fictional. A
defined pilot path: provisioned install, club's real data, DPA signed
before the first real player record. A pilot converts to a subscription
without rebuilding or migrating anything.

**The pilot needs a new vehicle — this is the live question here.**
Decision 3 made the Free tier the pilot: a club ran on a Free install for
a season, with no separate paid pilot. The 2026-08-27 tier revision
removed Free as a tier, so that mechanism is gone and the pilot path has
nothing to run on. Options: a time-boxed full-product install on
Standard, a paid pilot credited against the first year, or a first-season
discount. **Settle this before picking § 6 up** — it also feeds § 5's
subscription lifecycle and § 8's pricing.

**Open questions.**

1. What replaces Free as the pilot vehicle? (Above.)
2. Is the demo self-serve (open URL) or gated behind a form? Self-serve
   reaches more clubs; a form gives a contact to follow up. For a product
   sold to a handful of clubs a year, the form probably wins.
3. How long is a pilot? A season is the honest answer for a product whose
   value shows up over a season, but it is a long sales cycle to carry.
4. Does the existing DemoData preset make a good demo, or does the sales
   demo want a curated dataset — a specific player with an interesting
   journey to click through, rather than uniformly generated rows?

---

## 7. The pitch sells self-hosted; the product is hosted

*Was issue #2931. Last in the sequence — collateral cannot be rewritten
until tiers, domain and pricing settle.*

The pitch materials sell the opposite of the decided model.
`marketing/pitch/onepager.md` leads its differentiation section with
*"Your data, your hosting, your control. WordPress plugin — install,
activate, done. No vendor lock-in."*, and under Pricing + deployment
*"Single-site annual subscription: `{{pricing_tbd}}` — Self-hosted on
your WordPress install"*. `marketing/pitch/pitch-deck.md` carries the
same framing and placeholder.

Under managed hosting, "your hosting, your control, no vendor lock-in" is
not merely outdated — it is the objection a club will raise back at us,
and we would have written it ourselves.

Meanwhile `docs/license-and-account.md` quotes concrete numbers (€399
Standard / €699 Pro) that no marketing artifact repeats, so the only
published prices sit in operator documentation.

**What to change.**

1. Rewrite the differentiation section around what hosting actually gives
   a club: nothing to install, nothing to maintain, nothing to back up,
   updates handled, and a system that works on a phone at the side of a
   pitch. For a volunteer-run amateur club, "you don't have to run a
   server" is a stronger promise than "you control the server" — the
   self-hosted framing was written for a buyer this product does not
   have.
2. Address lock-in honestly rather than dropping the claim. Hosting *is*
   a dependency on us, and the credible answer is an export guarantee and
   a de-provisioning clock — both of which § 4 and § 5 define anyway. A
   club that asks "what if you disappear" deserves a real answer, and
   having one is a differentiator against a spreadsheet.
3. Replace `{{pricing_tbd}}` once numbers exist, and make marketing and
   `docs/license-and-account.md` agree.
4. Reconcile the GPL framing. The plugin header says GPL-2.0+ and the
   repo has no LICENSE file. Under hosting, the licence of the code is
   not part of the customer conversation, and leading with it invites a
   question that no longer sells anything.
5. Consider Dutch-first collateral. `marketing/pitch/README.md` records
   that English-first was deliberate so the pitch could be tuned per
   audience before localising — the audience is now known, and it is
   Dutch amateur clubs.

**Done when.** No pitch artifact claims self-hosted deployment or "no
vendor lock-in". Hosting is positioned as the benefit it is for a
volunteer-run club. Export and exit are stated explicitly, matching what
de-provisioning actually does. No `{{pricing_tbd}}` placeholders remain,
and prices match `docs/license-and-account.md`.

**Open questions.**

1. Is the pitch deck still the sales instrument, or does a live demo URL
   replace it now that a prospect can be sent one?
2. Does the marketing surface designed for the SaaS port
   (`design/screens/marketing/` in the SaaS repo) get built for the
   hosted WordPress product instead — same audience, same offer, one
   product earlier? Its notes already flag the GPL/self-hosted footer
   claim as inherited and needing review.

---

## 8. Price points — deferred

*Was issue #2930, closed on the backlog by decision.*

Deliberately deferred. Hosting adds a per-club cost floor the 2025
numbers (€399 Standard / €699 Pro) ignore. Revisit once § 1 settles the
hosting stack and its per-club running cost is known, and once the tier
split in the development issue is decided.

---

## Sequence

The 2026-08-26 decisions removed what used to be step 1 — the hosting
stack, database isolation, SLA, signing entity and payment mechanism are
all settled. What is left is execution, plus one reopened product
question.

1. **Pick the pilot vehicle** (§ 6) — small, and it now blocks § 5's
   lifecycle and § 8's pricing. Reopened by the 2026-08-27 tier revision.
2. **Provisioning** (§ 1) — the largest lift, and now unblocked.
3. **Isolation verification** (§ 3) — decision 5 chose the design;
   this confirms the implementation matches, with `tt-media/` as the
   sharp edge.
4. **Fleet operations** (§ 2) — including the RTO/RPO the already-promised
   99.5% SLA rests on.
5. **Legal execution** (§ 4) and **billing** (§ 5) — hard gates on the
   first signature; run in parallel with 2–4.
6. **Demo funnel** (§ 6), **pricing** (§ 8), **collateral** (§ 7) — last,
   once there is something to sell.
