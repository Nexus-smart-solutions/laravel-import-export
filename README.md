# Nexus Enterprise Import Export


A PHP 8.3+ / Laravel 12 package with one metadata-driven `DataDefinition` for imports, exports and spreadsheet templates. Existing chunked imports, exports and template writers are retained.

```php
use Nexus\ImportExport\Fields\RelationField;

RelationField::make('school')->column('school_id')
    ->excelColumn('School')->exportColumn('School Name')
    ->belongsTo('school', School::class)
    ->importBy('code')->storeUsing('id')->exportUsing('name')
    ->templateUsing('code')->codeAndLabel('name')->dropdown()
    ->required()->exportable();
```

| Excel value | Lookup | Stored value | Export value |
| --- | --- | --- | --- |
| SCH001 - Future Language School | schools.code = SCH001 | students.school_id = 17 | Future Language School |

Register your definition in `config/bulk-imports.php`, then:

```php
use Nexus\ImportExport\{ImportManager, ExportManager, TemplateManager};

$template = app(TemplateManager::class)->download('students', $request->user());
$import = app(ImportManager::class)->dispatch('students', $request->file('file'), $request->user());
$export = app(ExportManager::class)->dispatch('students', $request->user(), [
    'format' => 'xlsx', 'fields' => ['student_code', 'name', 'school'],
]);
$updateFile = app(TemplateManager::class)->withData('students', $request->user());
```

Imports stream into checksummed JSONL chunks and Laravel Bus batches; business writes, receipt and counters commit together. Exports use durable keyset pages, immutable parts and revision fencing. CSV/XLSX processing is bounded by configured chunks; large XLSX exports split sheets automatically. Templates include instructions and bounded option/relation dropdowns. Definitions whitelist fields, aliases, options, validation, normalization and relations.

Backend access defaults to the creator. Guest access is explicitly enabled per package and definition, using expiring, revocable 256-bit bearer tokens stored as hashes. Tenancy is optional. `SystemPrincipal` supports scheduled/CLI jobs with a stable owner. Optional notifications resolve the stored creator or a verified guest/server recipient. Notification delivery or broker failures do not change a successful import/export into a failure.

Start with [installation](docs/installation.md), [quick start](docs/quick-start.md), and the complete [StudentDefinition](examples/Enterprise/Definitions/StudentDefinition.php). [Product](examples/Enterprise/Definitions/ProductDefinition.php) and [Employee](examples/Enterprise/Definitions/EmployeeDefinition.php) definitions demonstrate reuse. The [documentation index](docs/index.md) covers access, relationships, queues and deployment.

```bash
composer install
composer test
composer analyse
composer format:check
composer validate --strict
```

The pre-rename release verification baseline is summarized in [verification](docs/VERIFICATION.md), and measured benchmark evidence is retained in [performance](docs/performance.md). Native MySQL/PostgreSQL, Redis/Horizon and S3 deployment still need host-specific validation. Generic pivot/child-sheet synchronization and generic million-row atomic publication remain outside scope. See [limitations](docs/limitations.md).

## Proprietary license

Copyright © 2026 Nexus Smart Solutions. All Rights Reserved.

This package is proprietary software owned by Nexus Smart Solutions. Use is permitted only under a valid written or commercial license from Nexus Smart Solutions and within that license's scope. Redistribution, sublicensing, public source disclosure, resale, or relicensing requires prior written permission from Nexus Smart Solutions. Authorized integration into client applications does not grant permission to redistribute or sell this package separately.

See [LICENSE.md](LICENSE.md) for the full terms. Third-party dependencies retain their respective licenses; their terms are not replaced by this package's proprietary license.
