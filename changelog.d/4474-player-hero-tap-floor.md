# Player profile: hero buttons reach 48 px on touch (#4474)

The action buttons in the player profile hero were 44 px tall on phones and tablets, under the 48 px tap target, because a rule of their own outranked the shared button floor. That rule and three other player-profile floors (the popover footer, the card actions and the injury row actions) are gone, so the profile's buttons follow the shared rule: 48 px under touch, 36 px minimum with a mouse.
