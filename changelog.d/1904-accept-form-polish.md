# Invitation accept-form polish: recovery-email hint + silent-link relationship (#1904)

Bump: patch

Two onboarding-correctness tweaks on the invitation accept flow. The **recovery email** field now carries a short note that it's pre-filled from the invitation and only used for password recovery (and can be changed), so an invitee doesn't enter a wrong or shared address by mistake. And the **silent-link** path (a logged-in parent whose email matches) now asks for the **relationship** (parent / mother / father / guardian) just like the full form — previously it linked silently with an assumed role, so a grandparent or carer could be recorded incorrectly. The relationship is threaded through `silentLink()` into the existing linking step. Part of the go-live-readiness epic (#1723).
