<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\OpeningBalance;
use App\Models\OpeningBalanceItem;
use App\Models\Person;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one-time Opening Balance / Initial Business Position: how a business
 * already running before it starts using Gedi Finance enters its starting
 * assets/liabilities/equity without fabricating historical sales, purchases,
 * or loans. Every non-cash, non-inventory piece (customer receivables,
 * supplier payables, loans given/received, per-owner opening capital) is
 * posted as its own dedicated opening_balance_* Transaction via the
 * existing, unmodified TransactionService::create() - never credit_sale/
 * purchase/loan_given/loan_received/owner_contribution, so none of it can
 * ever be picked up as current-period revenue, expense, or contribution
 * (see TransactionType's own doc comment). Opening inventory is posted via
 * the existing, unmodified InventoryService::record(), establishing the
 * first entry in that product's weighted-average cost. Cash/bank/mobile
 * money never gets a new mechanism at all - accounts.opening_balance
 * already exists and already feeds the asset side of every balance
 * calculation; this class only has to fold it into the equity side once,
 * via the single validated `opening_equity` figure it stores.
 *
 * DRAFT -> LOCKED is a real state machine: a draft is pure data (nothing
 * posted to the ledger yet), so it can be edited freely with zero
 * accounting side effects. Locking is the one atomic moment every item is
 * actually posted, after validating reconciliation and the date boundary -
 * if anything fails, the whole lock is refused and nothing is posted
 * (wrapped in one DB transaction). Reopening a locked record voids every
 * transaction it posted and reverses every inventory movement it created
 * (compensating entries, never deletion - the same pattern SaleService::
 * void()/SaleReturnService already use), then clears its items so a
 * correction can be entered fresh and relocked.
 */
class OpeningBalanceService
{
    private const CATEGORIES = ['receivable', 'other_receivable', 'payable', 'loan_given', 'loan_received', 'capital', 'inventory'];

    public function __construct(
        private readonly TransactionService $transactions,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * The single Opening Balance record, if one exists - there is only
     * ever one (see saveDraft()), so this is always the whole answer to
     * "what is the current Opening Balance."
     */
    public function current(): ?OpeningBalance
    {
        return OpeningBalance::query()->first();
    }

    /**
     * Creates the one-and-only draft, or fully replaces the items on the
     * existing draft/reopened one - never a second, independent record.
     * Nothing is posted to the ledger here; that only happens in lock().
     */
    public function saveDraft(array $data, ?int $userId): OpeningBalance
    {
        return DB::transaction(function () use ($data, $userId) {
            $record = OpeningBalance::query()->first();

            if ($record && $record->status === 'locked') {
                throw ValidationException::withMessages([
                    'opening_balance' => 'The Opening Balance is locked. A Super Admin must reopen it before it can be changed.',
                ]);
            }

            $record ??= new OpeningBalance();
            $record->fill([
                'as_of_date' => $data['as_of_date'],
                'opening_equity' => $data['opening_equity'],
                'status' => 'draft',
                'created_by' => $record->created_by ?? $userId,
            ]);
            $record->save();

            // A draft is always fully replaced, not merged - nothing has
            // been posted yet, so there is nothing to reverse first.
            $record->items()->delete();

            $items = $data['items'] ?? [];
            $this->createItems($record, $items);

            $this->applyAccountOpeningBalances($data['accounts'] ?? []);

            AuditLog::record('opening_balance_created', $record, null, [
                'opening_balance_id' => $record->id,
                'as_of_date' => (string) $data['as_of_date'],
                'opening_equity' => (string) $data['opening_equity'],
                'item_count' => count($items),
                'created_by' => $userId,
            ]);

            return $record->fresh(['items']);
        });
    }

    /**
     * Explicit, intentional updates only - an account not mentioned here is
     * never touched, so an existing nonzero opening_balance is never
     * silently overwritten by this workflow.
     */
    private function applyAccountOpeningBalances(array $accounts): void
    {
        foreach ($accounts as $index => $row) {
            $account = Account::query()->find($row['account_id'] ?? null);

            if (!$account) {
                throw ValidationException::withMessages([
                    "accounts.$index.account_id" => 'The selected account does not exist.',
                ]);
            }

            $account->update(['opening_balance' => (float) $row['opening_balance']]);
        }
    }

    private function createItems(OpeningBalance $record, array $items): void
    {
        foreach ($items as $index => $item) {
            $category = $item['category'] ?? null;

            if (!in_array($category, self::CATEGORIES, true)) {
                throw ValidationException::withMessages([
                    "items.$index.category" => 'This is not a valid opening balance category.',
                ]);
            }

            $personId = null;
            $supplierId = null;
            $productId = null;
            $productUnitId = null;
            $quantity = null;
            $unitCost = null;
            $amount = 0.0;

            if ($category === 'inventory') {
                $product = Product::query()->where('id', $item['product_id'] ?? null)->where('is_active', true)->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        "items.$index.product_id" => 'The selected product does not exist or is inactive.',
                    ]);
                }

                $quantity = (float) ($item['quantity'] ?? 0);
                $unitCost = (float) ($item['unit_cost'] ?? 0);

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Opening quantity must be greater than zero.',
                    ]);
                }

                if ($unitCost < 0) {
                    throw ValidationException::withMessages([
                        "items.$index.unit_cost" => 'Opening unit cost cannot be negative.',
                    ]);
                }

                $productId = $product->id;
                $productUnitId = $item['product_unit_id'] ?? null;
                $amount = round($quantity * $unitCost, 2);
            } elseif ($category === 'payable') {
                $supplier = Supplier::query()->where('id', $item['supplier_id'] ?? null)->where('is_active', true)->first();

                if (!$supplier) {
                    throw ValidationException::withMessages([
                        "items.$index.supplier_id" => 'The selected supplier does not exist or is inactive.',
                    ]);
                }

                $supplierId = $supplier->id;
                $amount = (float) ($item['amount'] ?? 0);
            } else {
                $person = Person::query()->where('id', $item['person_id'] ?? null)->where('is_active', true)->first();

                if (!$person) {
                    throw ValidationException::withMessages([
                        "items.$index.person_id" => 'The selected person does not exist or is inactive.',
                    ]);
                }

                if ($category === 'capital' && !$person->is_owner) {
                    throw ValidationException::withMessages([
                        "items.$index.person_id" => 'The selected person is not marked as a business owner.',
                    ]);
                }

                $personId = $person->id;
                $amount = (float) ($item['amount'] ?? 0);
            }

            if ($category !== 'inventory' && $amount <= 0) {
                throw ValidationException::withMessages([
                    "items.$index.amount" => 'Amount must be greater than zero.',
                ]);
            }

            $record->items()->create([
                'category' => $category,
                'person_id' => $personId,
                'supplier_id' => $supplierId,
                'product_id' => $productId,
                'product_unit_id' => $productUnitId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'amount' => $amount,
            ]);
        }
    }

    /**
     * Validates reconciliation and the date boundary, then posts every
     * item to the ledger and locks the record. Refuses (with nothing
     * posted at all - the whole thing is one DB transaction) if the
     * entered opening_equity doesn't match Assets - Liabilities, if any
     * owner capital allocations don't sum to it, or if a posted
     * transaction already exists on or before the requested date.
     */
    public function lock(?int $userId): OpeningBalance
    {
        return DB::transaction(function () use ($userId) {
            $record = OpeningBalance::query()->lockForUpdate()->first();

            if (!$record) {
                throw ValidationException::withMessages([
                    'opening_balance' => 'No Opening Balance draft exists to lock.',
                ]);
            }

            if ($record->status === 'locked') {
                throw ValidationException::withMessages([
                    'opening_balance' => 'The Opening Balance is already locked.',
                ]);
            }

            $record->load('items.product', 'items.productUnit');
            $asOfDate = $record->as_of_date->toDateString();

            // #13: refuse rather than silently letting a backdated entry
            // land underneath the opening position.
            $predatesExists = DB::table('transactions')
                ->where('status', 'posted')
                ->whereDate('transaction_date', '<=', $asOfDate)
                ->exists();

            if ($predatesExists) {
                throw ValidationException::withMessages([
                    'as_of_date' => "A posted transaction already exists on or before {$asOfDate}. The Opening "
                        . 'Balance date must be earlier than all existing transaction history.',
                ]);
            }

            $sums = $record->items->groupBy('category')->map(fn ($group) => (float) $group->sum('amount'));

            $accountsOpeningTotal = (float) Account::query()->where('is_active', true)->sum('opening_balance');
            $receivable = $sums->get('receivable', 0.0);
            $otherReceivable = $sums->get('other_receivable', 0.0);
            $loanGiven = $sums->get('loan_given', 0.0);
            $inventoryValue = $sums->get('inventory', 0.0);
            $payable = $sums->get('payable', 0.0);
            $loanReceived = $sums->get('loan_received', 0.0);
            $capital = $sums->get('capital', 0.0);

            $openingAssets = round($accountsOpeningTotal + $receivable + $otherReceivable + $loanGiven + $inventoryValue, 2);
            $openingLiabilities = round($payable + $loanReceived, 2);
            $expectedEquity = round($openingAssets - $openingLiabilities, 2);

            if (abs($expectedEquity - (float) $record->opening_equity) > 0.01) {
                throw ValidationException::withMessages([
                    'opening_equity' => 'Opening Equity (' . number_format((float) $record->opening_equity, 2)
                        . ') does not match Assets - Liabilities (' . number_format($expectedEquity, 2)
                        . '). Correct the figures before locking.',
                ]);
            }

            if ($record->items->where('category', 'capital')->isNotEmpty() && abs(round($capital, 2) - $expectedEquity) > 0.01) {
                throw ValidationException::withMessages([
                    'opening_equity' => 'Owner opening capital allocations (' . number_format(round($capital, 2), 2)
                        . ') must sum to exactly Opening Equity (' . number_format($expectedEquity, 2) . ').',
                ]);
            }

            foreach ($record->items as $item) {
                if ($item->category === 'inventory') {
                    // An opening balance must be dated on as_of_date (which
                    // is in the past relative to "now"), or every Business
                    // Position/report query bounded to on-or-before a past
                    // date would miss it entirely. InventoryService::
                    // record()'s $occurredAt parameter handles this
                    // directly now - this used to be a raw
                    // DB::table('inventory_movements')->update(...) patched
                    // on immediately after the call; that workaround is
                    // gone now that the authoritative method supports a
                    // business date itself (see the accounting audit's
                    // Finding 1).
                    $movement = $this->inventory->record(
                        $item->product,
                        (float) $item->quantity,
                        $item->product_unit_id,
                        'opening_balance',
                        'opening_balance',
                        $record->id,
                        null,
                        "Opening balance as of {$asOfDate}",
                        $userId,
                        'in',
                        (float) $item->unit_cost,
                        occurredAt: $asOfDate,
                    );

                    $item->update(['inventory_movement_id' => $movement->id]);
                    continue;
                }

                $type = match ($item->category) {
                    'receivable' => 'opening_balance_receivable',
                    'other_receivable' => 'opening_balance_other_receivable',
                    'payable' => 'opening_balance_payable',
                    'loan_given' => 'opening_balance_loan_given',
                    'loan_received' => 'opening_balance_loan_received',
                    'capital' => 'opening_balance_capital',
                };

                $transaction = $this->transactions->create([
                    'type' => $type,
                    'person_id' => $item->person_id,
                    'supplier_id' => $item->supplier_id,
                    'amount' => $item->amount,
                    'description' => $this->describeItem($item, $asOfDate),
                    'transaction_date' => $asOfDate,
                ], $userId, internal: true);

                $item->update(['transaction_id' => $transaction->id]);
            }

            $record->update([
                'opening_equity' => $expectedEquity,
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => $userId,
            ]);

            AuditLog::record('opening_balance_locked', $record, null, [
                'opening_balance_id' => $record->id,
                'as_of_date' => $asOfDate,
                'opening_equity' => (string) $expectedEquity,
                'opening_assets' => (string) $openingAssets,
                'opening_liabilities' => (string) $openingLiabilities,
                'locked_by' => $userId,
            ]);

            return $record->fresh(['items']);
        });
    }

    /**
     * Reverses every posted item (void the transactions, compensate the
     * inventory movements - never delete anything already posted) and
     * clears the items so a correction can be entered fresh, then relocked.
     */
    public function reopen(?int $userId): OpeningBalance
    {
        return DB::transaction(function () use ($userId) {
            $record = OpeningBalance::query()->lockForUpdate()->first();

            if (!$record || $record->status !== 'locked') {
                throw ValidationException::withMessages([
                    'opening_balance' => 'The Opening Balance is not currently locked.',
                ]);
            }

            $record->load('items');
            $asOfDate = $record->as_of_date->toDateString();

            foreach ($record->items as $item) {
                if ($item->transaction_id) {
                    $transaction = Transaction::find($item->transaction_id);

                    if ($transaction && $transaction->status === 'posted') {
                        $this->transactions->voidTransaction($transaction, 'Opening Balance reopened for correction');
                    }
                }

                if ($item->inventory_movement_id) {
                    $movement = InventoryMovement::find($item->inventory_movement_id);

                    if ($movement) {
                        // Dated on the same day as the original movement
                        // (not "today", unlike an ordinary sale/purchase
                        // void's reversal - see the accounting audit's
                        // Finding 1) - a reopen-correct-relock cycle must
                        // make every past-and-future report agree with the
                        // CORRECTED position from as_of_date onward, not
                        // just from today onward. This used to be a raw
                        // DB::table('inventory_movements')->update(...)
                        // patched on after the call; consolidated onto
                        // $occurredAt now that record() supports it
                        // directly.
                        $this->inventory->record(
                            $movement->product,
                            (float) $item->quantity,
                            $item->product_unit_id,
                            'opening_balance_reversal',
                            'opening_balance_reversal',
                            $record->id,
                            'Opening Balance reopened for correction',
                            null,
                            $userId,
                            'out',
                            (float) $item->unit_cost,
                            occurredAt: $asOfDate,
                        );
                    }
                }
            }

            $record->items()->delete();

            $record->update([
                'status' => 'reopened',
                'reopened_at' => now(),
                'reopened_by' => $userId,
            ]);

            AuditLog::record('opening_balance_reopened', $record, ['status' => 'locked'], [
                'status' => 'reopened',
                'reopened_by' => $userId,
                'reopened_at' => now()->toIso8601String(),
            ]);

            return $record->fresh();
        });
    }

    private function describeItem(OpeningBalanceItem $item, string $asOfDate): string
    {
        return match ($item->category) {
            'receivable' => "Opening customer receivable as of {$asOfDate}",
            'other_receivable' => "Opening other receivable as of {$asOfDate}",
            'payable' => "Opening supplier payable as of {$asOfDate}",
            'loan_given' => "Opening loan given as of {$asOfDate}",
            'loan_received' => "Opening loan received as of {$asOfDate}",
            'capital' => "Opening owner capital allocation as of {$asOfDate}",
            default => "Opening balance as of {$asOfDate}",
        };
    }
}
