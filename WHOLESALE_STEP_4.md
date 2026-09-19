# Gedi Wholesale - Step 4: Customers & Sales Documents

This step evolves the existing `people` table into customer master data and adds sales document structures.

## Existing People table is preserved

Customer-specific fields added to `people`:

- `customer_code`
- `credit_limit`
- `payment_terms_days`
- `is_customer`

This avoids duplicating a person as both a generic person and a customer.

## New tables

- `sales`
- `sale_items`

A sale stores invoice-level totals and payment state. Each sale item stores the product, selected unit, quantity, base quantity, and historical selling price.

## Important accounting design

This step does NOT yet post:

- inventory movements
- customer receivable transactions
- cash/bank/EVC/eDahab/JEEB movements
- customer payments

Those will be implemented in the service layer once the payment workflow is built.

The `amount_paid` and `balance_due` fields are document snapshots for the sale UI/workflow. The financial ledger will remain the authoritative source once posting is connected.

## Run

From `backend`:

```powershell
php artisan migrate
php artisan migrate:status
```
