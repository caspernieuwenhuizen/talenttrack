# Match analysis reads as one page, and sharing it is now a decision (#2748, #2749)

Bump: minor

The finished analysis is a document instead of a stack of half-empty cards,
and the screen, the share link and the print sheet are now the same
document — so what you look at is what the person you send it to sees.

**Two chains instead of six cards.** The phases sit in two columns that each
read top to bottom: with the ball (attacking → the instant we lose it → our
own set pieces) and without it (defending → the instant we win it →
theirs). A transition only means something read next to the phase it comes
out of. Each phase carries its own points inside its own tile, so the
qualification and the specifics sit together rather than in a separate list
further down, and a phase nobody rated is a thin placeholder rather than a
full-size empty card.

**Set pieces split by side.** They shipped merged, which put a note about our
corners in the same box as one about defending their free kicks. Splitting
them also restores an exact 1:1 mapping with the four goal boxes on the match
plan, so every planned line now appears beside the phase it was planned for.
Anything already written under the merged section moves to the attacking side
and keeps its text.

**The rating moved onto the phase's own heading.** It was four full-width
pills in the same white-on-outline as the text fields below them, which read
as a row of empty inputs — and the selected one always turned green, whatever
you picked. It is now three compact chips carrying the rating's own colour,
with **Clear** appearing once something is set.

**A share link is no longer created just by opening the page.** It was:
merely looking at an analysis wrote a live, working URL nobody had asked for,
on a document that names children. Now there is **Create share link**, and
once it exists you get the URL with **Copy link** beside it and a **Replace
link** action that says plainly that the current one stops working.

**Saving keeps you where you are.** It used to reload the page and jump the
scroll to the top, which reads as having been taken somewhere else; your text
and the print and share actions stay put now.

The printed sheet is landscape A4, built to fit one page, and still real
selectable text rather than an image.
