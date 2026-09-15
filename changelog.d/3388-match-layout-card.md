# The live-match layout preference is gone from a player's settings (#3388)

My settings offered every persona a **Live match screen** card, asking them to
pick a layout for a surface only staff can open. A player saw a preference that
could never take effect, on a page that should hold two or three things.

The card now renders only for a user who holds `tt_edit_activities` — the same
capability the match screen itself enforces — and the form's handler asks the
same question again, so hiding the card and refusing its POST are not two
different answers.
