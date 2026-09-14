# System and CLI operations

The managers do not require an HTTP request, authenticated session, or tenancy. Give an operation a stable server identity:

```php
use Nexus\ImportExport\Auth\SystemPrincipal;
use Nexus\ImportExport\ExportManager;

$system = new SystemPrincipal('nightly.students');
$export = app(ExportManager::class)->dispatch('students', $system, ['format' => 'csv']);
```

ImportManager and TemplateManager accept the same identity. Names contain 1–100 identifier characters. A system identity owns its operations exactly like a user; ordinary users cannot access them by ID. SystemPrincipal is rejected by HTTP authentication middleware and is never constructed from API input. Host policies may explicitly permit it in a definition. For notifications bind a resolver to an approved recipient; no arbitrary email is inferred from the name.

The legacy ImportDefinition API remains available with its existing authorizer settings. Avoid anonymous null identities for new resources: use a user, an explicitly issued guest principal or a SystemPrincipal. CLI does not imply bypassing definition field permissions or tenant scope.
