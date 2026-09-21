<?php

namespace App\Http\Controllers;

use App\Exports\BusinessReportExport;
use App\Models\Account;
use App\Models\Person;
use App\Models\Supplier;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Generates real Excel (.xlsx) business reports/statements from the same
 * filtered data the screen shows - a genuine Office Open XML workbook
 * (built in memory by PhpSpreadsheet via maatwebsite/excel), never a CSV
 * renamed with an .xlsx extension. Every endpoint here sits behind the
 * same 'web','auth:web','password.changed' middleware group as the rest
 * of the API (see routes/api.php), so an export can only be requested by
 * someone already authorized to see the underlying records through the
 * normal app.
 */
class ReportExcelController extends Controller
{
    public function __construct(private readonly ReportExportService $reports) {}

    public function transactions(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'person_id' => ['nullable', 'integer', Rule::exists('people', 'id')],
            'type' => ['nullable', 'string', 'max:50'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'status' => ['nullable', Rule::in(['posted', 'voided'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $report = $this->reports->transactionsReport($validated);

        $filterSummary = [
            'Person' => ! empty($validated['person_id']) ? Person::find($validated['person_id'])?->name : 'All',
            'Type' => ! empty($validated['type']) ? str_replace('_', ' ', ucfirst($validated['type'])) : 'All',
            'Account' => ! empty($validated['account_id']) ? Account::find($validated['account_id'])?->name : 'All',
            'Status' => ! empty($validated['status']) ? ucfirst($validated['status']) : 'All',
            'Date' => $this->dateRangeLabel($validated['from'] ?? null, $validated['to'] ?? null),
        ];

        if (! empty($validated['search'])) {
            $filterSummary['Search'] = $validated['search'];
        }

        return $this->render($report, $filterSummary, 'transactions-report');
    }

    public function sales(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('people', 'id')],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'unpaid'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $report = $this->reports->salesReport($validated);

        $filterSummary = [
            'Customer' => ! empty($validated['customer_id']) ? Person::find($validated['customer_id'])?->name : 'All',
            'Payment Status' => ! empty($validated['payment_status']) ? ucfirst($validated['payment_status']) : 'All',
            'Date' => $this->dateRangeLabel($validated['from'] ?? null, $validated['to'] ?? null),
        ];

        return $this->render($report, $filterSummary, 'sales-report');
    }

    public function purchases(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'unpaid'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $report = $this->reports->purchasesReport($validated);

        $filterSummary = [
            'Supplier' => ! empty($validated['supplier_id']) ? Supplier::find($validated['supplier_id'])?->name : 'All',
            'Payment Status' => ! empty($validated['payment_status']) ? ucfirst($validated['payment_status']) : 'All',
            'Date' => $this->dateRangeLabel($validated['from'] ?? null, $validated['to'] ?? null),
        ];

        return $this->render($report, $filterSummary, 'purchases-report');
    }

    private function dateRangeLabel(?string $from, ?string $to): string
    {
        if (! $from && ! $to) {
            return 'All time';
        }

        $fromLabel = $from ? date('d M Y', strtotime($from)) : 'Beginning';
        $toLabel = $to ? date('d M Y', strtotime($to)) : 'Today';

        return "{$fromLabel} - {$toLabel}";
    }

    private function render(array $report, array $filterSummary, string $slug): BinaryFileResponse
    {
        $export = new BusinessReportExport(
            title: $report['title'],
            subtitle: $report['subtitle'],
            generatedAt: now()->format('d M Y, H:i'),
            filterSummary: $filterSummary,
            columns: $report['columns'],
            align: $report['align'],
            rows: $report['rows'],
            totals: $report['totals'],
        );

        return Excel::download($export, $this->filenameFor($report, $slug));
    }

    /**
     * "customer-statement-ahmed.xlsx" / "supplier-statement-test-supplier.xlsx"
     * when a person/supplier filter is active, otherwise the plain,
     * dated report name - matching exactly what the on-screen filters
     * produced, so the file itself tells you what's inside it.
     */
    private function filenameFor(array $report, string $slug): string
    {
        if (! empty($report['subtitle']) && preg_match('/^(Customer|Supplier): (.+)$/', $report['subtitle'], $matches)) {
            $prefix = $matches[1] === 'Customer' ? 'customer-statement' : 'supplier-statement';

            return $prefix.'-'.Str::slug($matches[2]).'.xlsx';
        }

        return $slug.'-'.now()->format('Y-m-d').'.xlsx';
    }
}
