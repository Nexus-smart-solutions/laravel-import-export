# Bounded Excel dropdowns

Static options and opt-in RelationField::dropdown() values populate hidden reference cells, named ranges and Excel list validation. The streaming writer creates the small template; a bounded OOXML edit adds validation. Massive import/export workbooks never pass through a whole-workbook DOM.

Defaults: dropdown_limit 5000, total_option_cells 20000, sample_limit 100, validation_rows 10000. Relation queries read at most limit + 1; oversized lists are omitted and instructions explain the expected free-text lookup. Validation applies to a range, not a distinct formula for every cell. Do not increase limits to hundreds of thousands of related values.

`codeAndLabel('name')` gives a choice such as SCH001 - Future Language School. The code is the authoritative lookup key. Use codes that cannot contain the configured separator. Spreadsheet data validation is a user aid; backend normalization/validation still decides whether imported data is acceptable.
