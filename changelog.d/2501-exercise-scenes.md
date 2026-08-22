# Draw an animated scene for a drill (#2501)

Bump: minor

An exercise can now carry a **scene** — a small animated diagram of the drill,
with players, opponents, the ball, cones and goals on a pitch, and the
movements you want them to make. Open an exercise and press **Draw a scene**.

The editor is built around one gesture: drag a marker on the pitch and it
records where that marker is at the moment the playhead is on. Scrub to two
seconds, move the left-back forward, and the left-back now runs forward over
those two seconds. A timeline, a marker palette, a line tool (pass, dribble,
run, shot, press) and forty steps of undo are there for everything the drag
does not cover, and the arrow keys move a marker without a mouse.

A saved scene shows up in three places — the exercise page, the sideline view
while the training runs, and the printed A4 sheet. All three draw it with the
same code, so they cannot drift apart; on paper it becomes a still picture of
the scene's final frame, which is also what a reader who prefers reduced motion
sees on screen.

Scenes are stored per exercise and validated on the way in, so a diagram that
reaches the database is always one that renders. Coordinates off the pitch are
pulled back onto it, keyframes are sorted, and a line drawn to a player who has
since been deleted is dropped rather than left pointing at nothing.

Drawing works best on a tablet or a desktop. On a phone you can watch a scene
and move a marker, but the timeline wants more room than a phone has.
