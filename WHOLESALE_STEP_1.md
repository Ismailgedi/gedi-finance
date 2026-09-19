# Gedi Wholesale - Step 1: Product & Unit Foundation

This update adds only the database/model foundation for:

- Product categories
- Units of measure
- Products
- Product-specific unit conversions

## Design

Inventory quantities will ultimately be stored in each product's base unit.

Example:

- Product: Beans
- Base unit: KG
- Sack conversion: 50 KG
- Ton conversion: 1000 KG

A product can define its own conversion for a unit, so a "Sack" does not have to mean the same weight for every product.

## Price behavior

Products have default prices for convenience:

- default cost price
- default selling price
- default wholesale price

These are defaults only. Future purchase/sale records must retain their transaction-time prices so market price changes do not rewrite history.

## Important

No existing transaction, sales, customer, supplier, or inventory tables were changed in this step.

## Run after reviewing

From backend:

```powershell
php artisan migrate
```

Then verify:

```powershell
php artisan migrate:status
```

Do not seed production data yet. The next phase will add inventory movement architecture after this foundation is verified.
