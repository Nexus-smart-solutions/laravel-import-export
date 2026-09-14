# Optional tenant/workspace context

No tenancy is required: CurrentContext defaults to EmptyCurrentContext (`[]`), tenantColumn() defaults to null, and ownership works normally. Tenant ID is not a mandatory API field.

For shared-table tenancy, implement the trusted server integration:

```php
final class WorkspaceContext implements \Nexus\ImportExport\Contracts\CurrentContext
{
    public function get(): array
    {
        return ['tenant_id' => (string) app('currentWorkspace')->getKey()];
    }
}
// config/import-export.php: 'current_context' => WorkspaceContext::class
// DataDefinition:
public function tenantColumn(): ?string { return 'tenant_id'; }
```

The currentWorkspace binding is a host service resolved and authorized by server middleware. Never derive it from unverified request JSON/headers. Keep scalar context types stable. `bulk-imports.context.allowed_keys` controls permitted context keys. Override tenantContextKey() when using another configured key.

The definition injects the tenant database attribute and includes it in target unique keys. Scope applies to exports, belongsTo lookup and relation dropdowns; related tables use the same tenant column unless the field explicitly calls sharedAcrossTenants() for a global catalog. Use composite unique indexes such as tenant_id + student_code. Missing required context fails closed.

For database-per-tenant applications, bind `ContextRestorer` in `bulk-imports.contracts` to initialize the tenant before job metadata access and tear it down in a finally block. CurrentContext and ContextRestorer have distinct request/worker responsibilities. Package metadata and imported business rows must share the same connection for atomic receipts. Notification jobs also restore context before resolving creators.

New API routes reject forged identity/context fields. PHP metadata dispatch rejects a context different from CurrentContext. Guest tokens bind to the trusted context at issuance; creator identity remains an additional access constraint. Lists/status/downloads require the same context as creation. Run recovery/cleanup/notification-retry commands within each tenant metadata connection; a central scheduler must iterate trusted tenants explicitly.
