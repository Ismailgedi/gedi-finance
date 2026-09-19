# Gedi Wholesale - Step 5: Business Posting Engine

This step connects the new wholesale documents to the existing financial ledger and inventory movement ledger.

## Posted sale

A sale:
- creates sale/sale items
- records inventory out movements
- records a `cash_sale` when fully paid
- records a `credit_sale` when unpaid
- records a separate `customer_payment` for a partial initial payment

## Posted purchase

A purchase:
- creates purchase/purchase items
- records inventory in movements
- records a `purchase` transaction that increases supplier payable
- records a `supplier_payment` when any amount is paid

## Later payments

Endpoints are included to record additional customer and supplier payments against a specific sale or purchase. They cannot exceed the outstanding document balance.

## Credit limits

Customer and supplier credit limits are checked before a new outstanding balance is posted when a limit is configured.

## Inventory safety

Sale inventory is checked against current stock before an outbound movement is recorded.

All multi-step posting operations use database transactions so a failure rolls the operation back.

## Important

COGS / weighted-average costing is NOT posted yet. The inventory movement ledger is quantity-focused at this stage. Cost layers and profit calculation will be implemented after the purchase/sale flow is verified.

## API routes added

- GET/POST `/api/sales`
- GET `/api/sales/{sale}`
- POST `/api/sales/{sale}/payments`
- GET/POST `/api/purchases`
- GET `/api/purchases/{purchase}`
- POST `/api/purchases/{purchase}/payments`

## Verify before use

From `backend`:

```powershell
php artisan migrate
php artisan migrate:status
php artisan route:list --path=api/sales
php artisan route:list --path=api/purchases
php artisan route:list --path=api/transactions
```

Do not use real business data for the first posting test. We should first create one test product/unit, one test supplier/customer, then test a small purchase and sale and verify inventory, account, supplier and customer balances.
