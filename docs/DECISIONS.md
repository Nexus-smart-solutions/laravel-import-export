# Design decisions

The working import/export/template engines are preserved. One DataDefinition remains the source of field, relation, validation and representation metadata. Guest access is an opt-in credential identity, tenancy is optional server state, and CLI work has an explicit system owner. Notifications use separate receipts/jobs and isolate delivery failures from successful business operations. Independent PHP worker tests verify row/progress idempotency. Benchmarks record executed results; deployment behavior on native databases/brokers/storage is not inferred from SQLite evidence.
