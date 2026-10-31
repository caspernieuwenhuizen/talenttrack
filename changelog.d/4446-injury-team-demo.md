# Injury recovery reminders that fall due in demo mode reach the team staff (#4446)

A recovery reminder that fell due while demo mode was on found no team for a real player, because the player was read through the demo filter, so the reminder was assigned to nobody. The player's team is now read by id within the club, without the demo filter, so the reminder reaches the team's physio and head coach as it does outside demo mode. Reminders that were already open were never affected.
