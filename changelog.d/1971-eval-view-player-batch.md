# Evaluations view: one batched query for the coach player filter (#1971)

The evaluations list page built its player-filter dropdown by running one
player query per coached team — an N+1 that scaled with a coach's team
count. It now loads every active player across the coach's teams in a single
batched query. The rendered options are identical; this is a pure
performance change with no behaviour or output difference. Closes the last
N+1 on the perf umbrella's suspect list (#1649).
