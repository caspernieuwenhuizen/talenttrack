# Goals tab: the Dutch deadline badge no longer overlaps the goal title (#3032)

On the player profile's Goals tab in Dutch, the due-date badge's label
("DEADLINE") was wider than the fixed 44px column it rendered into and painted
across the goal title beside it. The column now sizes to its content with 44px
as the floor, so short month badges are unchanged and a longer label in any
locale gets the room it needs.
