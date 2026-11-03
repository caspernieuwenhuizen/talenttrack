# Activities screens: the theme's line and soft-text colours apply again (#4825)

On the activities screens, two colour tokens were defined in terms of themselves: the line colour and the soft text colour. A colour token that refers to itself comes out empty, so the borders, dividers and secondary text there fell back to their own defaults instead of the theme's colours, on every design. The two lines are gone, and the screens now take both colours from the theme. A new CI check fails any stylesheet that defines a token in terms of itself.
