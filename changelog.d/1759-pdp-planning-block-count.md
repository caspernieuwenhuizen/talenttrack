# PDP planning grid follows the configured block count (#1759)

Bump: patch

The PDP planning matrix used to derive its number of block columns from the
highest block sequence found across stored conversations, so a legacy or
seed conversation carrying block 4 made the grid show 4 columns even when the
season was configured for 2. The grid now follows the academy's configured
PDP block count for the season (`tt_pdp_blocks`); blocks beyond the configured
count are no longer drawn. When a season has no blocks configured, it falls
back to the previous data-derived behaviour so legacy even-divide installs are
unchanged.
