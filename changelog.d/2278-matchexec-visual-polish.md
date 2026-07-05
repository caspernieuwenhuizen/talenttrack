# Match execution: fix the redesign's cards, bench minutes and opponent-goal feed (#2278)
Bump: patch

Follow-up polish on the v4.76.0 match-execution redesign, verified against the
mockups with a headless render:

- The **bench** and **tracked-players** sections now actually show their pastel
  card backgrounds (yellow / green) — the 2026 chrome sheet was overriding them
  with white.
- A bench player's **minutes** now sit inline on the right instead of dropping
  onto their own line, and the **↑ Bring on** button no longer wraps to a second
  row in edit mode.
- An **opponent goal** in Live progress now reads "Opponent goal · <team>" with a
  distinct grey chip instead of a blank "Goal", and the **running score counts
  each side separately** — an opponent goal no longer bumps our tally.
- After a match the review screen still opens read-only, but the **Edit button is
  now prominent** (filled) so the correction controls are easy to find.
