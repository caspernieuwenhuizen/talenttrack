# MFA QR encoder — independent round-trip verification + CI gate (#1393)

Bump: patch

Closes out the MFA-enrollment-QR bug. The payload + render fixes shipped earlier
(smaller otpauth URI, no silent truncation, larger render); the remaining risk was
that the hand-rolled QR encoder's v6–v10 paths — the only ones a real otpauth URI
ever exercises — were unverified. A new standalone check
(`scripts/qr-roundtrip-verify.php`, run in CI) encodes a representative corpus with
the production encoder, decodes each result with an independent from-spec ISO/IEC
18004 decoder, and asserts the decoded string equals the input. All versions v6–v10
round-trip cleanly, proving the encoder is correct, and the gate prevents
regressions. No user-facing change.
