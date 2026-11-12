# Text on the secondary colour is readable on every theme (#5148)

On some themes, text printed on the secondary colour was barely visible: the
app header's brand mark and avatar, the filter bar's active pills and badge,
the podium's lead pill, a goal owner's avatar, the methodology chips, the
milestone tag on the journey, the popularity rating, and in a live match the
goal button and a flagged player's number. On Middernacht it
printed blue on a slightly lighter blue. Where a theme's colours are too close
together, that text now uses the theme's deepest colour, which reads at 4.5:1
or better. This changes Middernacht, Oranje, Polder, Stadionlicht,
Wapenschild and Wedstrijddag; the default theme and the other themes look the
same. Kristalblauw is not changed yet: its header mark keeps its current
colours until that is decided.
