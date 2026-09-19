# The attendance grid is only offered where there is a register to enter (#3656)

Opening next week's training and pressing **Record attendance** used to land a
coach on an empty attendance grid: since v4.126.0 the grid shows an upcoming
activity only once something is recorded on it, but the buttons leading into it
did not know that rule. The **Attendance grid** action, the list card's fix
link and the **Record attendance** button in the mark-completed dialog now
follow the grid's own column rule, so they appear on an activity that has taken
place or that already carries a pre-recorded absence, and stay hidden on an
upcoming one that carries nothing. The grid and its entry links now read that
rule from one place, so they cannot drift apart again.

Also on that screen: the period pills and the **Clear** link kept the "back to"
target intact when it carried a query string of its own, instead of spilling
its parameters into the link and leaving the back pill pointing at a mangled
URL.
