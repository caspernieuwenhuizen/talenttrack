# Buttons use the same font as the text around them (#4805)

Buttons in the dashboard were always drawn in Helvetica Neue or Arial, whatever font the rest of the page used. On the Leon Hutten theme the text was Open Sans and the buttons were not, and a body font chosen under Branding never reached a button either. Buttons now use the body font, like the form fields, which already did. Button heights don't change; a button's width can change by a few pixels where the two fonts differ in width.
