# Players list toolbar now matches the standard register card (#1791)

The players list filter/search bar now renders as the standard 2026
"register" card — white surface, soft shadow, comfortable padding, and
rounded, bordered controls — instead of the earlier soft-grey strip with
square-cornered inputs. The toolbar and the table read as two matching
cards, the same chrome every other list uses. The rounded-control fix is
in the shared list-table component, so any list that didn't already style
its own controls now gets rounded search/filter inputs too. Restyle only;
filtering, search, and sort behaviour are unchanged.
