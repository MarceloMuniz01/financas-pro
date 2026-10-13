# Import Business Rules

This document describes the business rules that govern financial statement imports.

## 1. Import Sources

The application may receive financial data from different sources.

Currently supported:

- CSV
- Manual imports

Planned sources may include:

- OFX
- Open Finance

The import source is stored separately from the financial institution that generated the data.

For example:

- `source_type = manual_import`
- `bank = nubank`

## 2. Bank Detection

The application must identify the financial institution before parsing the imported file.

Each supported bank has its own expected file structure.

Examples:

### Nubank

Expected CSV header:

```text
Data,Valor,Identificador,Descrição
```

### Banco Inter

Expected CSV header:

```text
Data Lançamento;Histórico;Descrição;Valor;Saldo
```

If the application cannot identify the bank, the import must fail.

An unidentified file must never be processed using an arbitrary parser.

## 3. Bank-Specific Parsers

Each financial institution may represent transactions differently.

Bank-specific parsers are responsible for converting the original data into the application's normalized transaction format.

Parsers may extract:

- transaction date;
- description;
- amount;
- transaction method;
- counterparty name;
- counterparty document;
- counterparty type;
- bank-provided transaction identifier, when available.

Parsing must not silently discard valid financial transactions.

## 4. Transaction Identity

Not every bank provides a reliable unique transaction identifier.

The application must distinguish between:

- a reliable transaction identifier;
- a transaction fingerprint.

These concepts are not interchangeable.

### Nubank

Nubank provides an `Identificador` field.

This value may be stored as:

```text
transaction_code
```

It represents an identifier supplied by the source institution.

### Banco Inter

The currently supported Inter CSV format does not provide a reliable unique transaction identifier.

Therefore:

```text
transaction_code = null
```

for Inter transactions unless a reliable source identifier becomes available in the future.

The application must not invent an absolute transaction identity from fields such as:

- date;
- description;
- amount;
- balance;
- counterparty.

Two legitimate transactions may have exactly the same values.

## 5. Transaction Fingerprints

When no reliable transaction identifier exists, the application may generate a:

```text
transaction_fingerprint
```

The fingerprint is used to detect transactions that look similar.

A fingerprint means:

> These transactions may represent the same financial event.

It does **not** mean:

> These transactions are definitely the same financial event.

A fingerprint must therefore not have a unique database constraint.

Multiple transactions may legitimately share the same fingerprint.

## 6. Possible Duplicate Transactions

Possible duplicates must not be automatically deleted or ignored.

When multiple transactions have the same fingerprint, the application may mark them as suspected duplicates.

Possible states include:

```text
suspected
confirmed_duplicate
confirmed_distinct
```

The user must be able to resolve ambiguous cases.

Example:

```text
13/10/2026
Pix received from João
R$ 100.00
```

If the same information appears twice, the system cannot safely assume that one transaction is duplicated.

Both records must be preserved until the ambiguity is resolved.

## 7. Preserving Imported Data

Imported financial data should be preserved whenever possible.

User decisions must not destroy the original imported transaction.

If a transaction is later confirmed as a duplicate, it may be excluded from calculations using a field such as:

```text
ignored_at
```

The transaction itself should remain stored.

This allows the application to:

- audit previous decisions;
- restore transactions;
- rebuild the original imported history;
- change duplicate-detection logic in the future.

## 8. Empty Imports

A recognized file that produces zero valid transactions must fail.

Expected import status:

```text
failed
```

Expected error:

```text
Nenhuma transação foi encontrada no arquivo.
```

A failed empty import must not create contacts or transactions.

## 9. Import Status

An import may use statuses such as:

```text
pending
processing
done
failed
```

A successful import must only be marked as `done` after the processing pipeline finishes successfully.

When processing fails:

- the import must be marked as `failed`;
- `processed_at` must be recorded;
- an error message should be stored when available.

## 10. Contacts

Imported transactions may create or reuse contacts.

Contact detection and transaction duplicate detection are separate concerns.

Two transactions associated with the same contact are not necessarily duplicates.

Contact normalization must therefore never be used as sufficient evidence to discard a transaction.

## 11. Safety Principle

When the application cannot determine whether two financial transactions are identical, it must prefer preserving both records.

A visible possible duplicate is safer than silently deleting a legitimate financial transaction.