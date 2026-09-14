# Failures and reports

Failures are buffered and bulk-stored with source row number, field, code, message, sheet/location and bounded raw/normalized row information. FailureType separates structural, validation, reference, duplicate, persistence and system categories where recorded. File failures also appear on the operation; a malformed source need not have row failures.

An error-report job streams failure rows by ID to CSV. Output neutralizes formula injection. Access requires owner/context authorization; private storage paths are not client-selectable. Retention and redaction settings are in bulk-imports.failures. Configure sensitive business columns beyond the default password/token/secret list. Avoid including row secrets in custom exception messages or logs.

A notification contains aggregate status only and is not a replacement for the protected failure report. Persisted diagnostic data and disk usage should be included in the host retention policy.
