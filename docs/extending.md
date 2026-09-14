# Extension points

DataDefinition is the main extension point. Its model/field map, query(), filters(), sorts(), permission methods, context and templateRows() keep business metadata in one place. Field normalizers/formatters and computed callbacks transform already bounded data. OptionsProvider receives trusted context plus a maximum result bound. RelationField::scope supplies a trusted relation restriction.

CurrentContext and ContextRestorer integrate host request/worker tenancy. ImportAuthorizer can implement intentional sharing/admin policy. NotificationRecipientResolver selects stored/verified recipients; OperationFinished subclasses/custom Laravel channels customize delivery. SystemPrincipal supplies a stable non-login creator for scheduled work.

Legacy SourceReader/ImportTarget/AtomicCommitter contracts remain available, but custom writers must preserve same-connection transactional receipts/accounting. An extension that writes external systems cannot assume the chunk receipt provides exactly-once external effects. Custom callbacks/readers must respect row limits, context isolation and query budgets.
