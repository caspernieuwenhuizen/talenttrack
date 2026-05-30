# Wizard chrome — design alternatives

## Why this mockup exists

The current wizard chrome (shipping baseline: v3.110.102 in
[FrontendWizardView.php](../../src/Shared/Frontend/FrontendWizardView.php))
uses solid coloured pills for every step state — completed steps render as
dark green pills with a `✓` marker, current as teal pills with an outer
ring, pending as light grey. The pilot user finds the "completed step"
state visually heavy: every finished step takes up the same horizontal pill
space and the bold green draws the eye away from the current step + form.

The action button row also lacks visual hierarchy — Cancel, Save as draft,
Back and Next all sit on the right side with similar weight, making it hard
to scan for the primary path.

This mockup explores **three alternatives** to the step indicator + buttons,
all using the existing teal accent palette so the port is mechanical.

## Try it

Open [`index.html`](index.html). Picker at the top toggles:

- **Compare all 3** — sits all three variants in a column (mobile) or 3-up
  side-by-side (≥1100px wide). Best way to evaluate.
- **Baseline** — what ships today, for diffing.
- **V1 / V2 / V3** — each variant solo at the full body width.

Resize the window from desktop down to phone width to see how each variant
behaves at the mobile budget (the wizard form runs at `max-width: 640px` in
production, so the form column stays the same — only the step indicator's
density changes).

## The three variants

### V1 · Connected stepper rail

> Numbered dots connected by a hairline, labels below.

- **Done**: filled teal dot with subtle `✓`, normal-weight label.
- **Current**: filled teal dot with the step number + 4px soft ring, bold
  teal-coloured label.
- **Pending**: outlined dot (2px line border), muted label.
- **NA**: dashed outline dot, line-through muted label.
- The connecting hairline fills with teal up to and including the current
  step — gives a left-to-right "you're 4 of 6 deep" cue without colouring
  the whole pill.

**Buttons**: Cancel as a quiet text-button on the far left (auto-margin
pushes everything else right). Save as draft + Back as outlined pill
buttons in the middle. Next as a solid teal pill with shadow + arrow on
the far right.

**Vibe**: Stripe / Linear / Polaris. Classy, compact, very widely used.

**Pros**:
- Visual hierarchy is obvious — current step is the only thing with the
  ring.
- Completed steps are still visible (so the user can see how far they've
  come) but not loud.
- Vertical real estate is small (~60px for indicator including labels).

**Cons**:
- Labels below dots can wrap awkwardly at narrow widths with long step
  names ("Trial details", "Review & create"). Mitigation: shorten labels.
- The hairline + dot pattern is heavily used in modern apps; risk of
  reading "generic SaaS" instead of "TalentTrack".

### V2 · Compact progress bar

> Thin segmented bar at top, one-line context below.

- A 6-segment horizontal bar (one per step) — done segments fill teal,
  current fills teal with an inset light-line, NA renders as a dashed
  segment, pending stays mute.
- Below the bar: `Step 4 of 6 · Contact` line, with a "Jump to step ›"
  affordance on the right.

**Buttons**: Cancel as a pill with a hover-tinted danger state on the
far left. Save as draft + Back as quiet text-buttons in the middle.
Next as a **3D-style** primary button on the right — light-on-top
gradient with a 2px bottom-shadow that compresses on press. Bigger
visual weight than V1's primary, no chevron noise.

**Vibe**: Material / Notion / Linear. Maximises form space.

**Pros**:
- Smallest vertical footprint of the three (~36px including the meta
  row). Huge on phones.
- "% complete" reading is automatic from the bar — easier to estimate
  remaining work than counting pills.
- "Jump to step" affordance is naturally placed.

**Cons**:
- Loses the per-step labels — only the current step name is visible.
  Users who want to "see all the headings at a glance" lose that.
- The 3D button is fashion-sensitive; might feel dated in 2 years.

### V3 · Sidebar timeline / collapsed dropdown

> Vertical rail on desktop with per-step "Edit" links; collapses to a
> single tap-to-expand row on mobile.

