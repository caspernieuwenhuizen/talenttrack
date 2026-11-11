# Lists never show items in the recycle bin (#5118)

The goals, evaluations and holidays lists now follow the same rule as the activities list: an item in the recycle bin stays out of the list, whatever archive state the list is asked for, until it is restored. The wp-admin lists of players, teams, people, activities, evaluations and goals follow it too. The recycle bin keeps its own screen. One shared rule now decides this for every list, and a check keeps new lists on it.
