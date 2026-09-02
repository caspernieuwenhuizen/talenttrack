# The unit of measure is now part of the measurement (#3273)

Bump: minor

A test's unit used to be a caption printed after a number. It is now a property
of the datum: every unit belongs to a dimension — time, length, mass, count,
rate, percentage or level — and knows its factor to that dimension's SI base.
Values are stored canonically (seconds, metres, kilograms) and shown in the unit
the academy measures in, and each recorded result also keeps the unit and the
number it was entered in, so changing a test's unit later can no longer rewrite
the meaning of readings already taken.

This fixes a silent data error in the growth surfaces. The BMI series and the
player's `height_cm` both identified the height test by name and then assumed
centimetres; `m` has always been a selectable unit, so an academy recording
height in metres got a BMI two orders of magnitude out with nothing reporting a
problem. Both now convert through the unit instead of dividing by a hundred.

Time tests can be entered and shown as **mm:ss**. Tick "Enter and show as mm:ss"
on a test measured in a time unit and a result is typed as `5:30`, reads back as
`5:30`, and is stored in seconds — so trends, averages and target bands work on
the real quantity. Target bands are typed in the test's own unit throughout.

A custom free-text unit still works, and is now explicitly dimensionless: its
values are stored exactly as entered and never converted or compared across
units.
