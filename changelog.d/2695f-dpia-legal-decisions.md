# Photo-capture DPIA: the legal decisions are recorded (#2695)

Bump: patch

Legal clearance for photo-to-plan capture was given on 2026-08-23, and the DPIA
now records what was decided rather than listing what was outstanding:

- **Lawful basis: consent** (Art. 6(1)(a)), given by the parent or guardian,
  with the reasoning written down — the data subjects are children, and
  legitimate interest would have the academy weighing its own convenience
  against a child's privacy and marking its own homework.
- **No in-product consent step.** Consent is captured at registration. An extra
  tap on the capture screen would look like consent while collecting it from the
  wrong person: the coach is not the data subject.
- **A photo held on a phone lives at most 7 days.** Nothing is held today —
  capture shipped online-only — so this is the number the feature will be built
  to when holding lands.
- **Provider terms confirmed** by the data controller.

Two blanks remain for the academy to complete at signing: where consent is
captured, and how it is withdrawn. The product cannot know either.

The feature still cannot send anything until an administrator sets
`TT_VISION_ENDPOINT` and `TT_VISION_DATA_REGION`.
