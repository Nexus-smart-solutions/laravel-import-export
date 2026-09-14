# Events

The legacy Events directory exposes ImportCreated, ImportQueued, ImportStarted, ImportChunkStarted/Completed/Failed, ImportProgressUpdated, ImportCompleted, ImportCompletedWithErrors, ImportFailed and ImportCancelled, plus lifecycle events used by the existing engine. ImportEvent contains scalar identity/context/status and aggregate counters.

Exports emit ExportChanged(exportId, phase, context); phases reflect creation/processing/completion/failure/cancellation. QueueCompletionNotice listens to terminal import events and terminal ExportChanged phases. No per-row notification/event is emitted by default.

Event listeners are application code. Keep them bounded and avoid row queries. The built-in notification dispatcher isolates its failures, but unrelated host listeners can still throw and should be designed deliberately. Terminal notification receipt creation follows commit and is not a fully transactional outbox guarantee.
