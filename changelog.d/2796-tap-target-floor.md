# Buttons on a phone are back to a full-size tap target (#2796)

Bump: patch

Buttons across the app were rendering 44px tall on a phone instead of the
48px the design calls for, and the smaller variant only 32px — under a
third of a fingertip. The rule setting the correct size was being undone
further down by the very stylesheet meant to look after the phone layout.

Buttons now meet the intended size on every handset. Desktop is unchanged.
