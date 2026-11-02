# Dashboard help link: a visible keyboard focus ring (#4748)

The help link in the dashboard header removed its focus outline, so a keyboard user tabbing onto it saw only a colour change. It now draws a 2px ring on `:focus-visible`: primary-coloured, as on the shared help button, and white on the shell's dark green header band, as on the home link next to it. Hover looks as before. CSS only.
