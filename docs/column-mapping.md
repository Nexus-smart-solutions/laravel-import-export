# Database ↔ Excel mapping

| Meaning | API | Example |
| --- | --- | --- |
| Logical field | make('school') | school |
| DB destination | column('school_id') | students.school_id |
| Import header | importColumn('School') | School |
| Export header | exportColumn('School Name') | School Name |
| Template header | templateColumn('School') | School |
| Accepted aliases | aliases(['School Code']) | School Code |
| Visible selection | codeAndLabel('name') | SCH001 - Future Language School |
| Lookup value/column | importBy('code') | schools.code = SCH001 |
| Stored owner key | storeUsing('id') | school_id = 17 |
| Export representation | exportUsing('name') | Future Language School |

excelColumn('School') sets all three headers together before optional overrides. The bundled Student definition keeps School as its ordinary export header; add exportColumn('School Name') for the alternative heading above. Both use the same relation metadata and values.

An unrelated attribute/header mapping is `Field::make('birth_date')->column('date_of_birth')->excelColumn('Birth Date')`. The Product example maps price to unit_price and uses separate upload/export labels. Requested API fields are logical names, not database column names or user-provided SQL.

A label-only relation import is appropriate only when the lookup label is stable/unique and configured as importBy(). The code-and-label mode preserves a human-readable choice while using the code as the lookup identity. Stale label text does not change that identity.
