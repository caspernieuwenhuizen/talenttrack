Bump: patch

Match preparation's save indicator now uses the shared component that every
autosaving surface will use, so the words a coach reads while their work is
being stored are the same wherever they are. Nothing changes about what is
saved or when.

One thing does improve: two saves can no longer be in flight at once. The old
loop let overlapping requests race, and whichever answered second won regardless
of which was typed second — so a fast typist could occasionally watch a
character disappear. There is now one request at a time, with the next carrying
whatever has been typed since.
