# Families can read the development plan over the API, not only acknowledge it (#3645)

A parent could already sign off a development talk through the API, but had no route on which to read the plan the talk belonged to — the PDP file routes are staff surfaces and answered "no access". So over the API a guardian could acknowledge something they could not see, and anything that is not the My PDP screen — a future app, an integration — had nothing to show them.

`GET /players/{id}/pdp` now answers with the plan exactly as My PDP shows it: the season, the conversations with their dates and state, the acknowledgement columns, the goals discussed, the active goals and the end-of-season verdict. A talk's notes and agreed actions stay empty until the coach signs it off, and the coach's private preparation is never included. A player reads their own plan, a guardian reads their child's, and a child who has hidden their plan from a parent has hidden it here too. The staff file routes are unchanged.

The My PDP screen itself now renders from that same reader, so what a family sees on the page and what they get from the API can no longer drift apart.
