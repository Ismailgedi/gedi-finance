# Gedi Wholesale Step 6 - COGS Fix 2

This patch fixes purchase inventory costing.

The PurchaseService now passes each purchase item's actual `unit_cost`
into InventoryService::record(), so inventory movements store the real
purchase cost instead of falling back to the product default cost.

No migration is required.

After replacing the file, run `php artisan optimize:clear`.

The existing test purchase PUR-20260918-0002 was already posted before
this fix, so its inventory movement must be repaired separately before
using weighted-average-cost calculations.
