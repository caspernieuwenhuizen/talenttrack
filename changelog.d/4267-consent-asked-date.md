# A consent request cannot be dated in the future or on a day that does not exist (#4267)

When logging that the academy asked a prospect's club for consent, any date in the right format was accepted, including a future date or a mistyped year. The waiting counter and the "consent awaiting" alert then counted from that date, so a prospect found a week earlier could show as waiting 174 days. The date must now be a real date no later than today. The form's date picker stops at today, and an earlier date, even one before the prospect was entered, is still accepted.
