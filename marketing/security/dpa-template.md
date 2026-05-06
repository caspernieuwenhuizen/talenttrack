# Data Processing Agreement (DPA)

> **Source for the DPA template that academies counter-sign when subscribing to TalentTrack.** Convert to PDF (preserving the Annex tables and signature block) for legal review and customer signature. **Until legal review is complete, this draft is not for execution.** The locked decisions in #0086 of the development backlog set the structure: standard EU template, MediaManiacs as the data processor entity, signed as-is with no per-customer negotiation.

> **Status:** Draft pending legal review. Do not execute until reviewed.

---

## Parties

This Data Processing Agreement ("**DPA**") is entered into between:

**Controller:**
[Academy name]
[Academy address]
[Country]
(hereinafter "**Controller**")

and

**Processor:**
MediaManiacs
[Registered address — fill in]
The Netherlands
KvK: [number — fill in]
(hereinafter "**Processor**")

each a "**Party**" and together the "**Parties**".

## Recitals

Whereas:

(A) The Parties have entered into a service agreement under which Processor provides the TalentTrack software-as-a-service product ("**Service**") to Controller (the "**Service Agreement**").

(B) In the course of providing the Service, Processor processes Personal Data on behalf of Controller within the meaning of Regulation (EU) 2016/679 (the "**GDPR**").

(C) The Parties wish to set out in writing the terms applicable to such processing, in compliance with Article 28 GDPR.

NOW THEREFORE, the Parties agree as follows.

## 1. Definitions

Capitalised terms not defined in this DPA have the meaning given in the GDPR. In particular:

- "**Personal Data**" means personal data as defined in Article 4(1) GDPR processed by Processor on behalf of Controller under the Service Agreement.
- "**Data Subjects**" are the natural persons to whom the Personal Data relates — typically the Controller's players, parents, and staff.
- "**Sub-processor**" means any third party engaged by Processor to process Personal Data on its behalf.
- "**Data Protection Law**" means the GDPR and any applicable national legislation implementing or supplementing it.

## 2. Subject matter and duration

Processor shall process Personal Data on behalf of Controller solely for the purpose of providing the Service in accordance with the Service Agreement and Controller's documented instructions. The duration of the processing is the duration of the Service Agreement, plus any post-termination period required by law or expressly agreed in writing.

The nature, purpose, categories of data, and categories of data subjects are described in **Annex 1**.

## 3. Controller's instructions

Processor shall process Personal Data only on documented instructions from Controller, including with regard to transfers of Personal Data to a third country or an international organisation, unless required to do so by Union or Member State law to which Processor is subject. Processor shall in such case inform Controller of that legal requirement before processing, unless that law prohibits such notice on important grounds of public interest.

The Service Agreement and this DPA constitute Controller's complete and final documented instructions. Any additional or alternative instructions must be agreed in writing.

If Processor is of the opinion that an instruction infringes Data Protection Law, Processor shall promptly inform Controller. Pending Controller's confirmation, Processor may suspend the affected processing.

## 4. Confidentiality

Processor shall ensure that personnel authorised to process Personal Data have committed themselves to confidentiality or are under an appropriate statutory obligation of confidentiality.

## 5. Security of processing

Processor shall implement and maintain appropriate technical and organisational measures to ensure a level of security appropriate to the risk, including:

(a) Encryption of Personal Data in transit (HTTPS/TLS) and protection of credentials at rest via authenticated encryption;
(b) The ability to ensure ongoing confidentiality, integrity, availability and resilience of processing systems;
(c) The ability to restore the availability of and access to Personal Data in a timely manner in the event of a physical or technical incident, including via the backup feature of the Service;
(d) A process for regularly testing, assessing and evaluating the effectiveness of the technical and organisational measures, including the annual external security audit referenced in Annex 3.

The specific technical and organisational measures are listed in **Annex 3**.

## 6. Sub-processors

Controller hereby grants Processor general written authorisation to engage Sub-processors for the purpose of providing the Service, subject to the conditions in this Article 6.

Processor shall ensure that any Sub-processor it engages is bound by data-protection obligations equivalent to those set out in this DPA, in particular providing sufficient guarantees to implement appropriate technical and organisational measures.

The current list of Sub-processors is in **Annex 2**. Processor shall inform Controller of any intended addition or replacement of Sub-processors at least thirty (30) days in advance, giving Controller the opportunity to object. If Controller objects on reasonable Data Protection Law grounds and the Parties cannot agree on a resolution within thirty (30) days, Controller may terminate the Service Agreement with respect to the affected service.

Processor remains fully liable to Controller for the performance of its Sub-processors.

## 7. Data subject rights

