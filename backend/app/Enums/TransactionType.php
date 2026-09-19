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

    case AccountTransfer = 'account_transfer';
    case Adjustment = 'adjustment';
}