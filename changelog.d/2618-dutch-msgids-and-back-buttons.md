# English installs no longer show Dutch labels in the methodology screens (#2618)

Bump: patch

Parts of the methodology authoring screens were written with Dutch text as the
source string — the image panel, the play-styles tab, and the Raamwerk tab label.
On an English install those rendered in Dutch, and because the source string was
already localised they could never be translated into any other language either.

The source strings are now English and carry the original Dutch as their
translation, so a Dutch academy sees exactly what it saw before while an English
one finally reads English. The image picker's "Afbeelding kiezen…" also loses its
trailing ellipsis.

The Analytics entity view drops its "← Academy view" button. It pointed at the
same place as the Analytics breadcrumb directly above it, so the screen was
offering the same route twice.
