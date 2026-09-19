# Gedi Wholesale - Step 3: Suppliers & Purchases

Adds the master data and document structure for goods entering the business.

## Tables

- `suppliers`
- `purchases`
- `purchase_items`

## Purchase behavior

A purchase stores:

- supplier
- purchase number
- purchase and due dates
- subtotal, discount and total
- amount paid and balance due
- payment status
- posted/cancelled status
- creator

Each item stores:

- product
- selected product unit
- entered quantity
- base-unit quantity
- historical unit cost
- historical line total

The historical unit cost is intentionally stored on the purchase item. Changing a product's default cost later will not rewrite old purchases.

## Important

This step does NOT yet post purchases to inventory or accounts.

That integration belongs in the purchase service/application layer after the purchase and payment workflows are finalized.

## Run

From `backend`:

```powershell
php artisan migrate
php artisan migrate:status
```
