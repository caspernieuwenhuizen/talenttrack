# MFA issuer no longer doubles the brand name (#2426)

Bump: patch

On an install whose site name already opens with the brand, the MFA enrollment
step showed a doubled issuer — site name `TalentTrack Local` produced
`TalentTrack TalentTrack Local`, both on screen and inside the otpauth URI.
That string is what the user then sees as the account name in their
authenticator app, and re-enrolling is the only way to change it.

The guard matched only the exact string `TalentTrack`, so anything merely
starting with it fell through to the concatenation. A site name that already
begins with the brand is now used as-is; one that doesn't still gets
`TalentTrack ` prepended, and an empty site name still falls back to the bare
brand. As a side benefit the URI gets shorter — the issuer appears in it twice,
so the duplication was costing the QR-version budget double.

Existing enrollments are unaffected; the issuer is display metadata recorded by
the authenticator app at scan time, not part of the shared secret.
