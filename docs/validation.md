# Validation

File preflight checks extension/MIME, readable upload, size, headers and corruption indicators. CSV records and XLSX ZIP size/compression/entries are bounded. Header normalization handles case, whitespace, BOM and aliases; duplicate/ambiguous mappings and missing required/key columns are rejected.

Fields normalize before Laravel row rules. required(), nullable(), type methods and rules(array) describe accepted values. Ordinary scalar validation is supported. Database exists/unique rule strings and standard rule objects are rejected; use declared bulk references and business keys. Custom rules remain trusted code and must not introduce row-level queries. Rows with missing/invalid references, invalid options and duplicates become structured failures according to the definition strategy.

Dry run uses the same parse/normalize/resolve/validate/duplicate flow while skipping business writes. Its metadata, spool and failures still persist. would_insert and would_update are estimates at validation time and cannot lock the future database state until a later real import.
