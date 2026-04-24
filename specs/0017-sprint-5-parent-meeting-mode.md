<!-- type: feat -->

# #0017 Sprint 5 — Parent-meeting mode: sanitized fullscreen view

## Problem

When the HoD meets the parents to communicate a decision — whether admittance or denial — they often want to show something from the plugin on a laptop or tablet. But:

- Normal case views show *internal* detail: individual staff ratings, coach free-text, aggregation breakdowns. Not appropriate to display to parents.
- Showing a laptop with a busy admin interface and sensitive internal data visible is unprofessional and risks leaking things the HoD didn't intend.

The HoD needs a **sanitized, fullscreen, meeting-friendly view** of the case outcome that can be projected or shown on-screen without anxiety about what's visible.

## Proposal

A new "Parent Meeting" tab on the case view, launchable in fullscreen mode. Shows only:
- Player photo (if not opted out in privacy).
- Player name, age, team considered.
- Trial dates.
- Decision outcome + encouragement language (no internal justification).
- For admit: start date, next steps.
- For deny-with-encouragement: the strengths summary and growth areas that went into the letter.
- For deny-final: polite framing, no internal rationale.
- The rendered letter, pre-loaded and ready to print or email after the meeting.

Nothing else. No staff ratings. No attendance percentages. No other evaluators' notes.

## Scope

### Parent Meeting tab

Location: new tab on `FrontendTrialCaseView`, visible only post-decision and only to users with `tt_manage_trials`.

Tab initially renders a compact preview:
- "Parent Meeting Mode — launch fullscreen for parent conversation."
- Button: "Enter fullscreen meeting mode."

### Fullscreen meeting mode

When launched:
- Uses browser Fullscreen API (`element.requestFullscreen()`).
- Graceful fallback: if browser doesn't support fullscreen, opens in a clean new tab with no nav chrome.
- Page content:
  - Large player photo (top center).
  - Player name + age + team + trial dates.
  - Decision outcome prominently displayed — color-coded gently (green for admit, blue for encouragement, gray for final).
  - Section appropriate to the outcome (admit start date; denial encouragement strengths/growth).
  - "View full letter" button that opens the rendered letter in an overlay or new window.
  - Exit fullscreen button (top right, clearly visible).
- All styling clean, large-text, readable from across a small meeting room.

### What's explicitly hidden

Compared to the normal case view, parent-meeting mode omits:
- All individual staff inputs.
- All staff names (except the HoD signatory).
- Attendance percentages.
- Session lists.
- Aggregation statistics.
- Admin-internal notes.
- Justification text from the Decision tab (that's for the internal record, not for parents).
- Free-text evaluator comments, even released ones.

### Print and email from parent-meeting mode

- "Print letter" button — opens the persisted letter (from Sprint 4) in a print-ready view.
- "Download letter" button — downloads HTML.
- "Email letter" — opens user's default mail client with pre-filled subject and the letter URL (simple `mailto:` link; no server-side email integration).

### Exit flow

- "Close meeting mode" button returns to the normal case view.
- Exit doesn't change any data or record anything additional.

## Out of scope

- **Recording the meeting outcome** (e.g. did parents accept?). Separate from the meeting mode view — HoD records this elsewhere on the case (already supported by the acceptance-slip feature in Sprint 4 for admits; denials don't need further tracking).
- **Custom branding or club-specific styling** for the meeting mode — uses the plugin's default aesthetics. Branding customization is #0011's scope.
- **Presenter-notes mode** — no separate "what I plan to say" overlay. The meeting mode is what's on-screen, full stop.
- **Multi-language switcher in meeting mode** — the letter is already rendered in the chosen locale. No toggle.
- **Exporting meeting mode as a PDF** — the letter itself is what gets printed/emailed. Meeting mode is a live view.

## Acceptance criteria

### Visibility

- [ ] Parent Meeting tab visible only on decided cases, only to users with `tt_manage_trials`.
- [ ] Pre-decision cases do not show the tab.

### Fullscreen launch

- [ ] Clicking "Enter fullscreen meeting mode" triggers browser fullscreen.
- [ ] Graceful fallback: if fullscreen fails, opens in a chrome-free new tab.
- [ ] Exit fullscreen button visible and functional.
- [ ] Esc key exits fullscreen (browser default).

### Content correctness

- [ ] Shows only the sanitized content listed above.
- [ ] Does NOT show any individual staff ratings, attendance data, aggregation stats, or justification notes.
- [ ] Decision outcome is prominently displayed.
- [ ] For admit: start date and next steps visible.
- [ ] For deny-with-encouragement: strengths summary and growth areas visible (these come from the letter context).
- [ ] For deny-final: polite framing, no rationale detail.

### Letter access

- [ ] "View full letter" opens the rendered letter correctly.
- [ ] "Print letter" and "Download letter" work.
- [ ] "Email letter" opens a `mailto:` with sensible defaults.

### Responsive

- [ ] Works on a tablet (768px) as the likely target device for a parent meeting.
- [ ] Works on a laptop (1024px+).
- [ ] Mobile (375px) view is functional but less-important — probably not used in a meeting.

### No regression

- [ ] Other case view tabs continue to work.
- [ ] Letter generation (Sprint 4) unchanged.

## Notes

### Sizing

~5–7 hours. Breakdown:
- Tab scaffolding + gate: ~0.5 hour
- Fullscreen launch + fallback: ~1 hour
- Sanitized content rendering (with explicit allow-list): ~2 hours
- Letter access buttons + mailto: ~0.5 hour
- Styling (large text, clean layout): ~1.5 hours
- Testing across tablet/laptop and all three decision outcomes: ~1.5 hours

### Why this is a small sprint

Most of the substance was built in Sprint 4 (the letter itself). Sprint 5 is really just a UI facade around the decided-case state with careful content filtering. The hard part is making sure the "don't show X" list is tight and tested.

### Depends on

- #0017 Sprint 4 (decision + letter persistence).

### Blocks

None.

### Touches

- `src/Shared/Frontend/FrontendTrialCaseView.php` — add Parent Meeting tab
- `src/Shared/Frontend/FrontendParentMeetingView.php` (new — the fullscreen view)
- Dedicated CSS partial for the meeting mode (large-text, clean styling)
- JavaScript: fullscreen API + fallback, exit handlers

### Design principle

**Allow-list what's shown, not block-list what's hidden.** If a new field is added to the case or letter later, parent-meeting mode should default to *not* showing it unless explicitly added to the allow-list. Safer than a block-list that might miss a new leak.
