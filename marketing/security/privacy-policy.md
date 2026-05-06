# Privacy Policy

> **Source for `talenttrack.app/privacy`.** Copy this content to the public page on the TalentTrack website. Update the *Last reviewed* date with each annual review. The operator-facing how-to is at `docs/privacy-operator-guide.md` inside the plugin; the legal Data Processing Agreement template is at `marketing/security/dpa-template.md`.

> **Last reviewed:** [date of last annual review]

This privacy policy explains how MediaManiacs, the company that publishes TalentTrack, handles personal data — your data as a website visitor, and the data of your academy's players, parents, and staff once you become a customer. It is written in plain language; the formal legal commitments are in the Data Processing Agreement.

## Two roles

**On `talenttrack.app` (the website you're reading right now), we are the controller.** We decide what data is collected when you browse the site, sign up for a trial, or download something. The amount of data is minimal — see "What we collect on this website" below.

**On your TalentTrack install (after you become a customer), your academy is the controller and we are the processor.** Your academy decides what data is collected about your players, staff, and operations. We hold that data on your behalf, only act on your instructions, and have signed a Data Processing Agreement (DPA) with you that documents this relationship. The legal commitments we make to you in that role are in the DPA, not on this page.

This split matters because the GDPR obligations differ. This page covers our role as the website controller. For our obligations as your processor, the DPA is the source.

## What we collect on this website

When you visit `talenttrack.app`:

- **Server-side logs** — your IP address, the pages you requested, the timestamp, your browser's user-agent string. Retained 90 days for security and abuse detection.
- **Cookies** — a single first-party cookie remembering your language preference and whether you've dismissed our cookie notice. No third-party advertising cookies, no analytics cookies, no tracking pixels.
- **If you start a trial** — your name, email, organisation name, and the academy details you submit. Used to set up your trial and to contact you about the trial outcome.
- **If you contact us** — the contents of your email or contact form submission. Used to respond and follow up.

We do not run third-party analytics on the website. We do not track you across sites. We do not sell, rent, or trade any data to third parties.

## What our customers' installs collect

When your academy uses TalentTrack, the install collects personal data about players, parents, and staff. **The legal responsibility for that data is your academy's, not ours.** Your academy is the data controller; we are the processor under the DPA. The categories include:

- **Player records** (most are minors): name, date of birth, photo, contact details, evaluations, attendance, goals, development plan, scout reports, journey events, staff-only notes, behaviour and potential ratings.
- **Parent records**: name, email, phone, link to one or more player records, consent flags.
- **Staff records**: name, email, role, scope of access, login activity (date-only by default).
- **Operational metadata**: audit log entries, impersonation log, login activity, demo-data tags.

What an install does *not* collect: payment data (Freemius handles that), free-text outside what staff explicitly type, browsing data outside TalentTrack pages, IP addresses tied to specific actions.

A complete column-by-column inventory is available in the operator-facing privacy guide inside the install.

## Lawful basis for processing

For our website (we as controller): the lawful basis under GDPR Article 6 is **legitimate interest** (running and securing our website) and **performance of contract / pre-contract** (when you start a trial or sign up).

For customer installs (academies as controllers): the lawful basis is decided by the academy, typically **consent** (parents consent to their child's data being held by the academy at signup) or **legitimate interest** (the academy's interest in operating its football development programme). Each academy is responsible for documenting its own lawful basis.

## Data subject rights

Both as a website visitor and as a person whose data is held in a customer's TalentTrack install, you have rights under GDPR:

- **Right of access** — the right to receive a copy of the personal data held about you.
- **Right to rectification** — the right to correct inaccurate data.
- **Right to erasure** ("right to be forgotten") — the right to have your data deleted, subject to lawful exceptions.
- **Right to restriction of processing** — the right to limit how data is used.
- **Right to data portability** — the right to receive your data in a structured, machine-readable format.
- **Right to object** — the right to object to processing based on legitimate interest.
- **Right to withdraw consent** — where consent is the lawful basis, the right to withdraw it.

**To exercise any of these rights against MediaManiacs as the website controller**: email `casper@mediamaniacs.nl`. We respond within one month.

**To exercise any of these rights against an academy that holds your data in a TalentTrack install**: contact the academy directly. They are the controller for your data in that install. We provide tooling to help them respond — subject-access export ships with the Export module (#0063 use case 10); a formal erasure pipeline ships afterwards.

## Retention

**Website data:**

- Server logs: 90 days.
- Trial signup data: kept while your trial is active + 12 months for follow-up. Deleted thereafter unless you've become a paying customer (in which case it transitions to the customer record).
- Customer billing records: retained per Dutch tax law (typically 7 years).
- Email correspondence: retained while the conversation is active + indefinitely after, unless you ask us to delete it.

**Customer-install data:** the academy decides. Defaults shipped by TalentTrack and the operator-facing how-to are in the privacy operator guide inside the install.

## Sub-processors

For the website:

| Sub-processor | Purpose | Region |
|--|--|--|
| Hosting provider for `talenttrack.app` | Webserver | EU (Netherlands) |
| Email provider for `casper@mediamaniacs.nl` | Email | EU |

For customer installs (in the controller's role): the academy chooses its own hosting provider; Freemius handles licensing and payment. See the customer-facing security page for the full list.

## International transfers

We do not transfer your data outside the EU. The exceptions are:

- **Freemius (US)** for license verification and payment, when you become a paying customer. Freemius's own DPA documents its data-residency commitments and Standard Contractual Clauses.
- **Customer-install data** to whatever hosting region the academy chooses. Most EU academies pick EU-only hosts; we recommend it.

## Cookies

`talenttrack.app` uses one first-party cookie to remember your language preference and cookie-notice dismissal. No tracking cookies, no third-party cookies. Cookie banner is currently the standard EU opt-out style; full cookie-management details are in the cookie notice.

Customer installs of TalentTrack run on WordPress, which uses session cookies for authentication. Those cookies are first-party to the academy's WordPress install and live under the academy's privacy policy, not ours.

## Children

The product TalentTrack collects data about players who are typically minors (10-18 years old). The legal obligations around children's data (GDPR Article 8 — parental consent for under-13s in some jurisdictions, under-16s in others) sit with the academy as controller, not with us as processor. Each academy's privacy notice given to parents is the parental-consent surface.

## How we secure data

For the website: standard hardened WordPress install on a managed European host. HTTPS only. No third-party analytics or advertising.

For customer installs: see the security page at `talenttrack.app/security` for the full posture, including encryption commitments, audit cadence, and breach-notification commitments.

## Changes to this policy

When this policy changes materially, we email customers and update the "Last reviewed" date at the top. The version history is tracked in the TalentTrack repo at `marketing/security/privacy-policy.md`. We do not silently change material commitments.

## Contact

Privacy questions, requests to exercise your rights, complaints: `casper@mediamaniacs.nl`. If you're not satisfied with our response, you have the right to lodge a complaint with the Dutch supervisory authority (Autoriteit Persoonsgegevens, [autoriteitpersoonsgegevens.nl](https://autoriteitpersoonsgegevens.nl/)) or your own EU member state's supervisory authority.

## Controller details

MediaManiacs
Casper Nieuwenhuizen
[Address — fill in for the published version]
casper@mediamaniacs.nl
mediamaniacs.nl
