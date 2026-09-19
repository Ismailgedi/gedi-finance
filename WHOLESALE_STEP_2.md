# Gedi Wholesale - Step 2: Inventory Movement Ledger

This step adds the auditable inventory movement layer.

## Table

`inventory_movements`

Every stock change is recorded as a movement.

Examples:

- opening_stock: +500 KG
- purchase: +1,000 KG
- sale: -250 KG
- customer_return: +50 KG
- damaged: -20 KG
- adjustment: -5 KG

## Quantity model

`quantity` stores the quantity in the selected unit.

`base_quantity` stores the signed quantity in the product's base unit.

Example:

10 sacks × 50 KG = `quantity 10`, `base_quantity +500`.

## References

`reference_type` and `reference_id` are intentionally generic at this stage. They will allow inventory movements to be tied to sales, purchases, returns, or adjustments without forcing those modules into the database before their designs are finalized.

## Important

This step does not change existing transaction logic and does not yet connect sales or purchases to inventory.

## Run

From `backend`:

```powershell
php artisan migrate
php artisan migrate:status
```

After verification, the next phase can build the supplier and purchase architecture.
