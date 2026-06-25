# Trial pages overhaul — redesigned case page, warmer Dutch letters, friendlier configuration (#1646)

Bump: minor

The trial case page has been rebuilt to match the player and team profiles: a paper hero anchored by the player's photo and name, status / decision / track pills, a key-facts strip, and the content laid out in cards under tab navigation (Overview · Execution · Staff inputs, plus Decision · Letter · Parent meeting for the head of development). The old anchor-strip layout and its inline styling are gone; all styling now lives in the enqueued, mobile-first stylesheet. The post-decision summary now shows the decision's readable label instead of the raw internal code.

The shipped Dutch parent letters (admittance, decline-final, decline-with-encouragement) have been rewritten in a warm, informal "je/jullie" club voice, and a set of broken pronoun placeholders that previously printed literally in both the English and Dutch letters has been removed.

The trial tracks and letter-template configuration screens now open with plain-language guidance, label each letter by what it's for instead of an internal key, and carry per-field hints. Missing Dutch translations across the trial surfaces have been filled in so the pages read fully in Dutch.
