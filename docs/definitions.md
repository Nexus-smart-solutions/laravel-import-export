# One definition

DataDefinition extends the existing ImportDefinition. A developer implements model() and fields(); the field map supplies import columns, required headers, aliases, rules, normalizers, references, target payloads, export values and template instructions. No separate template definition is needed.

```php
final class CustomerDefinition extends \Nexus\ImportExport\Definitions\DataDefinition
{
    public function model(): string { return Customer::class; }
    public function fields(): array {
        return [
            \Nexus\ImportExport\Fields\Field::make('code')->column('customer_code')
                ->excelColumn('Customer Code')->required()->unique()->exportable(),
            \Nexus\ImportExport\Fields\Field::make('email')->email()->nullable()->exportable(),
        ];
    }
}
```

`unique()` fields form uniqueBy(); override uniqueBy() for a composite source key. targetUniqueBy() translates fields into DB attributes and adds the tenant key when configured. The database needs the corresponding unique index. Override duplicateStrategy(), version(), query(), filters(), sorts(), templateRows(), permission methods or context hooks intentionally. Version changes prevent old definition semantics from silently continuing an export/import.

fields() order controls default output order. Only exportable fields can be requested; make() defaults to import/template visibility and no export permission. Model accessors/computed callbacks must not introduce per-row SQL. Definitions are trusted application code, registered by resource name rather than supplied as class names by clients.
