# Assigning a course to someone already on it no longer claims to have moved their deadline (#3707)

Assigning a course over the API to a coach who was already enrolled answered
"created" and handed back the old deadline, so an academy admin trying to push
a missed deadline forward was told it had worked while nothing had changed. The
enrolment is still left untouched — re-assigning must never reset a
half-finished course — but the answer is now honest: it says the person was
already enrolled, and, when the request carried a different deadline, that the
new one was not applied. Enrolling someone new is unchanged, and the assign-course
wizard already reported this correctly on screen.
