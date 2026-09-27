# Excel import: imported matches count towards suspensions straight away (#4121)

The Excel importer writes its activities as completed without firing any hook, so a suspension whose last match arrived through an import stayed open until that match was next saved. The importer now fires `tt_activity_marked_completed` once for every activity it writes, after its attendance is in, and listeners such as the suspension service settle during the import.
