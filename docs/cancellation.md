# Cancellation

POST /api/data/imports/{id}/cancel and /api/data/exports/{id}/cancel require creator/context authorization. Cancellation is cooperative: a request changes durable state and workers check between safe boundaries. Already committed partial import rows stay committed; cancellation does not roll back earlier chunks.

Export workers stop publishing when cancellation is observed and eventually mark cancelled. Temporary parts remain private until lifecycle cleanup. Batch cancellation can reduce pending work, but durable database state remains the authority. Expiring/revoking a guest credential affects access and does not itself cancel the operation.
