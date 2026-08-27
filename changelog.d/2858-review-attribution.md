# The review shows and corrects who scored (#2858)

Bump: patch

Each of our goals in the post-match **Match goals** list now shows the
scorer and, where one was recorded, the assist. A goal logged without a
scorer reads *Scorer not recorded* and an own goal reads *Own goal* —
previously both rendered as "Our goal", which told a coach nothing about
which of the two it was, and only one of them is something to go and fix.

With Edit on, every one of our goals carries a scorer and assist picker, so
a goal saved mid-match without an attribution can be put on a player's
record afterwards — and one attributed to the wrong player at the time can
be corrected. When any goal still has no scorer, the section says so above
the list. It is a reminder rather than a gate: finalizing stays available,
because a coach who never found out who scored still has to be able to
close the match out.

The *Add late goal* form matches the live sheet: the scorer is optional
there too, with an assist field and an own-goal box beside it. A goal added
days later is exactly the case where nobody is certain who touched it last.

Matches recorded before goals were logged individually keep their stored
score untouched. Where that score and the logged goals disagree, the review
now says so in a short note instead of presenting a figure the goal list
cannot account for as though the two agreed.
