# Normalization and formatting

Field methods normalize trimmed/empty values, email case, booleans, date/datetime values and configured options before validation. Field defaults are explicit; omitted optional columns are not blanket instructions to erase existing attributes. Dates require Y-m-d and datetimes require Y-m-d H:i:s; Excel date objects are converted to these formats. Decimals use Laravel numeric validation, without locale-specific separator conversion or arbitrary precision arithmetic. Phone transformation is application-specific where needed.

normalizeUsing(Closure) receives the value and trusted context. formatUsing(Closure) controls output representations. computed(Closure, eagerLoads) reads already loaded data for an export; callbacks are trusted application code and must not issue avoidable per-row queries. exportLabels(false) chooses internal option values for ordinary exports. Import-compatible export selects representations acceptable to the field's configured OptionInput.

Spreadsheet formulas are not executed. CSV formula-safe output prefixes dangerous leading values; this may add a literal apostrophe on re-import, so use XLSX string cells for exact formula-like text workflows or apply a deliberate application convention.
