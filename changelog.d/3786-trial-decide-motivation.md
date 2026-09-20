# The decide form and the API now agree, and say why they refused (#3786)

Recording a trial decision on the case screen kept its own copy of the motivation rule the API had already had fixed, and its copy counted bytes rather than characters — so a Dutch motivation written with accents cleared a floor the message describes in characters, and the two surfaces disagreed about the same text. There is one rule now, in the domain layer, and both call it.

A motivation that is too short no longer fails in silence. The Decision tab re-renders with the reason under the field — the minimum and how many characters you wrote — with everything you typed still in the form, including the outcome you picked. Losing a paragraph somebody wrote about a child was exactly the wrong way to fail.

The screen also stopped writing the player's status itself. That transition belongs to the trial-decision subscriber, which was already doing it; the screen wrote `archived` over it, which is not a status the product recognises. A decline with encouragement now leaves the player **Inactive**, as the documentation has always said, whichever surface recorded the decision.
