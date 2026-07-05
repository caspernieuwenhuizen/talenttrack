# Match execution: undo a substitution, reload-safe goal/sub undo (#2269)

Bump: patch

Every logged goal and substitution in the Live progress feed now carries an
inline Undo that works even after a page reload, because it is keyed to the
stored event rather than a short-lived tap memory. A just-logged
substitution can also be undone straight from its confirmation toast.
