<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FinancialYearClose;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a closed financial year into a stable historical record. Before
 * this, BusinessCapitalService::position() always recomputed opening/
 * closing equity live from the full transaction history, which meant a
 * backdated entry or a voided old sale could silently change a year that
 * had already been reported. Closing a year snapshots that computation
 * once and freezes it here; BusinessCapitalService then reads the snapshot
 * instead of recomputing for that year, and this class blocks new postings/
 * voids dated inside a closed period so the frozen numbers can't drift out
 * of sync with the live ledger underneath them.
 *
 * A financial year is always a calendar year (Jan 1 - Dec 31), matching
 * every example in the spec this was built against.
 */
class FinancialYearCloseService
{
    public function __construct(
        private readonly BusinessCapitalService $capital,
    ) {
    }

    public function close(int $year, ?int $userId): FinancialYearClose
    {
        $existing = FinancialYearClose::query()->where('financial_year', $year)->first();

        if ($existing && $existing->status === 'closed') {
            throw ValidationException::withMessages([
                'financial_year' => "Financial year {$year} is already closed.",
            ]);
        }

        $periodStart = "{$year}-01-01";
        $periodEnd = "{$year}-12-31";

        if (now()->toDateString() <= $periodEnd) {
            throw ValidationException::withMessages([
                'financial_year' => "Financial year {$year} has not yet ended - it ends {$periodEnd}. "
                    . 'It can only be closed once that date has passed.',
            ]);
        }

        $this->assertPriorYearsClosed($year);

        // Computed fresh, live, right now - this is the one and only moment
        // this year's numbers are ever derived from raw transactions. Once
        // stored below, BusinessCapitalService::position() will read this
        // row instead of recomputing for this exact period.
        $position = $this->capital->position($periodStart, $periodEnd);

        $oldValues = $existing?->only([
            'status', 'closing_equity', 'closed_at', 'reopened_at',
        ]);

        $record = FinancialYearClose::updateOrCreate(
            ['financial_year' => $year],
            [
                'period_start_date' => $periodStart,
                'period_end_date' => $periodEnd,
                'opening_equity' => $position['equity']['opening_equity'],
                'profit_loss' => $position['equity']['profit_or_loss'],
                'other_income' => $position['equity']['other_income'],
                'owner_contributions' => $position['equity']['owner_contributions'],
                'owner_withdrawals' => $position['equity']['owner_withdrawals'],
                'closing_equity' => $position['equity']['closing_equity'],
                'assets' => $position['assets'],
                'liabilities' => $position['liabilities'],
                'cash_accounts' => $position['cash_accounts'],
                'inventory_breakdown' => $position['inventory_breakdown'],
                'status' => 'closed',
                'created_by' => $userId,
                'closed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ],
        );

        AuditLog::record('financial_year_closed', $record, $oldValues, [
            'status' => 'closed',
            'closing_equity' => $record->closing_equity,
            'closed_at' => $record->closed_at?->toIso8601String(),
        ]);

        return $record;
    }

    /**
     * Financial years must close in order: closing year N would freeze a
     * snapshot that assumes every earlier year is already settled, so if an
     * earlier year is still open it could still change after N is closed,
     * silently invalidating N's frozen numbers. "Earlier" only means years
     * the business actually has posted activity in - determined from the
     * earliest posted transaction_date, the same ledger every other figure
     * in this class already reads from, so no separate "business start
     * date" concept has to be invented. A business with no transactions
     * yet, or whose first transaction is in $year or later, has nothing
     * earlier to require closing.
     */
    private function assertPriorYearsClosed(int $year): void
    {
        $earliestDate = DB::table('transactions')->where('status', 'posted')->min('transaction_date');

        if (!$earliestDate) {
            return;
        }

        $earliestYear = (int) Carbon::parse($earliestDate)->format('Y');

        for ($priorYear = $earliestYear; $priorYear < $year; $priorYear++) {
            $priorClose = FinancialYearClose::query()->where('financial_year', $priorYear)->first();

            if (!$priorClose || $priorClose->status !== 'closed') {
                throw ValidationException::withMessages([
                    'financial_year' => "Financial year {$priorYear} must be closed before {$year} can be closed.",
                ]);
            }
        }
    }

    public function reopen(int $year, ?int $userId): FinancialYearClose
    {
        $record = FinancialYearClose::query()->where('financial_year', $year)->first();

        if (!$record || $record->status !== 'closed') {
            throw ValidationException::withMessages([
                'financial_year' => "Financial year {$year} is not currently closed.",
            ]);
        }

        $latestClosedYear = FinancialYearClose::query()
            ->where('status', 'closed')
            ->max('financial_year');

        if ((int) $latestClosedYear !== $year) {
            throw ValidationException::withMessages([
                'financial_year' => "Only the most recent closed financial year ({$latestClosedYear}) may be reopened. "
                    . "Reopen later years first.",
            ]);
        }

        $oldValues = $record->only(['status', 'reopened_at']);

        $record->update([
            'status' => 'reopened',
            'reopened_by' => $userId,
            'reopened_at' => now(),
        ]);

        AuditLog::record('financial_year_reopened', $record, $oldValues, [
            'status' => 'reopened',
            'reopened_at' => $record->reopened_at?->toIso8601String(),
        ]);

        return $record;
    }

    /**
     * Throws when $date falls inside a currently closed financial year -
     * called before any new transaction is posted (TransactionService::
     * create(), which Sales/Purchases/Loans/Owner Capital all funnel
     * through) and before any transaction is voided (TransactionService::
     * voidTransaction(), which Sale/Purchase void both funnel through).
     */
    public function assertDatePostable(string|CarbonInterface $date): void
    {
        $normalized = $date instanceof CarbonInterface ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');

        $closed = FinancialYearClose::query()
            ->where('status', 'closed')
            ->whereDate('period_start_date', '<=', $normalized)
            ->whereDate('period_end_date', '>=', $normalized)
            ->first();

        if ($closed) {
            throw ValidationException::withMessages([
                'financial_year' => "Financial year {$closed->financial_year} is closed. Reopen the financial year "
                    . 'before posting or voiding a transaction dated within the closed period.',
            ]);
        }
    }

    /**
     * The closure metadata BusinessCapitalService attaches to a position()
     * response - null when $year isn't closed.
     */
    public function closureFor(int $year): ?array
    {
        $record = FinancialYearClose::query()->where('financial_year', $year)->first();

        if (!$record || $record->status !== 'closed') {
            return null;
        }

        $latestClosedYear = (int) FinancialYearClose::query()->where('status', 'closed')->max('financial_year');

        return [
            'financial_year' => $record->financial_year,
            'status' => $record->status,
            'closed_by' => $record->closedBy?->name,
            'closed_at' => $record->closed_at?->toIso8601String(),
            'reopened_by' => $record->reopenedBy?->name,
            'reopened_at' => $record->reopened_at?->toIso8601String(),
            'is_latest_closed_year' => $latestClosedYear === $year,
        ];
    }
}
