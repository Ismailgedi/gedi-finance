<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions)
    {
    }

    public function index(): JsonResponse
    {
        $transactions = Transaction::query()
            ->with(['person', 'account', 'destinationAccount', 'category', 'supplier', 'sale', 'purchase', 'items'])
            ->latest('transaction_date')
            ->paginate(25);

        return response()->json($transactions);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactions->create(
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Transaction created successfully.',
            'transaction' => $transaction,
        ], 201);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json(
            $transaction->load(['person', 'account', 'destinationAccount', 'category', 'loan', 'supplier', 'sale', 'purchase', 'items', 'attachments'])
        );
    }

    /**
     * A printable receipt for a single posted transaction, covering every
     * transaction type the ledger supports. Reuses the same relationships
     * show() already loads (plus the creator, needed for the receipt
     * footer) rather than building a separate receipt model or query.
     *
     * No new "receipt" record is stored: the transaction_number already
     * uniquely identifies the transaction, so the receipt number is derived
     * from it on the fly instead of duplicating an identifier in the
     * database.
     */
    public function receipt(Transaction $transaction): JsonResponse
    {
        $transaction->load([
            'person',
            'account',
            'destinationAccount',
            'category',
            'loan',
            'supplier',
            'sale',
            'purchase',
            'items',
            'attachments',
            'creator',
        ]);

        return response()->json([
            'receipt_number' => 'RCPT-' . $transaction->transaction_number,
            'generated_at' => now()->toIso8601String(),
            'business_name' => config('app.name'),
            'transaction' => $transaction,
        ]);
    }
}
