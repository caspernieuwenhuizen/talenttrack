# Centralized cross-view link authorization affordances (#2304)

Bump: minor

Cross-view navigation links, tiles and buttons that point at another view are now hidden through one shared helper (`CrossViewLink`) backed by a registry that mirrors each target view's actual access guard, instead of hand-rolled inline capability checks that drifted from the destination. The measurements execution links (Manage tests, Record measurements, Testing coverage), the team-detail Planner link, the team-development chemistry and blueprint tiles, the activity methodology link, and the player "Chemistry attributes" action all route through it — same users see each link, with the player-attributes entry now correctly tightened to the per-player evaluation check the target enforces. A new diff-only CI gate stops future cross-view links from skipping the helper.
