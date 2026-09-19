# A new PDP cycle plans its talks at 18:00, not at 07:59:59 (#3670)

When a PDP file was opened, the conversations it created were spread across the season in seconds rather than in days, so each talk picked up whatever time of day the division left over — 07:59:59, 15:59:58, 04:47:56. Files created from academy-configured cycle blocks always landed at 11:59:59 or 23:59:59. Every automatically planned talk now sits on a whole day at 18:00, which the coach moves to the real slot. The dates themselves are unchanged, and talks that already exist are left alone.
