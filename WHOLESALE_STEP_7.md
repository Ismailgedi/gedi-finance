# Gedi Wholesale Step 7 - Business Reporting

Adds backend reporting for sales, purchases, inventory, customer receivables, supplier payables, gross profit, expenses, and net profit.

No database migration is required.

## Added
- BusinessReportService
- BusinessReportController
- Business summary endpoint
- Sales and purchases report endpoints
- Inventory report endpoint
- Customer receivables endpoint
- Supplier payables endpoint

## Date range
Summary, sales, and purchases accept optional `from` and `to` query parameters in `YYYY-MM-DD` format. Defaults to the current month.

Example: `/api/reports/business-summary?from=2026-09-01&to=2026-09-30`

The route block is provided separately because your current `routes/api.php` already contains Step 5 routes and should not be blindly overwritten.
