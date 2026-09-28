<?php

namespace App\Enums;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';

    case CashSale = 'cash_sale';
    case CreditSale = 'credit_sale';
    case CustomerPayment = 'customer_payment';

    case SupplierPayment = 'supplier_payment';
    case Purchase = 'purchase';

    case LoanGiven = 'loan_given';
    case LoanRepayment = 'loan_repayment';
    case LoanReceived = 'loan_received';
    case LoanPayment = 'loan_payment';

    case DebtCreated = 'debt_created';
    case DebtPayment = 'debt_payment';

    // A customer return against a specific sale (see SaleReturnService).
    // SaleReturn always reduces the receivable (or, once it's already at
    // zero, creates a negative balance = customer credit) - a real cash
    // refund is a SEPARATE SaleReturnRefund transaction, only ever created
    // for the excess left over after the return has first been applied to
    // whatever was still outstanding on that sale.
    case SaleReturn = 'sale_return';
    case SaleReturnRefund = 'sale_return_refund';

    // Mirrors the sale-return pair for a supplier return against a
    // specific purchase (see PurchaseReturnService).
    case PurchaseReturn = 'purchase_return';
    case PurchaseReturnRefund = 'purchase_return_refund';

    case AccountTransfer = 'account_transfer';
    case Adjustment = 'adjustment';

    // Owner equity movements - see TransactionService::effectsFor(). Never
    // treated as income/expense (BusinessReportService's revenue/expense
    // queries filter by these exact type strings and neither one matches),
    // so capital never contaminates the P&L.
    case OwnerContribution = 'owner_contribution';
    case OwnerWithdrawal = 'owner_withdrawal';

    // A third-party purchase landed cost (e.g. a trucking company paid
    // separately from the supplier) - decreases the paying account exactly
    // like an expense, but deliberately is NOT the 'expense' type, so
    // BusinessReportService::profit()'s operating_expenses query (which
    // filters type = 'expense') never counts it. Its cost is instead
    // capitalized into inventory and becomes COGS when that stock sells -
    // counting it as an operating expense too would double it. Always
    // requires purchase_id (see TransactionService::validateBusinessRules)
    // since it only means something tied to a specific purchase's landed
    // cost - see PurchaseAdditionalCost.
    case PurchaseCost = 'purchase_cost';

    // The one-time Opening Balance / Initial Business Position (see
    // OpeningBalanceService). Every case here represents a pre-existing
    // balance the business already had before Gedi Finance started
    // tracking it, not current-period activity - each deliberately mirrors
    // only the BALANCE-side sign of its ordinary counterpart (CreditSale/
    // Purchase/LoanGiven/LoanReceived/DebtCreated) and drops the cash/
    // account effect entirely, since an opening balance never represents a
    // fresh cash movement. Kept as distinct type strings (never reusing
    // credit_sale/purchase/loan_given/loan_received/owner_contribution) so
    // BusinessReportService::profit()'s revenue/expense queries and
    // BusinessCapitalService::capitalMovement()'s contribution/withdrawal
    // sums - which all filter by exact type string - can never pick them
    // up as current-year activity.
    case OpeningBalanceReceivable = 'opening_balance_receivable';
    case OpeningBalanceOtherReceivable = 'opening_balance_other_receivable';
    case OpeningBalancePayable = 'opening_balance_payable';
    case OpeningBalanceLoanGiven = 'opening_balance_loan_given';
    case OpeningBalanceLoanReceived = 'opening_balance_loan_received';
    case OpeningBalanceCapital = 'opening_balance_capital';

    /**
     * Every type the generic POST /api/transactions endpoint may create
     * directly: income/expense, account transfers/adjustments, the Loans &
     * Debts settlement types, and owner capital (separately gated by
     * TransactionService::assertAuthorizedForCapitalMovement()). Every
     * other type is "service-owned" - it carries required linkage (a
     * Sale/Purchase/OpeningBalance record), inventory effects, denormalized
     * totals, or reconciliation math that only its owning service
     * (SaleService, PurchaseService, SaleReturnService,
     * PurchaseReturnService, CustomerPaymentService, SupplierPaymentService,
     * OpeningBalanceService) performs alongside it - see
     * TransactionService::create()'s own $internal flag, which is the only
     * way one of those types is ever actually posted. The single source of
     * truth for this split: StoreTransactionRequest and TransactionService
     * both read this list rather than each keeping their own copy.
     *
     * @return list<self>
     */
    public static function publiclyCreatable(): array
    {
        return [
            self::Income,
            self::Expense,
            self::AccountTransfer,
            self::Adjustment,
            self::LoanGiven,
            self::LoanRepayment,
            self::LoanReceived,
            self::LoanPayment,
            self::DebtCreated,
            self::DebtPayment,
            self::OwnerContribution,
            self::OwnerWithdrawal,
        ];
    }
}