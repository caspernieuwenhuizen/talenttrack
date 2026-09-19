# My evaluations names the type in your own language (#3681)

A player or parent opening My evaluations saw the evaluation type in raw
English — a card labelled "Match" on a page that says *Wedstrijd* everywhere
else — and a match with no score recorded read "vs FC Groningen (—)". The type
is now resolved the same way the coach list has resolved it since v3.110, using
whatever label the academy gave it, and a match without a score simply names
the opponent instead of promising a result in brackets that was never there.
`GET /players/{id}/evaluations` gains a `type_localised` field alongside the
unchanged canonical `type`, so a non-WordPress front end prints the same word
the page does.
