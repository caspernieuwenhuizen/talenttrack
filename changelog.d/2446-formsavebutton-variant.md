# Save buttons follow your button colours again (#2446)

The shared Save button helper mishandled its own default. When a form didn't
name a button style explicitly — which is nearly all of them, 50 of the 55
call sites — the helper emitted a PHP warning and then rendered the button
without its `tt-btn-primary` class.

The visible consequence was that those Save buttons ignored the Buttons colour
settings under Design: instead of your configured button background, text and
hover colours, they fell back to the brand primary colour. On an install that
hasn't customised those tokens nothing looked wrong, which is why it went
unnoticed.

Save buttons now get the primary style whenever no style is named, so they
follow the Design settings like every other button. Forms that explicitly ask
for a secondary or danger button are unaffected.
