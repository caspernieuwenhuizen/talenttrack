# Team managers read the minutes share and monthly report for their own team (#4259)

A team manager was refused the minutes share for their own team ("You do not
have access to this team"), so before match day they could not see who was
short of playing time. The authorization matrix now grants the team manager
read on team reports at team scope, both on the team manager role and on the
Manager functional role held by a staff account. That opens the minutes share,
the monthly team report and its PDF for the teams they manage, and no other
team's. Existing installs get the grant with migration
`0301_authorization_seed_topup_manager_reports`, which only adds the missing
row and leaves any matrix cell an administrator has edited alone.
