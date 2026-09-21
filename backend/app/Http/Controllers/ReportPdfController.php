<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Person;
use App\Models\Supplier;
use App\Services\ReportPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Generates real, printable PDF business reports/statements from the same
 * filtered data the screen shows - never a screenshot of the DOM. Every
 * endpoint here sits behind the same 'web','auth:web','password.changed'
 * middleware group as the rest of the API (see routes/api.php), so a PDF
 * can only be exported by someone already authorized to see the underlying
 * records through the normal app - there is no separate, looser
 * authorization path for exports.
 */
class ReportPdfController extends Controller
{
    public function __construct(private readonly ReportPdfService $reports) {}

    public function transactions(Request $request): Response
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

        return $this->render($report, $filterSummary, 'transaction-history');
    }

    public function sales(Request $request): Response
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

    public function purchases(Request $request): Response
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

        return $this->render($report, $filterSummary, 'purchase-report');
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

    private function render(array $report, array $filterSummary, string $slug): Response
    {
        $pdf = Pdf::loadView('reports.pdf', [
            'title' => $report['title'],
            'subtitle' => $report['subtitle'],
            'generatedAt' => now()->format('d M Y, H:i'),
            'filterSummary' => $filterSummary,
            'columns' => $report['columns'],
            'align' => $report['align'],
            'rows' => $report['rows'],
            'totals' => $report['totals'],
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions(['isPhpEnabled' => true, 'isRemoteEnabled' => false]);

        $filename = $slug.'-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}
