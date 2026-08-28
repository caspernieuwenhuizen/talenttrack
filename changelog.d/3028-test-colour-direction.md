# Test results: beating the target band is now green, not red (#3028)

Bump: patch

Target bands were treated as closed ranges regardless of the test's direction,
so on a lower-is-better test a player who ran faster than the green band fell
outside every band and was flagged red. In the reported case the three fastest
sprinters in a U12 squad showed red while the three slowest showed green. The
band is now open on the better side: beating it counts as green, amber and red
sit past it on the worse side only, and there is still no red threshold to
enter. Neutral tests keep both edges, since those values are meant to land
inside a range. The player profile's trend chart shades its band the same way.
