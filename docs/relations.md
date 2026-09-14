# belongsTo relations

```php
RelationField::make('school')->column('school_id')->excelColumn('School')
    ->belongsTo('school', School::class)->importBy('code')->storeUsing('id')
    ->exportUsing('name')->templateUsing('code')->codeAndLabel('name')
    ->dropdown()->required()->exportable();
```

The source code is normalized, distinct lookup values are collected for a chunk, related rows are queried in bounded batches and a local map resolves destination foreign keys. No relation query is issued per row. Ambiguous lookup matches are not silently treated as a unique relationship; enforce appropriate unique indexes.

ERROR, SKIP_ROW and NULL are available missing-relation strategies. required()/nullable() and database nullability must agree with the selected strategy. CREATE is not implemented. Generic belongsToMany, hasMany child mutation and arbitrary pivot synchronization are intentionally unsupported.

Export representation is separate from lookup/stored keys. Import-compatible exports use template-compatible codes or code-and-label values. Nested singular exportFrom paths and explicitly preloaded computed callbacks can be used, with page-bounded loading. Shared-table tenant scope applies unless sharedAcrossTenants() explicitly marks a global related catalog; scope(Closure) supplies additional trusted restrictions.

`templateUsing()` selects the same lookup column as `importBy()`; it is an alias, not a separate identity. The last call wins. This keeps template and import-compatible export values resolvable by the importer. Ordinary `exportUsing()` remains independent.
