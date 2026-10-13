# Regras de negócio — Transações

## Identidade da transação

Nem toda instituição fornece um identificador único confiável.

### Nubank

O campo `Identificador` fornecido pelo banco é usado como
`transaction_code`.

### Banco Inter

O CSV não fornece um identificador único confiável.

Por isso:

- `transaction_code` deve permanecer `null`;
- o sistema gera `transaction_fingerprint`;
- o fingerprint indica apenas similaridade, não identidade;
- duas transações com o mesmo fingerprint podem ser transações legítimas;
- transações suspeitas não devem ser descartadas automaticamente.

## Possíveis duplicidades

Quando duas ou mais transações parecem iguais:

- todas devem continuar armazenadas em `transactions`;
- devem ser agrupadas como possíveis duplicidades;
- o usuário decide se são transações diferentes ou duplicadas;
- nenhuma informação importada deve ser destruída.

Estados previstos:

- `suspected`
- `confirmed_duplicate`
- `confirmed_distinct`

## Transações ignoradas

Uma transação confirmada como duplicada não deve ser apagada.

Ela pode ser marcada com `ignored_at`.

Transações com `ignored_at != null` não participam dos cálculos
financeiros normais.

Isso permite restaurar o histórico original futuramente.