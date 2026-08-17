# Ratings grid: collapsed categories no longer pull the header off its columns (#2474)

Bump: patch

Opening the ratings grid on an activity whose categories have sub-categories
showed a header detached from the data: the first main category stretched
across every score column and the ones after it sat over empty space. It hit
every not-yet-rated activity, because groups start collapsed until a
sub-category holds a score.

The main category headers were spanning their sub-columns even while those
were folded away. A folded column is removed from the table altogether, so the
extra width was columns no row ever filled, and each following group drifted
one block to the right. The header now spans what is actually on screen, and
follows along when a group is folded open or shut. A main category with no
score of its own keeps an empty placeholder column while collapsed, so its
label and expand toggle still have a column to sit over.
