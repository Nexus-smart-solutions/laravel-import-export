# Public API

Main services: ImportManager::dispatch/retry/cancel, ExportManager::dispatch/download/retry/cancel, TemplateManager::generate/download/withData. Resource names resolve only through the trusted definitions registry. Definitions and Fields are documented in definitions.md and fields.md.

| Method/path under /api/data | Result |
| --- | --- |
| GET resources/{resource} | Permission-filtered metadata |
| GET resources/{resource}/template | XLSX by default; CSV selectable |
| GET resources/{resource}/template?with_data=true | 202 asynchronous compatible export |
| POST resources/{resource}/imports | Multipart file/options; 202 ImportResource |
| POST resources/{resource}/exports | Whitelisted export request; 202 export metadata |
| GET imports, exports | Creator/context-scoped lists |
| GET imports/{id}, exports/{id} | Authorized status/progress |
| GET imports/{id}/failures | Paginated protected failures |
| GET imports/{id}/failure-report | Generated report download |
| GET exports/{id}/download | Completed, unexpired authorized export |
| POST imports/{id}/retry or /cancel | Authorized lifecycle control |
| POST exports/{id}/retry or /cancel | Authorized lifecycle control |
| POST resources/{resource}/guest-token | Opt-in, throttled 201 credential response |
| DELETE guest-token | Revoke the authenticated guest credential; 204 |

Export request example: `{"format":"csv","fields":["student_code","name","school"],"filters":{"status":"active"}}`. API field selection uses logical names. Unknown/hidden fields and raw filters are rejected. Import options support dry_run, mapping and chunk_size; identity/context/recipient input is rejected. HTTP status can be 401/403/404/409/410/422 according to authentication, permission, resource/state/expiry or validation.

Exports use snake_case counters; legacy-compatible ImportResource uses camelCase progress members. Physical disk/file paths and raw guest credentials are absent from status output. See source resources/controllers for the exact schema. Legacy `/api/imports` routes remain separately configurable; their template endpoint returns metadata, while the new template route generates a file.
