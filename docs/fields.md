# Fields and visibility

Field::make(name) declares the logical key. column() selects the database attribute. excelColumn() sets all spreadsheet headers, while importColumn(), exportColumn() and templateColumn() override them individually. label() is a convenience for spreadsheet labeling. aliases() accepts additional import headings; case/whitespace/BOM normalization and ambiguity checks apply.

Fluent options include required(), nullable(), unique(), rules(array), default(value), example(value), description(), instructions(), importable(), exportable(), templateVisible(), importOnly(), exportOnly(), templateOnly(), hidden() and internal(). A field is not exportable until explicitly enabled. Hidden/internal fields are not an HTTP column escape hatch. Use server context for tenancy, not an importable tenant column.

Types: string, text, integer, decimal, boolean, date, datetime, email, phone and uuid. normalizeUsing() and formatUsing() customize conversion; computed(callback, eagerLoads) and exportFrom(path) support controlled export values. Inspect the [Field implementation](../src/Fields/Field.php) for exact signatures and [mapping tables](column-mapping.md) for representation differences.
