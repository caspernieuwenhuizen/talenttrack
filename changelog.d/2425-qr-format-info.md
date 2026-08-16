# MFA QR codes now scan (#2425)

Bump: patch

The QR code on the MFA enrollment step could not be read by any authenticator
app. The encoder wrote the 15 format-information bits in reverse order, so the
result was not a valid BCH(15,5) codeword — conforming scanners locate the
symbol, fail format validation, and stop before reading any data. Every QR
version the encoder can emit (v1–v10) was affected, so scanning has never
worked; only the manual-entry fallback did.

The fix is one expression in `QrCodeRenderer::writeFormatInfo()` — the bits are
now placed most-significant-first per ISO/IEC 18004 §7.9.1. The rest of the
encoder was already correct: data encoding, error correction, mask selection,
alignment patterns and version-info blocks all verified module-for-module
against an independent encoder.

The round-trip CI gate missed this because its decoder shared the encoder's bit
order — it read back LSB-first what the encoder wrote LSB-first, recovered the
right mask, and passed. Two encoders agreeing proves nothing when one wrote the
other. The verifier now reads the strip most-significant-first and additionally
asserts the format bits are one of the 32 legal BCH codewords encoding ECC
level L, and that the primary and mirror copies agree. That check needs no
third-party decoder and fails loudly if the bit order is ever reversed again.

Users who enrolled via manual entry are unaffected and need not re-enroll.
