# The PDP conversation form follows the player's position (#4978)

A conversation held on a conversation form now gets the goalkeeper form when the player's first preferred position is goalkeeper, and the outfield form otherwise. The conversation screen shows which form the talk uses, and the trainer can switch to the other one until the player has saved anything. The form and its version stay with the conversation from its first save, so a new version of the form never changes a talk already started. Every screen that shows a player's preferred positions now reads them the same way. Three small visible changes come with that:
- **Empty list on the rate card:** the coach dashboard's rate card showed the literal "[]" for a player whose position list was empty, and now shows no position ("—").
- **Blank first position on the rate card:** it now shows the next one.
- **Malformed list in the activity lists:** where they printed "Array", they now print the positions they can read.
