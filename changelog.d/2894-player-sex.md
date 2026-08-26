# A player record can record sex, for growth references (#2894)

Bump: minor

Age-adjusted height, weight and BMI are read against published growth curves,
and those curves are separate for boys and girls. The player record had date of
birth, height and weight but nothing about sex, so none of those age-adjusted
figures could be calculated at all. This adds the field the **Player · BMI-for-age**
report needs.

It is deliberately narrow. The field is labelled for what it is used for, the
help text says why it is asked, and the list is fixed at male and female because
a growth reference publishes exactly two curves — an editable list would suggest
the reference follows it, which it does not. This is not a record of how a young
person describes themselves and should not be used as one.

**Optional everywhere, and blank on every existing player.** Nothing is filled in
or guessed from a name. Leaving it blank costs that player only the age-adjusted
columns; height, weight and raw BMI still read normally.

Available on the player form, the WordPress admin player page, the API, and the
demo-data import and export, so a generated academy keeps the field on a
round-trip.
