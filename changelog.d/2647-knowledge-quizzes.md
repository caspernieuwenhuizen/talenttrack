# Lesson checks that actually check something (#2647)

Bump: minor

Every module of the periodisation course has had five questions written for
it since the corpus shipped. None of them appeared anywhere. The payloads were
valid, the lessons declared they had a check, and there was no block on any
page to render one — so the sequential unlock waited on a quiz nobody could
take. The corpus lint now fails a PR where those two halves disagree, in
either direction.

The questions are live now, in four shapes: pick one, pick several, put a
sequence in order, and match two lists. Ordering uses a position box per item
rather than drag-and-drop — dragging is nicer with a mouse and unusable with a
keyboard, and typing a number is a real answer rather than a fallback bolted
beside a nicer one.

Marking happens on the server. That is not caution for its own sake: the file
that holds the questions also holds the answers, so anything that marked in
the browser would have to be given the answers first. The page a coach sees
with developer tools open is the page they see without them.

Options are shuffled every time a lesson is opened, which matters more than it
sounds. Every ordering and matching question in the course happens to be
stored in its correct sequence, so showing the options as filed would have
handed over the answer to all nine of them.

There is no partial credit and a skipped question counts as wrong. Half an
ordering is not half an understanding of a sequence, and a check you can pass
by answering only the questions you were sure of is not checking anything.

Every attempt is kept, passed or not — a coach who got there on the fourth try
has a different development record than one who got it first time, and the
head of academy reading that record should see both. Retakes are unlimited,
and the reason behind each answer is shown whether you got it right or wrong.

The whole thing is a plain form, so it still works with JavaScript switched
off; the script just saves you losing your place in a long lesson.