Taking into account the nature of the processing, Processor shall assist Controller by appropriate technical and organisational measures, insofar as possible, in the fulfilment of Controller's obligation to respond to requests for exercising Data Subject rights under Articles 15-22 GDPR.

The Service includes the following self-service tooling supporting these requests, with the timelines indicated:

- **Right of access (Article 15) / data portability (Article 20):** Subject access export ships in the Export module (currently in development; expected within 6 months of DPA execution). Until then, Processor will support Controller's manual response on request.
- **Right to rectification (Article 16):** Available now via the standard editing interfaces.
- **Right to erasure (Article 17):** A formal erasure pipeline (dry-run preview, 30-day grace, hard-delete across all tables containing Personal Data) is in development; expected within 12 months. Until then, Processor will support Controller's manual erasure on request.
- **Right to restriction (Article 18) / objection (Article 21):** Available via the soft-archive mechanism in the Service.

Where a Data Subject contacts Processor directly with a rights request relating to Controller's data, Processor shall promptly forward the request to Controller and not respond directly except to confirm receipt and direct the Data Subject to Controller.

## 8. Data breach notification

Processor shall notify Controller without undue delay, and in any event within seventy-two (72) hours of becoming aware of, a Personal Data breach. The notification shall include:

(a) A description of the nature of the breach including, where possible, the categories and approximate number of Data Subjects and records concerned;
(b) The name and contact details of the Processor's contact for further information;
(c) The likely consequences of the breach;
(d) The measures taken or proposed to address the breach and to mitigate its possible adverse effects.

Where, and insofar as, it is not possible to provide the information at the same time, the information may be provided in phases without undue further delay.

Processor shall not notify Data Subjects, supervisory authorities, or the public of any breach without Controller's prior written instruction, except where required by Union or Member State law.

## 9. Data protection impact assessment

Processor shall provide reasonable assistance to Controller in carrying out data protection impact assessments under Article 35 GDPR and prior consultations with the supervisory authority under Article 36 GDPR, taking into account the nature of processing and the information available to Processor.

## 10. International transfers

Processor shall not transfer Personal Data outside the European Economic Area without:

(a) Controller's prior written consent; and
(b) Implementation of appropriate safeguards within the meaning of Articles 44-49 GDPR, including (where applicable) Standard Contractual Clauses.

The Sub-processors listed in Annex 2 with non-EEA processing have been authorised by Controller through this DPA, with the safeguards documented in Annex 2.

## 11. Audits

Controller, or an independent auditor mandated by Controller, may, at Controller's expense and on reasonable prior notice, conduct an audit of Processor's compliance with this DPA, no more than once per twelve-month period (unless required by a regulatory authority or following a Personal Data breach).

In addition, Processor commits to an **annual external security audit** by an independent third-party security firm. The summary findings of the most recent audit are published on Processor's security page (`talenttrack.app/security`); the full report is available to Controller under non-disclosure agreement on request.

Controller may rely on the most recent external audit report in lieu of conducting its own audit, provided the scope is reasonably equivalent.

## 12. Return or deletion of Personal Data

On termination of the Service Agreement, at Controller's choice, Processor shall return or delete all Personal Data processed under this DPA, and shall delete existing copies, unless Union or Member State law requires storage.

Controller shall make its choice in writing within thirty (30) days of termination. Absent a choice, Processor shall delete all Personal Data after sixty (60) days. Backups containing Personal Data shall be deleted in accordance with Processor's standard backup-retention schedule, after which Processor confirms deletion in writing.

## 13. Liability and indemnity

Each Party's liability under or in connection with this DPA is governed by the limitations set out in the Service Agreement.

For the avoidance of doubt, this Article 13 does not limit any liability that cannot be limited under applicable law (including liability for fines imposed under Article 83 GDPR where the Party in question is responsible for the underlying infringement).

## 14. General

**14.1 Governing law and jurisdiction.** This DPA is governed by the laws of the Netherlands. The competent court in Amsterdam has exclusive jurisdiction over disputes arising out of or in connection with this DPA, without prejudice to mandatory provisions of Data Protection Law on jurisdiction.

**14.2 Order of precedence.** In case of conflict between this DPA and the Service Agreement, this DPA prevails on matters of data protection.

**14.3 Amendments.** Amendments to this DPA require written agreement of both Parties.

**14.4 Severability.** If any provision of this DPA is held to be invalid or unenforceable, the remaining provisions remain in full force and effect.

---

## Annex 1 — Description of processing

### Subject matter and purpose

Processor processes Personal Data on behalf of Controller for the purpose of providing the TalentTrack software-as-a-service product, which supports Controller's youth football academy operations including player development tracking, evaluation management, attendance tracking, communication with parents, and trial-case management.

