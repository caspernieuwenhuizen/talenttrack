# Teams: a single team shows its squad size and upcoming activities (#3601)

Opening one team over the API returned no squad size and zero upcoming
activities, while the teams list showed the real numbers for the same team.
The team card built from it showed an empty squad. The single-team response
now carries the same player count and next-fourteen-days activity count as
the list, card included.
