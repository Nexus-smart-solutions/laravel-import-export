# Relation mappings

| Domain association | Import field on | Spreadsheet | DB write |
| --- | --- | --- | --- |
| School hasMany Students | Student belongsTo School | SCH001 - Future Language School | students.school_id = 17 |
| Classroom hasMany Students | Student belongsTo Classroom | CLS01 | students.classroom_id |
| Country hasMany Students | Student belongsTo Country | EG | students.country_id |
| Category hasMany Products | Product belongsTo Category | Category code | products.category_id |

A parent's hasMany relationship does not make a child's foreign-key import a hasMany import. The Student definition resolves belongsTo lookups in batches. ExportMapper collects foreign keys from each export page and fetches related values in bounded WHERE IN queries. The existing 5,000-row regression checks three relation queries for import and three for export using the three Student relations.

Order plus Order Items is a different, true parent/child import. Generic child-sheet orchestration and pivot synchronization are not implemented. A safe domain extension needs staged parent/child identities, sheet dependencies, atomic publication decisions, bounded bulk writes and idempotent receipts. Same-schema split XLSX sheets supported by this package do not represent child tables.
