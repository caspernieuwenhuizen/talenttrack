# Player report: show evaluations by main category or with subcategories (#3989)

Bump: minor

The player report's Evaluations section can now show subcategory scores. When an evaluation in the period rated subcategories, the panel offers **Main categories** (the default, unchanged) or **With subcategories**. The detailed view lists each rated subcategory indented under its main category, with its own latest and average score, on screen and in the PDF, and the print-fit meter counts the extra rows. The choice is a per-section option on the report composition, following the team report's pattern, so saved views, shared links, snapshots, family shares and monthly schedules keep it. `GET /players/{id}/report` and `POST /players/{id}/report-snapshots` accept `options={"ratings":{"detail":"sub"}}` and refuse an unknown option key. Each evaluation category row in the evidence packet now carries `parent_id`.