- Desktop (≥720px): a 220px left rail with all steps stacked vertically.
  Each entry: dot · label · optional caption ("You are here", "Not
  applicable for this player"). Completed steps get a small "Edit" link
  for jumping back.
- Mobile (<720px): the rail is hidden; replaced by a teal-tinted disclosure
  button showing `Step 4 of 6 · Contact ▾`. Tap expands the rail below.

**Buttons**: grouped inside a tinted container at the bottom. Cancel as a
left-aligned outline button (turns red on hover). Save as draft + Back as
text-buttons grouped centrally. Next as a **pill-shaped** primary on the
right with a chevron that slides on hover.

**Vibe**: GitHub / Stripe Connect / multi-step setup wizards.

**Pros**:
- Most information visible on desktop — coach sees every step + can jump
  back to any completed step inline.
- Mobile is the lightest of the three (one collapsed line until tapped).
- The "Edit" affordance per completed step is genuinely useful; today's
  baseline has no such per-step jump-back.

**Cons**:
- Desktop layout takes a fixed 220px column off the form width — the
  640px `max-width` form column would need to either shrink or sit
  beside a narrower rail (the mockup currently shrinks the form area).
- Two-mode layout means twice the CSS to maintain.

## Button design — common improvements across V1-V3

### Desktop pattern

All three variants share these principles vs. baseline:

1. **Cancel is visually quietest** — never a danger-red button. Today's
   Cancel sits in a red-bordered outlined button (`tt-button-secondary`)
   that pulls attention. The new pattern: ghost / text-button on the far
   left, hover-tints to danger to signal "this discards your work" only
   on intent.
2. **Save as draft + Back are visually neutral** — text-style or outline,
   never primary. They're escape hatches, not commits.
3. **Next is the only weighty button** — fills with brand teal, has more
   horizontal padding than the others, sits to the right where the eye
   lands last.
4. **Touch targets all ≥ 48px tall** (matches CLAUDE.md).
5. **Cancel sits far-left, Next far-right** — physical separation prevents
   misclicks. Today they're stacked on the right with similar weights.

### Mobile pattern (≤719px) — unified across all three variants

The desktop "Cancel left, secondaries middle, Next right" pattern devolves
into wrapped chaos on a phone: 3-4 ~120px pills don't fit a 360px row,
flex-wrap drops them onto multiple lines, and the visual hierarchy
disappears. The user flagged this as the v1 of this mockup's biggest weak
point.

The mobile fix is a 3-row grid that reads top-to-bottom in priority order:

```
┌─────────────────────────────┐
│       [   Next ›   ]        │  full-width primary (thumb spot)
├─────────────────────────────┤
│       [   ‹ Back   ]        │  full-width outline (subordinate)
├─────────────────────────────┤
│   Save as draft  ·  Cancel  │  text-link row (escape hatches)
└─────────────────────────────┘
```

Why:

- The user fills the form by scrolling down. Their thumb naturally lands
  at the bottom — that's where Next should be.
- Next is the next action in 90%+ of submissions. Give it dominant weight
  + full-width.
- Back is the second-most-likely action. Full-width preserves tap
  accuracy but the outline-only style signals "subordinate to Next."
- Save as draft + Cancel are escape hatches — they're discoverable but
  visually quiet, so they don't compete with the navigation.
- The pattern is identical across all three variants of this mockup so
  the implementation reuses the same grid block regardless of which
  visual chosen for the step indicator.

Implementation note: each button carries a `data-role="next|back|save|cancel"`
attribute that the mobile CSS targets via `grid-template-areas`. Wrapper
divs (e.g. V2's `.v2-actions__middle`) get `display: contents` on mobile
so their children participate in the parent grid directly.

## Recommendation (subjective, for discussion)

**V1 — Connected stepper rail** is the safest bet. It:

- Solves the user's "completed steps look too heavy" complaint cleanly —
  done steps shrink to a small dot, no more dark-green pill takeover.
- Keeps all the information density the baseline offers (you can see
  every step name + every state).
- Has the smallest blast radius — only the existing
  `.tt-wizard-progress` block and the action row change; the form body,
  autosave indicator, help drawer all stay unchanged.

V2 is a good fallback if pilot feedback says "I never read the step
names anyway, just give me the bar."

V3 is more ambitious — worth picking if you also want to introduce
per-step jump-back ("edit step 2 from any later step") as a feature in
the same release.

## Out of scope

- Form input styling (these are baseline `<input>`s for show; production
  has its own `.tt-input` system that already follows the mobile-first
  rules).
- Help drawer / autosave-status placement.
- The wizard-level page chrome (breadcrumbs, page title) — that's set by
  `FrontendBreadcrumbs::fromDashboard()` upstream.

## What to test on real device

1. **Mobile**: open at 360px width and confirm V1's labels don't wrap into
   two lines. Test V3's collapse/expand animation.
2. **Long step lists**: most TT wizards are 3-6 steps. Stress-test V1 + V2
   with a hypothetical 8-step wizard to see if the chrome still reads.
3. **Reduced motion**: V3's chevron-slide should respect
   `prefers-reduced-motion: reduce`. Add `@media` guard before porting.
4. **Touch accuracy**: ensure the Cancel ghost-button is easy to tap-and-
   release without triggering Next (the visual distance helps but verify).
