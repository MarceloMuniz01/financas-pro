# Transaction Business Rules

This document describes the business rules that govern financial transactions in Finanças Pro.

## 1. Transaction Source

Every transaction must have a known source.

Examples include:

```text
manual_import
open_finance
```

The source describes how the transaction entered the application.

The financial institution that produced the transaction is tracked separately through the related import or source metadata.

## 2. Transaction Identity

A transaction identifier and a transaction fingerprint represent different concepts.

They must not be treated as interchangeable.

### Reliable Transaction Identifier

When a financial institution provides a reliable unique identifier, it may be stored as:

```text
transaction_code
```

For example, Nubank provides the `Identificador` field in its CSV export.

That value can be used as the transaction code.

### Missing Reliable Identifier

Some financial institutions do not provide a reliable unique identifier.

The currently supported Banco Inter CSV format is an example.

In those cases:

```text
transaction_code = null
```

The application must not create an artificial transaction identity from attributes such as:

- date;
- description;
- amount;
- balance;
- counterparty;
- transaction method.

Two legitimate transactions may contain exactly the same values.

## 3. Transaction Fingerprint

When a reliable transaction identifier is not available, the application may generate:

```text
transaction_fingerprint
```

The fingerprint exists to detect transactions that look alike.

It represents:

> These transactions may represent the same financial event.

It does not represent:

> These transactions are definitely the same financial event.

The fingerprint must not have a unique database constraint.

Multiple legitimate transactions may share the same fingerprint.

## 4. Ambiguous Transactions

When multiple transactions have the same or sufficiently similar fingerprints, the application may classify them as possible duplicates.

The transactions must remain stored.

The application must not automatically discard one of them.

Example:

```text
João → Maria: R$ 100
Maria → Pedro: R$ 100
João → Maria: R$ 100
```

Depending on the information exported by the financial institution, the first and third transactions may contain identical:

- dates;
- descriptions;
- amounts;
- transaction methods;
- resulting balances.

They are still two legitimate financial events.

The system must preserve both.

## 5. Duplicate Status

Transactions involved in duplicate detection may have a `duplicate_status`.

Expected states include:

```text
suspected
confirmed_duplicate
confirmed_distinct
```

A `null` value means that the transaction is not currently involved in a duplicate-resolution workflow.

### `suspected`

The system found another transaction that appears sufficiently similar.

No financial data should be discarded.

The user may be asked to resolve the ambiguity.

### `confirmed_duplicate`

The user confirmed that the transaction represents the same financial event as another transaction.

The transaction must remain stored for historical and auditing purposes.

It may be excluded from financial calculations.

### `confirmed_distinct`

The user confirmed that the transactions represent separate financial events.

All confirmed-distinct transactions remain active and participate normally in calculations.

The same duplicate warning should not continue to be shown for an already resolved case.

## 6. Ignored Transactions

A transaction that should not participate in normal financial calculations may use:

```text
ignored_at
```

For example, a transaction confirmed as a duplicate may receive an `ignored_at` timestamp.

The transaction itself must not be deleted merely because it was ignored.

Normal financial queries should exclude ignored transactions when appropriate.

Conceptually:

```text
ignored_at = null
→ active transaction

ignored_at != null
→ preserved but excluded from normal calculations
```

This allows decisions to be reversed in the future.

## 7. Preserve Original Financial Data

Imported transaction data should be preserved whenever reasonably possible.

A later user decision represents an interpretation of the imported data, not a replacement for the original data.

For example, confirming a duplicate should not destroy the imported transaction.

Preserving the original records makes future features possible, including:

- restoring the original financial history;
- reversing duplicate decisions;
- auditing previous changes;
- reprocessing transactions using improved rules;
- comparing parsed data with the original import.

## 8. Contacts

A transaction may belong to a contact.

Contact identity and transaction identity are independent concepts.

Two transactions associated with the same contact are not necessarily duplicates.

For example:

```text
João → Maria: R$ 100
João → Maria: R$ 100
```

may represent two legitimate transfers.

Contact normalization must therefore never be used by itself to determine transaction duplication.

## 9. Categories

A transaction may belong to a category.

Categories may be:

- automatically suggested during import;
- inherited from contact defaults;
- manually changed by the user.

Changing a transaction category must not change the transaction's identity.

Category information must not be part of a reliable transaction identifier.

## 10. Transaction Amount

The sign of the amount determines the financial direction of the transaction.

Conceptually:

```text
amount < 0
→ expense

amount >= 0
→ income
```

The application may expose this value as a computed transaction type rather than storing an independent `type` column.

The amount remains the authoritative value.

## 11. Duplicate Groups

A future duplicate-resolution system may group several ambiguous transactions together.

For example:

```text
Duplicate Group #12

├── Transaction 101
├── Transaction 145
└── Transaction 203
```

A group is preferable to linking one transaction directly to another because more than two transactions may appear identical.

A future implementation may therefore introduce:

```text
transaction_duplicate_groups
```

and:

```text
transactions.duplicate_group_id
```

The group represents the ambiguity relationship.

It does not prove that the transactions are duplicates.

## 12. Financial Calculations

Normal financial calculations should generally use active transactions only.

Transactions explicitly ignored by the user should not affect:

- balances;
- budgets;
- reports;
- spending totals;
- income totals;
- category totals.

However, ignored transactions must remain available for historical inspection and restoration.

## 13. Safety Principle

Financial correctness takes priority over aggressive automatic deduplication.

When the application cannot determine with confidence whether two transactions represent the same financial event, it must:

**preserve both transactions and expose the ambiguity to the user.**

A visible possible duplicate can be resolved later.

A silently deleted legitimate transaction may permanently corrupt the user's financial history.