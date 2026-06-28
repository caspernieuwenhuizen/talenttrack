# PDP conversations: only the active conversation is fully editable (#2041)

Bump: patch

PDP conversations now run strictly in order. Only the active conversation —
the earliest one not yet signed off — is fully editable. Later conversations
in the cycle are read-only except for their planned date, so a coach can
schedule the whole season ahead without filling in a talk out of turn. A
later conversation opens for full editing once the one before it is signed
off. Enforced both in the form and in the REST endpoint. Signed/acknowledged
conversations keep their existing end-to-end lock.
