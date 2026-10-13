# ADR 001: Preserve Ambiguous Transactions

## Status

Accepted

## Context

Financial institutions do not always provide a reliable unique identifier for every exported transaction.

Nubank CSV exports currently provide an `Identificador` field that can be used as a reliable transaction code.

The supported Banco Inter CSV format does not provide an equivalent unique identifier.

It is tempting to generate a transaction identifier using fields such as:

```text
account
date
description
amount
balance
```

However, this combination is not guaranteed to be unique.

For example, consider the following sequence:

```text
João → Maria: R$ 100
Maria → Pedro: R$ 100
João → Maria: R$ 100
```

If all transactions occur on the same day, Maria's first and third transactions may contain exactly the same:

- date;
- transaction type;
- description;
- amount;
- resulting balance.

A hash generated from those fields would therefore be identical even though two legitimate financial transactions occurred.

Using such a hash as a unique transaction identifier could silently discard real financial data.

## Decision

The application will distinguish between a transaction identifier and a transaction fingerprint.

### Reliable source identifier

When the source institution provides a reliable identifier, it may be stored in:

```text
transaction_code
```

Example:

```text
Nubank Identificador → transaction_code
```

### No reliable source identifier

When the source does not provide a reliable identifier:

```text
transaction_code = null
```

The application will not fabricate an absolute transaction identity from transaction attributes.

Instead, it may generate:

```text
transaction_fingerprint
```

The fingerprint represents similarity only.

Multiple transactions are allowed to have the same fingerprint.

## Duplicate Detection

When two or more transactions share a fingerprint, they may be classified as possible duplicates.

The system must preserve all of them.

The user may then resolve the ambiguity.

Possible states include:

```text
suspected
confirmed_duplicate
confirmed_distinct
```

A possible user-facing warning is:

> This transaction appears more than once. Should these be considered different transactions?

The user may confirm that:

- the records represent different transactions; or
- one represents a duplicate of another.

## Confirmed Duplicates

Confirming a duplicate must not require deleting the imported transaction.

Instead, the application may mark the transaction so that it is excluded from normal financial calculations.

For example:

```text
ignored_at != null
```

The original transaction record remains available.

## Rationale

Financial data should favor preservation over aggressive deduplication.

A false positive in duplicate detection can remove a legitimate expense or income and silently change:

- balances;
- reports;
- budgets;
- financial summaries.

A false negative is less destructive.

If the system preserves two records that later turn out to represent the same transaction, the user can resolve the duplication.

If the system deletes a legitimate transaction automatically, the original financial history may be lost.

Therefore:

> When transaction identity is ambiguous, preserve the data and expose the ambiguity.

## Consequences

### Positive

- Legitimate transactions are not silently discarded.
- The original imported history can be preserved.
- Duplicate-detection algorithms can evolve without requiring lost records to be reconstructed.
- User decisions can potentially be reversed.
- Source identifiers and heuristic fingerprints have clearly different meanings.

### Negative

- The database may temporarily contain suspected duplicates.
- Reports must exclude transactions explicitly marked as ignored.
- The UI needs a duplicate-resolution workflow.
- Additional metadata is required to track duplicate state.

These costs are accepted because preserving financial correctness is more important than automatically removing every possible duplicate.

## Database Implications

Transactions may contain fields such as:

```text
transaction_code
transaction_fingerprint
duplicate_status
ignored_at
```

`transaction_code` may be unique when populated according to the source's guarantees.

`transaction_fingerprint` must not be unique.

A future duplicate-group mechanism may associate multiple transactions that share the same suspected identity.

## Future Considerations

Future import sources such as Open Finance or OFX may provide stronger identifiers.

When a reliable external transaction ID is available, it should take precedence over heuristic duplicate detection.

This ADR should be revisited if a supported Banco Inter format begins providing a stable unique transaction identifier.