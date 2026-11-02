# Dashboard help link: a visible keyboard focus ring (#4748)

The help link in the dashboard header removed its focus outline, so a keyboard user tabbing onto it saw only a border colour change. It now draws a 2px primary ring on `:focus-visible`, as the shared help button does. Hover looks as before. CSS only.
