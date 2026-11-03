# Team assignment dates accept only a real date (#4905)

A staff member's team assignment start and end dates are now either empty or a real calendar date written as YYYY-MM-DD. Creating or changing an assignment over the REST API with any other value answers `400 invalid_field` naming the field, and nothing is written; the staff form on the team edit page shows its error message and writes nothing. An empty date is stored as no date, as before.
