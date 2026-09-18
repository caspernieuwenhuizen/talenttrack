# Support access is time-boxed, and the club can see it (#3501)

Bump: minor

Helping a club used to mean asking a coach to describe what they see, or
reaching for a developer override that nothing time-boxed and the club could
not inspect.

TalentTrack support now reaches an install only under a **support grant**: one
named operator, one named reason, one end time. While a grant is live, every
signed-in person at the club sees an orange banner — in the app and in wp-admin
— naming all three. **It cannot be dismissed.** That visibility is the whole
safeguard: a grant a club cannot see is indistinguishable from a back door, and
this product holds children's records.

Three things hold whatever happens:

- **Every grant ends on its own**, enforced on the club's own install, so it
  closes on time even if the site cannot reach us for a week.
- **Withdrawing a grant takes effect on the next check-in** — the install
  replaces the grants it holds rather than adding to them, so a revoked grant
  is simply gone.
- **Starting a session records which grant it ran under**, so "who had access,
  and what did they do" is answerable from the club's own logs.

A club's own administrators are untouched. The grant adds a requirement for
support accounts only, and can never stand between an academy and its own
records.
