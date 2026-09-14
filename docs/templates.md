# Templates from the definition

TemplateManager::generate(resource, actor, format='xlsx') returns an owned temporary path; the caller must delete it. download() returns a response that deletes its temporary file after sending. Format can be csv or xlsx. Empty templates never query business records, though configured relation dropdowns query bounded reference values.

XLSX output contains a Data sheet, Instructions sheet, and hidden reference options when needed. Instructions list required/type/lookup details and examples. Header order follows the definition. Field examples describe how to fill a value; data sample rows appear only when templateRows() explicitly returns them. CSV has a header row and any configured samples, without Excel validation/instructions sheets.

```php
return app(\Nexus\ImportExport\TemplateManager::class)->download('students', $actor);
$export = app(\Nexus\ImportExport\TemplateManager::class)->withData('students', $actor, ['format'=>'xlsx']);
```

withData reuses the asynchronous export engine: current values use import-compatible headers, option representations and relation lookup values. It does not append the empty template's instructions/dropdown sheets. Download → edit → re-import uses the definition's business unique keys and duplicate strategy. See [exports](exports.md).
