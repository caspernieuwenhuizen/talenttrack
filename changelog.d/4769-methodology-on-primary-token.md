# Methodology screen: the active tab's text follows the theme's on-primary colour (#4769)

The active tab on the methodology management screen read a colour token that was never defined, so its text was always the built-in white whatever the theme set. It now reads the on-primary colour token, and so do the active child-switcher pill and the active segmented option. All current themes keep white text on their primary colour, so nothing changes on screen today; a theme with a light primary colour can now make that text dark. CSS only.
