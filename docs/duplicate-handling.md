# Duplicates and business identity

Declare unique fields or override uniqueBy(), and install the matching database unique constraint. For tenant resources include the tenant database attribute in target uniqueness; the metadata definition supplies it from trusted context.

ERROR reports an existing key, SKIP ignores an existing key, UPDATE updates existing keys and skips missing ones, and UPSERT inserts or updates. Preparation records first source-key claims across chunks; later repeated source keys follow configured behavior. Database constraints are the final protection against concurrent writers. The import fingerprint includes actor/context/definition version/options by default, so one creator cannot replay another creator's private import.

Chunk receipt/progress and business writes share a transaction. A retry after a committed chunk returns without writing again. Intentional whole-file resubmission uses the configured idempotency strategy; do not remove unique constraints to make a failed import pass. Definitions and callbacks must keep key normalization consistent with database collation/types.
