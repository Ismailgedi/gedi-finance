# Step 6 COGS Fix 3

Adds `unit_cost` and `total_cost` to InventoryMovement mass-assignment and casts.

No migration is required.

After replacing `backend/app/Models/InventoryMovement.php`, run:
`php artisan optimize:clear`

Then repair the existing purchase movement for `reference_id = 2`.
