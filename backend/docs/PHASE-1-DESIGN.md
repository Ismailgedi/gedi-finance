# Gedi Finance v2 - Phase 1 Design

## Source of truth

`transactions` is the financial source of truth. Customer and account balances must be derived from posted transactions.

## Person balance sign convention

- Positive `person_balance_effect`: the person owes the business more / the business's receivable increases.
- Negative `person_balance_effect`: the person owes the business less / the business's receivable decreases.

Examples:

- Credit sale $450: `+450`
- Customer payment $300: `-300`
- Loan given $1,000: `+1000`
- Repayment $300: `-300`

## Account balance sign convention

- Positive `account_balance_effect`: money increases in the account.
- Negative `account_balance_effect`: money decreases from the account.

Examples:

- Income $500 to Cash: `+500`
- Expense $200 from Cash: `-200`
- Customer payment $300 to Cash: `+300`
- Loan given $1,000 from Cash: `-1000`

## Credit workflow

Example:

1. Ahmed gets $1,000 of supplies on credit -> `credit_sale`, person effect `+1000`.
2. Ahmed pays $300 in Cash -> `customer_payment`, person effect `-300`, Cash effect `+300`.
3. Ahmed gets another $450 of supplies on credit -> `credit_sale`, person effect `+450`.
4. Balance = `$1,150`.

## Descriptions

`description` is mandatory for every transaction. The UI may provide a smart default, but the stored transaction must contain a useful explanation of what happened.