### Categories of Data Subjects

- Players (typically minors aged 6-18) registered with Controller's academy
- Parents and guardians of those players
- Staff members of Controller's academy (coaches, scouts, administrators, managers, support roles)
- Prospects in the recruitment pipeline (where the Service includes the onboarding-pipeline module)

### Categories of Personal Data

- **Identity:** name, date of birth, photograph
- **Contact:** address, email, telephone (parent contact for minors)
- **Operational:** team membership, attendance records, evaluation scores, development goals, journey events (joins, position changes, age-group promotions, etc.)
- **Sensitive (where Controller chooses to record):** injury history, safeguarding notes, behavioural and potential ratings — all gated behind specific capabilities in the access matrix
- **Account / authentication:** username, hashed password (managed by WordPress), session tokens, login activity (date)
- **Audit metadata:** records of sensitive actions performed by staff users

### Duration

Processing duration matches the duration of the Service Agreement plus any agreed post-termination period.

### Nature of processing

Storage in a relational database hosted on Controller's chosen infrastructure; retrieval and display through the TalentTrack web interface; aggregation for reporting; export on Data Subject request; deletion on Data Subject request or per Controller's retention policy.

---

## Annex 2 — Sub-processors

| Sub-processor | Purpose | Categories of Personal Data | Region | Safeguards |
|---|---|---|---|---|
| Hosting provider chosen by Controller | Database storage and webserver | All Personal Data | Determined by Controller | Provider's own DPA + Standard Contractual Clauses if non-EEA |
| Freemius Inc. | License key verification and payment processing | License key, contact email, payment data | United States | Freemius's DPA includes Standard Contractual Clauses |
| MediaManiacs operational mothership (`mediamaniacs.nl`) | Phone-home operational telemetry (counts and shapes only — no per-Data-Subject records) | Aggregate metadata only | EU (Netherlands) | Direct controller-processor relationship; no further transfer |

**Procedure for adding or replacing Sub-processors:** Processor shall inform Controller in writing at least thirty (30) days before any addition or replacement, including the Sub-processor identity, location, and processing purpose. Controller may object on reasonable Data Protection Law grounds within thirty (30) days; the Parties shall use good-faith efforts to find an alternative arrangement, failing which Controller may terminate the affected portion of the Service Agreement.

---

## Annex 3 — Technical and organisational measures

### Confidentiality

- Granular capability and matrix-based authorization model — every persona × entity grant is documented, editable per academy, and enforced consistently in REST endpoints, render paths, and admin handlers.
- Sub-processor access is limited to the minimum personnel required, all under contractual confidentiality.

### Integrity

- Full audit log (`tt_audit_log`) of sensitive actions: impersonation start/end, role changes, bulk operations, configuration changes, license-tier changes.
- User impersonation requires explicit start, surfaces a non-dismissible banner during the impersonation, and records every start and end in `tt_impersonation_log`.
- Cross-academy impersonation is blocked at the service layer.

### Availability

- Built-in scheduled backup of all `tt_*` tables to local storage; off-site copy is the academy's responsibility (recommended monthly).
- Bulk operations write a safety snapshot before executing, with a 14-day undo window (Standard tier and above).

### Resilience

- WordPress-native session management; re-authentication on session expiry.

### Security testing

- Annual external security audit by an independent third-party firm (Securify or Computest under selection at the time of writing). Summary findings published on `talenttrack.app/security` within one month of report receipt.
- Coordinated disclosure for any vulnerability discovered by external researchers; contact `casper@mediamaniacs.nl`.

### Encryption

- All web traffic over HTTPS / TLS 1.2+.
- Phone-home telemetry signed with HMAC-SHA256.
- Third-party integration credentials (Spond, Web Push VAPID keys) encrypted at rest in the database using AES-256-GCM via the application's `CredentialEncryption` envelope.
- Database-level encryption is the responsibility of the chosen hosting provider.

### Roadmap commitments

The following measures are scheduled to ship within twelve (12) months of DPA execution:

- TalentTrack-native multi-factor authentication (TOTP + backup codes), with per-academy persona enforcement;
- Session management UI for all users (revoke active sessions);
- Failed-login tracking surfaced in the audit log;
- Optional admin IP allowlist;
- Subject Access Export module (currently in development);
- Formal erasure pipeline (currently in shaping; expected within 12 months).

---

## Signatures

**Controller:**

Name: ____________________________
Title: ____________________________
Signature: ____________________________
Date: ____________________________

**Processor (MediaManiacs):**

Name: Casper Nieuwenhuizen
Title: Founder
Signature: ____________________________
Date: ____________________________

---

**Document version:** [version number]
**Last reviewed:** [date of last legal review]
