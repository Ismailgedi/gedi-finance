import { Fragment, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import {
  BrowserRouter,
  Link,
  Navigate,
  NavLink,
  Outlet,
  Route,
  Routes,
  useNavigate,
  useParams,
  useSearchParams,
} from 'react-router-dom'
import {
  ArrowDownLeft,
  ArrowLeft,
  ArrowRight,
  ArrowUpRight,
  BarChart3,
  Check,
  Copy,
  CreditCard,
  Download,
  Eye,
  EyeOff,
  KeyRound,
  LayoutDashboard,
  Lock,
  Mail,
  Menu,
  Monitor,
  MoreHorizontal,
  Moon,
  Package,
  Pencil,
  Power,
  Printer,
  ReceiptText,
  RefreshCw,
  Search,
  Settings as SettingsIcon,
  ShieldCheck,
  Sun,
  TrendingUp,
  Truck,
  UserPlus,
  Users,
  Wallet,
  X,
} from 'lucide-react'
import './App.css'
import logoIcon from './assets/brand/gedi-finance-icon.svg'
import logoHorizontal from './assets/brand/gedi-finance-primary-horizontal.svg'
import logoOnDark from './assets/brand/gedi-finance-primary-on-dark.svg'
import logoIconWhite from './assets/brand/gedi-finance-icon-single-white.svg'
import { AuthProvider } from './auth/AuthContext'
import { useAuth } from './auth/useAuth'
import * as authApi from './auth/authApi'
import { useTheme } from './theme/useTheme'
import type { ThemeMode } from './theme/context'

const THEME_OPTIONS: { value: ThemeMode; label: string; icon: typeof Sun }[] = [
  { value: 'light', label: 'Light theme', icon: Sun },
  { value: 'dark', label: 'Dark theme', icon: Moon },
  { value: 'system', label: 'Match system theme', icon: Monitor },
]

function ThemeToggle() {
  const { mode, setMode } = useTheme()

  return (
    <div className="theme-toggle" role="group" aria-label="Theme">
      {THEME_OPTIONS.map(({ value, label, icon: Icon }) => (
        <button
          key={value}
          type="button"
          aria-label={label}
          aria-pressed={mode === value}
          onClick={() => setMode(value)}
        >
          <Icon size={16} aria-hidden="true" />
        </button>
      ))}
    </div>
  )
}

function apiFetch(input: RequestInfo | URL, init: RequestInit = {}) {
  const xsrfCookie = document.cookie
    .split('; ')
    .find((value) => value.startsWith('XSRF-TOKEN='))
  const xsrfToken = xsrfCookie
    ? decodeURIComponent(xsrfCookie.substring('XSRF-TOKEN='.length))
    : ''

  return fetch(input, {
    ...init,
    credentials: 'include',
    headers: {
      ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
      ...init.headers,
    },
  })
}

/**
 * Fetches every page of GET /api/transactions and concatenates them.
 * Several pages compute totals, balances or filtered views client-side
 * from the full ledger (Loans & Debts balances, Accounts' per-account
 * transaction counts, the legacy Reports page's month filter, Sales'
 * secondary activity list) - fetching only the first page silently makes
 * all of those wrong once the ledger passes one page. This is the single
 * place that pagination logic lives so every caller behaves the same way.
 */
async function fetchAllTransactions(): Promise<Transaction[]> {
  const response = await apiFetch('/api/transactions?per_page=100')
  if (!response.ok) {
    throw new Error('Unable to load transaction data.')
  }

  const data: TransactionsResponse = await response.json()
  let all = data.data

  if (data.last_page > data.current_page) {
    const remainingPages = Array.from(
      { length: data.last_page - data.current_page },
      (_, index) => data.current_page + index + 1,
    )
    const pageResponses = await Promise.all(
      remainingPages.map((page) => apiFetch(`/api/transactions?per_page=100&page=${page}`)),
    )
    const pageData = await Promise.all(
      pageResponses.map(async (pageResponse) => {
        if (!pageResponse.ok) throw new Error('Unable to load transaction data.')
        return (await pageResponse.json()) as TransactionsResponse
      }),
    )
    all = all.concat(...pageData.map((page) => page.data))
  }

  return all
}

/**
 * Downloads a real, server-generated Excel workbook (a genuine .xlsx, not
 * a CSV renamed with an .xlsx extension) from one of the
 * /api/reports/.../excel endpoints. The query string built from `params`
 * IS the filter - the backend re-runs the exact same filtered query the
 * screen used, so the workbook always matches what was on screen when the
 * button was pressed. When no filters are active (`params` is empty), the
 * "?" is left off entirely rather than sent as a bare trailing "?" - some
 * proxies (e.g. the Vite dev server's /api proxy) reject a request whose
 * path ends in an empty query string.
 *
 * `basePath` is always a relative "/api/..." path, resolved by the browser
 * against whatever origin the page was loaded from (the phone's own LAN
 * address when on a phone) and handled by Vite's dev-server proxy exactly
 * like every other API call in this app - never a hardcoded host.
 *
 * `fallbackFilename` is only used if the server's Content-Disposition
 * header is missing for some reason - the backend is the source of truth
 * for the filename (it's the one that knows, e.g., which customer a
 * statement is for), matching what the actual download attachment name
 * will be.
 */
async function downloadExcelReport(basePath: string, params: URLSearchParams, fallbackFilename: string): Promise<void> {
  const query = params.toString()
  const path = query ? `${basePath}?${query}` : basePath
  const response = await apiFetch(path, {
    headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
  })

  if (!response.ok) {
    let message = 'Unable to generate the Excel file. Please try again.'
    try {
      const data = await response.json()
      if (typeof data?.message === 'string') message = data.message
    } catch {
      // Not a JSON error body - keep the default message.
    }
    throw new Error(message)
  }

  // Confirm the server actually sent a workbook before treating the body
  // as one. A misbehaving proxy or an error response that slipped through
  // with a 200 status would otherwise silently turn into a corrupt file.
  const contentType = (response.headers.get('content-type') ?? '').toLowerCase()
  if (!contentType.includes('spreadsheetml') && !contentType.includes('ms-excel')) {
    throw new Error('The server did not return an Excel file. Please try again.')
  }

  const blob = await response.blob()
  if (blob.size === 0) {
    throw new Error('The generated Excel file was empty. Please try again.')
  }

  const disposition = response.headers.get('content-disposition') ?? ''
  const filenameMatch = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = filenameMatch ? decodeURIComponent(filenameMatch[1]) : fallbackFilename

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()

  // Revoking immediately can race with the browser actually starting the
  // download/open (notably on iOS Safari, which handles <a download> with
  // a blob URL asynchronously) - a short delay lets that complete first.
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
}

/**
 * Turns a business name into a safe filename fragment, e.g.
 * "Ali Hassan" -> "ali-hassan".
 */
function slugForFilename(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '') || 'record'
}

type Account = {
  id: number
  name: string
  type: string
  opening_balance: string
  currency: string
  is_active: boolean
  current_balance: string
}

type DashboardData = {
  today: {
    income: string
    expense: string
    customer_payments: string
    cash_sales: string
    credit_sales: string
    sales_total: string
    purchases_total: string
    gross_profit: string
  }
  receivables: {
    increases: string
    decreases: string
    outstanding: string
    // Customer-only (credit sales/payments) - excludes loans and debts.
    customer_outstanding: string
  }
  payables: {
    // Supplier-only (unpaid purchases) - excludes loans the business owes.
    outstanding: string
  }
  loans: {
    outstanding_given: string
    outstanding_received: string
  }
}

type Person = {
  id: number
  name: string
  phone: string | null
  address: string | null
  notes: string | null
  roles: string[]
  is_active: boolean
  // The actual flag SaleService checks before letting a person be used as
  // a sale's customer_id - distinct from the cosmetic `roles` tag list.
  is_customer: boolean
  credit_limit: string | number | null
  payment_terms_days: number | null
  // Column exists but is not currently populated by any write path - kept
  // optional so the invoice can show it if/when it ever is, without
  // inventing a value when it's absent.
  customer_code?: string | null
  created_at: string
  updated_at: string
}

type PeopleResponse = {
  current_page: number
  data: Person[]
  last_page: number
  total: number
}

type PersonTransaction = {
  id: number
  transaction_number: string
  type: string
  person_id: number | null
  account_id: number | null
  category_id: number | null
  loan_id: number | null
  amount: string
  currency: string
  description: string
  reference: string | null
  transaction_date: string
  created_at?: string | null
  status: string
  account_balance_effect?: string
  destination_account_effect?: string
  account?: {
    id: number
    name: string
  } | null
  category?: {
    id: number
    name: string
  } | null
}

type PersonDetailResponse = {
  person: Person
  balance: string
  transactions: {
    current_page: number
    data: PersonTransaction[]
    last_page: number
    total: number
  }
}

type Transaction = {
  id: number
  transaction_number: string
  type: string
  amount: string
  currency: string
  description: string
  // Carries the invoice/purchase number for sale- and purchase-linked
  // transactions (see SaleService/PurchaseService/CustomerPaymentService/
  // SupplierPaymentService), so this is what a search for "INV-..." or a
  // purchase number actually needs to match against - transaction_number
  // is a separate, internal ledger id.
  reference?: string | null
  transaction_date: string
  created_at?: string | null
  status: string
  account_balance_effect?: string
  destination_account_effect?: string
  person?: Person | null
  account?: Account | null
  destination_account?: Account | null
  // Purchases/supplier payments name their other party via `supplier`, not
  // `person` - a separate model (see backend/app/Models/Supplier.php).
  supplier?: { id: number; name: string } | null
  category?: {
    id: number
    name: string
  } | null
}

type TransactionsResponse = {
  current_page: number
  data: Transaction[]
  last_page: number
  total: number
}

type SaleItem = {
  id: number
  product_id: number
  product_unit_id: number | null
  quantity: string | number
  unit_price: string | number
  discount: string | number
  line_total: string | number
  product?: {
    id: number
    name: string
    sku: string
    // Present when no product_unit was chosen for the line (the item was
    // sold in the product's normal sellable unit, e.g. Bag) - the invoice
    // must fall back to this so the Unit column isn't blank for the most
    // common wholesale case.
    base_unit?: {
      id: number
      name: string
      abbreviation?: string | null
    } | null
  } | null
  product_unit?: {
    id: number
    selling_price?: string | number
    unit?: {
      id: number
      name: string
      abbreviation?: string | null
    } | null
  } | null
}

// Mirrors the `sales` table exactly (see backend/database/migrations for
// `sales`/`sale_items`) - there is no `sale_type` column and the total
// column is `total`, not `total_amount`.
type Sale = {
  id: number
  invoice_number: string
  sale_date: string
  due_date?: string | null
  subtotal: string | number
  discount: string | number
  total: string | number
  amount_paid: string | number
  balance_due: string | number
  cost_of_goods_sold: string | number
  gross_profit: string | number
  payment_status: string
  status: string
  voided_at?: string | null
  void_reason?: string | null
  voided_by?: { id: number; name: string } | null
  customer?: Person | null
  items: SaleItem[]
}

type SalesResponse = {
  current_page: number
  data: Sale[]
  last_page: number
  total: number
}

type Supplier = {
  id: number
  supplier_code: string
  name: string
  phone: string | null
  email: string | null
  address: string | null
  credit_limit: string | number | null
  payment_terms_days: number | null
  is_active: boolean
  notes: string | null
}

type SuppliersResponse = {
  current_page: number
  data: Supplier[]
  last_page: number
  total: number
}

type Unit = {
  id: number
  name: string
  abbreviation: string
}

type ProductCategory = {
  id: number
  name: string
  description: string | null
  is_active: boolean
}

type ProductUnitEntry = {
  id: number
  unit_id: number
  conversion_factor: string | number
  is_default: boolean
  unit?: Unit | null
}

type Product = {
  id: number
  category_id: number | null
  base_unit_id: number
  name: string
  sku: string
  default_cost_price: string | number | null
  default_selling_price: string | number | null
  default_wholesale_price: string | number | null
  minimum_stock: string | number | null
  is_active: boolean
  notes: string | null
  category?: ProductCategory | null
  base_unit?: Unit | null
  units?: ProductUnitEntry[]
}

type ProductsResponse = {
  current_page: number
  data: Product[]
  last_page: number
  total: number
}

type PurchaseItem = {
  id: number
  product_id: number
  product_unit_id: number | null
  quantity: string | number
  unit_cost: string | number
  line_total: string | number
  product?: {
    id: number
    name: string
    sku: string
    base_unit?: {
      id: number
      name: string
      abbreviation?: string | null
    } | null
  } | null
  product_unit?: {
    id: number
    unit?: {
      id: number
      name: string
      abbreviation?: string | null
    } | null
  } | null
}

type Purchase = {
  id: number
  purchase_number: string
  purchase_date: string
  due_date?: string | null
  subtotal: string | number
  discount: string | number
  total: string | number
  amount_paid: string | number
  balance_due: string | number
  payment_status: string
  status: string
  voided_at?: string | null
  void_reason?: string | null
  voided_by?: { id: number; name: string } | null
  supplier?: Supplier | null
  items: PurchaseItem[]
}

type PurchasesResponse = {
  current_page: number
  data: Purchase[]
  last_page: number
  total: number
}

type ReceiptTransactionItem = {
  id: number
  description: string
  quantity: string | number
  unit_price: string | number
  total: string | number
}

type ReceiptAttachment = {
  id: number
  file_name: string
}

/**
 * The full transaction record as the receipt endpoint returns it: the same
 * fields/relationships TransactionController::show() already loads, plus
 * the effect columns and the creator relation the receipt footer needs.
 */
type ReceiptTransaction = {
  id: number
  transaction_number: string
  type: string
  amount: string
  currency: string
  person_balance_effect: string
  account_balance_effect: string
  destination_account_effect: string
  supplier_balance_effect?: string
  description: string
  reference: string | null
  transaction_date: string
  created_at?: string | null
  status: string
  person?: Person | null
  account?: Account | null
  destination_account?: Account | null
  category?: { id: number; name: string } | null
  loan?: { id: number; type: string; principal_amount: string } | null
  supplier?: { id: number; name: string } | null
  sale?: { id: number; invoice_number: string; total?: string | number } | null
  purchase?: { id: number; purchase_number: string; total?: string | number } | null
  items: ReceiptTransactionItem[]
  attachments: ReceiptAttachment[]
  creator?: { id: number; name: string } | null
}

type ReceiptResponse = {
  receipt_number: string
  generated_at: string
  business_name: string
  transaction: ReceiptTransaction
}

/** Shared filter shape used by the business reporting endpoints. */
type ReportPeriod = {
  from: string
  to: string
  range: string
}

type ReportDateRange =
  | 'today'
  | 'this_week'
  | 'this_month'
  | 'last_month'
  | 'this_year'
  | 'custom'

type BusinessSummary = {
  period: ReportPeriod
  financial: {
    total_sales: string
    cash_sales: string
    credit_sales: string
    customer_collections: string
    total_expenses: string
    gross_profit: string
    net_profit: string
    accounts_balance: string
  }
  credit: {
    accounts_receivable: string
    accounts_payable: string
    overdue_customer_balance: string
    supplier_balances: string
  }
  inventory: {
    inventory_value: string
    current_stock_quantity: number
    low_stock_products: number
    active_products: number
    stock_requiring_attention: number
  }
  sales: {
    invoices: number
    average_sale_value: string
    today_sales: string
    this_month_sales: string
  }
  purchases: {
    count: number
    purchase_value: string
    amount_paid: string
    outstanding_supplier_balance: string
  }
}

type ReportMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type SalesReportRow = {
  id: number
  invoice_number: string
  date: string
  customer: string | null
  total: string
  amount_paid: string
  balance_due: string
  cogs: string
  gross_profit: string
  payment_status: string
}

type SalesReportResponse = {
  period: ReportPeriod
  data: SalesReportRow[]
  meta: ReportMeta
  summary: {
    total_sales: string
    total_cogs: string
    gross_profit: string
    amount_collected: string
    outstanding: string
  }
}

type PurchasesReportRow = {
  id: number
  purchase_number: string
  date: string
  supplier: string | null
  total: string
  amount_paid: string
  balance_due: string
  payment_status: string
}

type PurchasesReportResponse = {
  period: ReportPeriod
  data: PurchasesReportRow[]
  meta: ReportMeta
  summary: {
    total_purchases: string
    amount_paid: string
    outstanding_supplier_balance: string
  }
}

type ProfitReport = {
  period: ReportPeriod
  revenue: { sales_revenue: string }
  cost_of_goods_sold: { cogs: string }
  gross_profit: string
  operating_expenses: {
    by_category: { category: string; amount: string }[]
    total: string
  }
  net_profit: string
}

type ReceivablePayableRow = {
  id: number
  name: string
  customer_code?: string | null
  supplier_code?: string | null
  credit_limit: string | null
  balance: string
  available_credit: string | null
}

type AgingBucket = { label: string; outstanding: string }
type AgingSummary = { buckets: AgingBucket[]; total: string }

type InventoryStatus = 'in_stock' | 'low_stock' | 'out_of_stock'

type InventoryRow = {
  id: number
  product: string
  sku: string
  stock: number
  minimum_stock: number
  unit: string
  inventory_value: string
  cost_per_unit: string | null
  status: InventoryStatus
}

function formatMoney(value: string | number) {
  return `$${Number(value).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function formatTransactionType(type: string) {
  return type
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatDate(date: string) {
  return new Date(date).toLocaleDateString()
}

function formatTime(date?: string | null) {
  if (!date) return '—'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return '—'
  return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function transactionTimestamp(transaction: Pick<Transaction, 'transaction_date' | 'created_at'>) {
  return transaction.created_at || transaction.transaction_date
}

function formatSignedMoney(value: number) {
  if (Math.abs(value) < 0.005) return formatMoney(0)
  return `${value > 0 ? '+' : '-'}${formatMoney(Math.abs(value))}`
}

/**
 * The single source of truth for money coloring across the entire app:
 * positive = green, negative = red, (effectively) zero = neutral. Every
 * money display resolves to a signed number - even when what's on screen
 * is an unsigned magnitude with an implied direction (an expense, a payable)
 * - and runs that number through here rather than deciding color locally.
 */
function moneyTone(value: number): 'money-pos' | 'money-neg' | 'money-neutral' {
  if (value > 0.005) return 'money-pos'
  if (value < -0.005) return 'money-neg'
  return 'money-neutral'
}

function moneyToneClass(value: number) {
  return `money ${moneyTone(value)}`
}

/**
 * Derived defensively from the actual paid/balance amounts rather than
 * trusting the backend's `payment_status` string outright, so a zero
 * balance always reads as paid even if the stored status ever drifts.
 */
function invoicePaymentStatus(sale: Pick<Sale, 'amount_paid' | 'balance_due'>): 'paid' | 'partial' | 'unpaid' {
  const balance = Number(sale.balance_due)
  const paid = Number(sale.amount_paid)

  if (balance <= 0.005) return 'paid'
  if (paid > 0.005) return 'partial'
  return 'unpaid'
}

function invoiceStatusLabel(status: 'paid' | 'partial' | 'unpaid') {
  if (status === 'paid') return 'PAID'
  if (status === 'partial') return 'PARTIALLY PAID'
  return 'UNPAID'
}

/**
 * Payment-status badges (paid/partial/unpaid) are meaningless once a
 * record is voided - its balance_due is left as historical record, not
 * reset, so without this a voided sale/purchase would misleadingly still
 * show "UNPAID" etc. in list views instead of the fact that it was voided.
 */
function recordStatusBadge(record: Pick<Sale, 'status' | 'amount_paid' | 'balance_due'>) {
  if (record.status === 'voided') {
    return { label: 'VOIDED', className: 'status-badge-voided' }
  }

  const status = invoicePaymentStatus(record)
  return { label: invoiceStatusLabel(status), className: `status-badge-${status}` }
}

function personBalanceMap(transactions: Transaction[]) {
  const balances = new Map<number, number>()

  for (const transaction of transactions) {
    if (!transaction.person?.id || transaction.status !== 'posted') continue

    const personId = transaction.person.id
    const amount = Number(transaction.amount)
    const current = balances.get(personId) ?? 0

    if (['credit_sale', 'loan_given', 'debt_created'].includes(transaction.type)) {
      balances.set(personId, current + amount)
    } else if (['customer_payment', 'loan_repayment', 'loan_received', 'debt_payment'].includes(transaction.type)) {
      balances.set(personId, current - amount)
    } else if (transaction.type === 'loan_payment') {
      balances.set(personId, current + amount)
    }
  }

  return balances
}

/**
 * The transaction API only ever sends an unsigned amount (a magnitude), so
 * there is no literal sign to color from directly. This recovers the real
 * signed cash effect from the same ledger fields the backend already
 * computed in TransactionService::effectsFor - account_balance_effect for
 * the transaction's own account, destination_account_effect for the second
 * leg of a transfer - and sums them. That sum is the transaction's net
 * effect on total business cash: it cancels to 0 for a transfer between two
 * of the business's own accounts, and is signed correctly for genuine
 * inflows/outflows. The color itself is then decided purely by that
 * number's sign via moneyTone(), not by switching on the type string.
 */
function transactionCashEffect(
  transaction: Pick<Transaction, 'account_balance_effect' | 'destination_account_effect'>,
): number {
  return (
    Number(transaction.account_balance_effect ?? 0) +
    Number(transaction.destination_account_effect ?? 0)
  )
}

function PageHeader({
  eyebrow,
  title,
  description,
  actions,
}: {
  eyebrow: string
  title: string
  description?: string
  actions?: ReactNode
}) {
  return (
    <div className="page-header">
      <div>
        <p className="eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        {description ? <p className="muted">{description}</p> : null}
      </div>
      {actions ? <div className="page-header-actions">{actions}</div> : null}
    </div>
  )
}

function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon?: ReactNode
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="empty-state">
      {icon}
      <h3>{title}</h3>
      <p>{description}</p>
      {action}
    </div>
  )
}

function Dashboard() {
  const navigate = useNavigate()
  const [dashboard, setDashboard] = useState<DashboardData | null>(null)
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadDashboard() {
      try {
        setLoading(true)
        setError('')

        const [dashboardResponse, accountsResponse] = await Promise.all([
          apiFetch('/api/dashboard'),
          apiFetch('/api/accounts'),
        ])

        if (!dashboardResponse.ok || !accountsResponse.ok) {
          throw new Error('Unable to load dashboard data.')
        }

        const dashboardData: DashboardData = await dashboardResponse.json()
        const accountsData: Account[] = await accountsResponse.json()

        setDashboard(dashboardData)
        setAccounts(accountsData)
      } catch (err) {
        setError(
          err instanceof Error
            ? err.message
            : 'Unable to load dashboard data.',
        )
      } finally {
        setLoading(false)
      }
    }

    void loadDashboard()
  }, [])

  const totalBalance = accounts.reduce(
    (total, account) => total + Number(account.current_balance),
    0,
  )

  const receivables = Number(dashboard?.receivables.customer_outstanding ?? 0)
  const payables = Number(dashboard?.payables.outstanding ?? 0)
  const todaySales = Number(dashboard?.today.sales_total ?? 0)
  const todayPurchases = Number(dashboard?.today.purchases_total ?? 0)
  const todayGrossProfit = Number(dashboard?.today.gross_profit ?? 0)
  const outstandingLoans = Number(dashboard?.loans.outstanding_given ?? 0)

  return (
    <div className="page">
      <PageHeader
        eyebrow="Gedi Finance"
        title="Dashboard"
        description="Where your goods, money and credit stand right now."
        actions={
          <button
            className="primary-button"
            type="button"
            onClick={() => navigate('/sales?record=1')}
          >
            + New Sale
          </button>
        }
      />

      {error && <div className="error-banner">{error}</div>}

      <div className="dashboard-top-row">
        <section className="hero-balance">
          <span>Total Business Money</span>
          <strong className={moneyToneClass(totalBalance)}>
            {loading ? 'Loading...' : formatMoney(totalBalance)}
          </strong>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Accounts</h2>
              <p>Cash, bank and mobile money available to the business.</p>
            </div>
          </div>
          <div className="account-list account-grid">
            {loading ? (
              <div className="account-loading">Loading accounts...</div>
            ) : accounts.length === 0 ? (
              <EmptyState
                title="No money accounts yet"
                description="Configured Cash, Bank, EVC, eDahab and JEEB accounts will appear here."
              />
            ) : (
              accounts.map((account) => (
                <div className="account-row" key={account.id}>
                  <span>{account.name}</span>
                  <strong className={moneyToneClass(Number(account.current_balance))}>
                    {formatMoney(account.current_balance)}
                  </strong>
                </div>
              ))
            )}
          </div>
        </section>
      </div>

      <div className="stats-grid stats-grid-2">
        <Link className="stat-card stat-card-link" to="/reports/business/receivables">
          <span>Customers Owe Us</span>
          <strong className={moneyToneClass(receivables)}>
            {loading ? '...' : formatMoney(receivables)}
          </strong>
          <small>Receivables</small>
        </Link>
        <Link className="stat-card stat-card-link" to="/reports/business/payables">
          <span>We Owe Suppliers</span>
          <strong className={moneyToneClass(-payables)}>
            {loading ? '...' : formatMoney(payables)}
          </strong>
          <small>Payables</small>
        </Link>
      </div>

      <nav className="quick-actions" aria-label="Quick actions">
        <Link className="quick-action" to="/sales?record=1">Sell Goods</Link>
        <Link className="quick-action" to="/purchases?record=1">Buy Goods</Link>
        <Link className="quick-action" to="/receive-payment">Receive Payment</Link>
        <Link className="quick-action" to="/pay-supplier">Pay Supplier</Link>
        <Link className="quick-action" to="/loans">Loan</Link>
        <Link className="quick-action" to="/record?kind=account_transfer">Transfer Money</Link>
      </nav>

      <div className="today-activity">
        <div className="stat-card">
          <span>Today's Sales</span>
          <strong className={moneyToneClass(todaySales)}>
            {loading ? '...' : formatMoney(todaySales)}
          </strong>
          <small>Today</small>
        </div>
        <div className="stat-card">
          <span>Today's Purchases</span>
          <strong>
            {loading ? '...' : formatMoney(todayPurchases)}
          </strong>
          <small>Today</small>
        </div>
        <div className="stat-card">
          <span>Today's Gross Profit</span>
          <strong className={moneyToneClass(todayGrossProfit)}>
            {loading ? '...' : formatMoney(todayGrossProfit)}
          </strong>
          <small>Today</small>
        </div>
        <div className="stat-card">
          <span>Outstanding Loans</span>
          <strong className={moneyToneClass(outstandingLoans)}>
            {loading ? '...' : formatMoney(outstandingLoans)}
          </strong>
          <small>Owed to Gedi</small>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Recent Activity</h2>
            <p>Latest sales, purchases and payments across the business.</p>
          </div>
          <Link className="secondary-button" to="/transactions">View all</Link>
        </div>
        <RecentDashboardActivity />
      </section>
    </div>
  )
}

function RecentDashboardActivity() {
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    async function loadTransactions() {
      try {
        const response = await apiFetch('/api/transactions')

        if (!response.ok) {
          throw new Error('Unable to load transactions.')
        }

        const data: TransactionsResponse =
          await response.json()

        setTransactions(data.data.slice(0, 5))
      } catch {
        setTransactions([])
      } finally {
        setLoading(false)
      }
    }

    void loadTransactions()
  }, [])

  if (loading) {
    return (
      <div className="activity-loading">
        Loading activity...
      </div>
    )
  }

  if (transactions.length === 0) {
    return (
      <EmptyState
        icon={<ReceiptText size={32} />}
        title="You haven't recorded any transactions yet."
        description="Income, expenses, loans and transfers will appear here."
        action={
          <Link className="primary-button" to="/record">
            + Add Transaction
          </Link>
        }
      />
    )
  }

  return (
    <div className="activity-list">
      {transactions.map((transaction) => (
        <Link
          className="activity-row receipt-row-link"
          key={transaction.id}
          to={`/transactions/${transaction.id}/receipt`}
          title="View receipt"
        >
          <div className="activity-main">
            <strong>
              {formatTransactionType(transaction.type)}
            </strong>

            <span>
              {transaction.person?.name ||
                'General transaction'}
            </span>
          </div>

          <div className="activity-side">
            <strong className={moneyToneClass(transactionCashEffect(transaction))}>
              {formatMoney(transaction.amount)}
            </strong>

            <span>
              {formatDate(transaction.transaction_date)} · {formatTime(transactionTimestamp(transaction))}
            </span>
          </div>
        </Link>
      ))}
    </div>
  )
}

function People() {
  const [people, setPeople] = useState<Person[]>([])
  // Who owes us, and how much - sourced from the same, already-correct
  // receivables report the Reports hub uses (real customers with a
  // balance > 0), rather than recomputing it client-side from raw
  // transactions here too.
  const [owedByCustomer, setOwedByCustomer] = useState<Map<number, string>>(new Map())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(false)

  async function loadPeople() {
    try {
      setLoading(true)
      setError('')

      const [peopleResponse, receivablesResponse] = await Promise.all([
        apiFetch('/api/people'),
        apiFetch('/api/reports/customer-receivables'),
      ])

      if (!peopleResponse.ok) {
        throw new Error('Unable to load people.')
      }

      const data: PeopleResponse = await peopleResponse.json()
      setPeople(data.data)

      if (receivablesResponse.ok) {
        const receivablesData: { data: ReceivablePayableRow[] } = await receivablesResponse.json()
        setOwedByCustomer(new Map(receivablesData.data.map((row) => [row.id, row.balance])))
      }
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to load people.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadPeople()
  }, [])

  return (
    <div className="page">
      <PageHeader
        eyebrow="My People"
        title="Customers"
        description="Who buys from us - and who owes us money."
        actions={
          <button
            className="primary-button"
            onClick={() => setShowForm(true)}
          >
            + Add Person
          </button>
        }
      />

      {error && <div className="error-banner">{error}</div>}

      {showForm && (
        <AddPersonForm
          onClose={() => setShowForm(false)}
          onCreated={async () => {
            setShowForm(false)
            await loadPeople()
          }}
        />
      )}

      <section className="panel">
        <div className="panel-header people-header">
          <div>
            <h2>Business Contacts</h2>
            <p>
              {people.length} active contact(s)
            </p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">
            Loading people...
          </div>
        ) : people.length === 0 ? (
          <EmptyState
            icon={<Users size={32} />}
            title="No people yet"
            description="Add your first customer or business contact."
            action={
              <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
                + Add Person
              </button>
            }
          />
        ) : (
          <>
            <div className="people-table-wrapper people-desktop">
              <table className="people-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Owes Us</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {people.map((person) => (
                    <PersonRow
                      key={person.id}
                      person={person}
                      owed={owedByCustomer.get(person.id) ?? null}
                    />
                  ))}
                </tbody>
              </table>
            </div>
            <div className="people-mobile">
              {people.map((person) => (
                <PersonCard key={person.id} person={person} owed={owedByCustomer.get(person.id) ?? null} />
              ))}
            </div>
          </>
        )}
      </section>
    </div>
  )
}

function PersonCard({ person, owed }: { person: Person; owed: string | null }) {
  const navigate = useNavigate()
  const owesUs = owed != null && Number(owed) > 0.005

  return (
    <article
      className="person-card"
      role="button"
      tabIndex={0}
      onClick={() => navigate(`/people/${person.id}`)}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          navigate(`/people/${person.id}`)
        }
      }}
    >
      <h3>{person.name}</h3>
      {owesUs && owed != null && (
        <p className="person-card-owed">
          Owes Us <strong className="money-neg">{formatMoney(owed)}</strong>
        </p>
      )}
      <dl>
        <div className="kv-row">
          <dt>Phone</dt>
          <dd>{person.phone || 'Not provided'}</dd>
        </div>
        <div className="kv-row">
          <dt>Role</dt>
          <dd>{person.roles.join(', ') || 'contact'}</dd>
        </div>
        <div className="kv-row">
          <dt>Address</dt>
          <dd>{person.address || 'Not provided'}</dd>
        </div>
        <div className="kv-row">
          <dt>Status</dt>
          <dd>{person.is_active ? 'Active' : 'Inactive'}</dd>
        </div>
      </dl>
    </article>
  )
}

function PersonRow({ person, owed }: { person: Person; owed: string | null }) {
  const navigate = useNavigate()

  return (
    <tr
      className="clickable-row"
      onClick={() =>
        navigate(`/people/${person.id}`)
      }
      tabIndex={0}
      onKeyDown={(event) => {
        if (
          event.key === 'Enter' ||
          event.key === ' '
        ) {
          event.preventDefault()
          navigate(`/people/${person.id}`)
        }
      }}
      role="button"
    >
      <td>
        <strong>{person.name}</strong>
      </td>

      <td>
        {person.phone || 'Not provided'}
      </td>

      <td>
        <div className="role-list">
          {person.roles.map((role) => (
            <span
              className="role-badge"
              key={role}
            >
              {role}
            </span>
          ))}
        </div>
      </td>

      <td className={owed != null && Number(owed) > 0.005 ? 'money-neg' : ''}>
        {owed != null && Number(owed) > 0.005 ? formatMoney(owed) : '—'}
      </td>

      <td>
        <span className="status-badge">
          Active
        </span>
      </td>
    </tr>
  )
}

function PersonDetail() {
  const { personId } = useParams()
  const navigate = useNavigate()

  const [data, setData] =
    useState<PersonDetailResponse | null>(null)

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)

  async function loadPerson() {
    if (!personId) {
      setError('Person not found.')
      setLoading(false)
      return
    }

    try {
      setLoading(true)
      setError('')

      const response = await fetch(
        `/api/people/${personId}`,
      )

      if (!response.ok) {
        throw new Error(
          'Unable to load person.',
        )
      }

      const personData: PersonDetailResponse =
        await response.json()

      // personData.balance is already computed server-side by
      // BalanceService::personBalance() (a SQL sum over ALL of this
      // person's posted transactions) - it's authoritative on its own.
      // This used to be recalculated client-side from a second fetch of
      // "/api/transactions?person_id=..." for extra confidence, but
      // TransactionController@index doesn't actually support a person_id
      // filter, so that request silently returned the most recent
      // transactions system-wide rather than this person's, making the
      // recalculated balance wrong for anyone whose transactions weren't
      // within the last page of the GLOBAL ledger. Trusting the
      // server-computed value directly is both correct and simpler.
      setData(personData)
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to load person.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadPerson()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [personId])

  if (loading) {
    return (
      <div className="page">
        <div className="people-loading">
          Loading person...
        </div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="page">
        <div className="error-banner">
          {error || 'Person not found.'}
        </div>

        <button
          className="secondary-button"
          onClick={() => navigate('/people')}
        >
          <ArrowLeft size={16} />
          Back to People
        </button>
      </div>
    )
  }

  const {
    person,
    balance,
    transactions,
  } = data

  return (
    <div className="page">
      <div className="person-detail-topbar">
        <button
          className="back-button"
          onClick={() => navigate('/people')}
        >
          <ArrowLeft size={17} />
          Back to People
        </button>

        <button type="button" className="secondary-button" onClick={() => setEditing(true)}>
          <Pencil size={16} />
          Edit
        </button>
      </div>

      {editing && (
        <EditPersonForm
          person={person}
          onClose={() => setEditing(false)}
          onSaved={async () => {
            setEditing(false)
            await loadPerson()
          }}
        />
      )}

      <div className="person-detail-header">
        <div>
          <p className="eyebrow">
            Person Profile
          </p>

          <h1>{person.name}</h1>

          <div className="role-list detail-roles">
            {person.roles.map((role) => (
              <span
                className="role-badge"
                key={role}
              >
                {role}
              </span>
            ))}
          </div>
        </div>

        <div className="balance-card">
          <span>
            {Number(balance) < -0.005
              ? 'Payable Balance'
              : 'Outstanding Balance'}
          </span>

          <strong className={moneyToneClass(Number(balance))}>
            {formatMoney(Math.abs(Number(balance)))}
          </strong>

          <small>
            {Number(balance) > 0.005
              ? 'Amount owed to the business'
              : Number(balance) < -0.005
                ? 'Amount the business owes this person'
                : 'No outstanding balance'}
          </small>
        </div>
      </div>

      <div className="detail-grid">
        <section className="panel">
          <div className="panel-header">
            <h2>Contact Information</h2>
          </div>

          <div className="detail-list">
            <div className="detail-item">
              <span>Phone</span>
              <strong>
                {person.phone ||
                  'Not provided'}
              </strong>
            </div>

            <div className="detail-item">
              <span>Address</span>
              <strong>
                {person.address ||
                  'Not provided'}
              </strong>
            </div>

            <div className="detail-item">
              <span>Notes</span>
              <strong>
                {person.notes ||
                  'No notes'}
              </strong>
            </div>

            {person.is_customer && (
              <div className="detail-item">
                <span>Credit Limit</span>
                <strong>
                  {person.credit_limit != null ? formatMoney(person.credit_limit) : 'No limit'}
                </strong>
              </div>
            )}

            <div className="detail-item">
              <span>Status</span>
              <strong>
                {person.is_active
                  ? 'Active'
                  : 'Inactive'}
              </strong>
            </div>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <h2>Financial Summary</h2>

            <p>
              {transactions.total}{' '}
              transaction(s)
            </p>
          </div>

          <div className="summary-list">
            <div className="summary-row">
              <span>
                {Number(balance) < -0.005
                  ? 'Payable'
                  : 'Outstanding'}
              </span>

              <strong className={moneyToneClass(Number(balance))}>
                {formatMoney(Math.abs(Number(balance)))}
              </strong>
            </div>

            <div className="summary-row">
              <span>Transactions</span>

              <strong>
                {transactions.total}
              </strong>
            </div>
          </div>
        </section>
      </div>

      <section className="panel transaction-history-panel">
        <div className="panel-header">
          <div>
            <h2>Financial History</h2>
            <p>
              All recorded transactions for{' '}
              {person.name}
            </p>
          </div>
        </div>

        {transactions.data.length === 0 ? (
          <div className="empty-state">
            <ReceiptText size={36} />

            <h3>
              No transactions yet
            </h3>

            <p>
              Sales, payments, loans and
              other financial activity will
              appear here.
            </p>
          </div>
        ) : (
          <TransactionGrid
            transactions={transactions.data.map(
              (transaction) => ({
                id: transaction.id,
                date: formatDate(
                  transaction.transaction_date,
                ),
                type: formatTransactionType(
                  transaction.type,
                ),
                cashEffect: transactionCashEffect(transaction),
                customer: person.name,
                amount: formatMoney(
                  transaction.amount,
                ),
                account:
                  transaction.account?.name ||
                  'Credit',
                description:
                  transaction.description,
                time: formatTime(transactionTimestamp(transaction)),
              }),
            )}
            personHistory
          />
        )}
      </section>
    </div>
  )
}

function EditPersonForm({
  person,
  onClose,
  onSaved,
}: {
  person: Person
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [name, setName] = useState(person.name)
  const [phone, setPhone] = useState(person.phone ?? '')
  const [address, setAddress] = useState(person.address ?? '')
  const [notes, setNotes] = useState(person.notes ?? '')
  const [role, setRole] = useState(person.roles[0] ?? 'customer')
  const [creditLimit, setCreditLimit] = useState(person.credit_limit != null ? String(person.credit_limit) : '')
  const [isActive, setIsActive] = useState(person.is_active)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')

      const response = await apiFetch(`/api/people/${person.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name,
          phone: phone || null,
          address: address || null,
          notes: notes || null,
          roles: [role],
          is_customer: role === 'customer',
          credit_limit: role === 'customer' && creditLimit ? Number(creditLimit) : null,
          is_active: isActive,
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || 'Unable to update person.')
      }

      await onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to update person.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <div>
            <h2>Edit Person</h2>
            <p className="muted-text">Update {person.name}&apos;s details.</p>
          </div>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner form-error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <div className="form-grid">
            <label>
              <span>Name *</span>
              <input type="text" value={name} onChange={(event) => setName(event.target.value)} required />
            </label>

            <label>
              <span>Role</span>
              <select value={role} onChange={(event) => setRole(event.target.value)}>
                <option value="customer">Customer</option>
                <option value="borrower">Borrower</option>
                <option value="lender">Lender</option>
                <option value="contact">Other contact</option>
              </select>
            </label>

            <label>
              <span>Phone</span>
              <input type="text" value={phone} onChange={(event) => setPhone(event.target.value)} />
            </label>

            <label>
              <span>Status</span>
              <select value={isActive ? '1' : '0'} onChange={(event) => setIsActive(event.target.value === '1')}>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </label>

            {role === 'customer' && (
              <label>
                <span>Credit Limit</span>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={creditLimit}
                  onChange={(event) => setCreditLimit(event.target.value)}
                  placeholder="No limit"
                />
              </label>
            )}

            <label className="form-field-full">
              <span>Address</span>
              <input type="text" value={address} onChange={(event) => setAddress(event.target.value)} />
            </label>

            <label className="form-field-full">
              <span>Notes</span>
              <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} />
            </label>
          </div>

          <div className="modal-actions">
            <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="primary-button" disabled={saving}>
              {saving ? 'Saving...' : 'Update Person'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

type TransactionGridItem = {
  id: number
  date: string
  time: string
  type: string
  cashEffect: number
  customer: string
  amount: string
  account: string
  description: string
  balance?: number | null
  status?: string
}

function TransactionGrid({
  transactions,
  personHistory = false,
  showBalance = false,
}: {
  transactions: TransactionGridItem[]
  personHistory?: boolean
  showBalance?: boolean
}) {
  return (
    <>
      <div
        className={`transaction-grid txn-table ${
          personHistory ? 'transaction-grid-history' : ''
        }`}
      >
        <div className="transaction-grid-header">
          <span>Date</span>
          <span>Time</span>
          <span>Description</span>
          <span>Type</span>
          {!personHistory && <span>Person</span>}
          {showBalance ? <span>Balance</span> : <span>Account</span>}
          <span>Amount</span>
        </div>

        <div className="transaction-grid-body">
          {transactions.map((transaction) => (
            <div
              className={`transaction-grid-row ${
                transaction.status === 'voided' ? 'txn-voided' : ''
              }`}
              key={transaction.id}
            >
              <div className="transaction-grid-cell" data-label="Date">
                {transaction.date}
              </div>
              <div className="transaction-grid-cell" data-label="Time">
                {transaction.time}
              </div>
              <div
                className="transaction-grid-cell description-cell"
                data-label="Description"
              >
                {transaction.description}
              </div>
              <div className="transaction-grid-cell" data-label="Type">
                {transaction.type}
              </div>
              {!personHistory && (
                <div className="transaction-grid-cell" data-label="Person">
                  {transaction.customer}
                </div>
              )}
              {showBalance ? (
                <div
                  className={`transaction-grid-cell amount-cell ${moneyToneClass(
                    transaction.balance ?? 0,
                  )}`}
                  data-label="Balance"
                >
                  <strong>{formatSignedMoney(transaction.balance ?? 0)}</strong>
                </div>
              ) : (
                <div className="transaction-grid-cell" data-label="Account">
                  {transaction.account}
                </div>
              )}
              <div
                className={`transaction-grid-cell amount-cell ${moneyToneClass(
                  transaction.cashEffect,
                )}`}
                data-label="Amount"
              >
                <div className="amount-cell-inner">
                  <Link
                    to={`/transactions/${transaction.id}/receipt`}
                    className="receipt-link"
                    title="View receipt"
                    aria-label="View receipt"
                  >
                    <ReceiptText size={15} />
                  </Link>
                  <strong>{transaction.amount}</strong>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="txn-cards">
        {transactions.map((transaction) => (
          <article
            className={`txn-card ${transaction.status === 'voided' ? 'txn-voided' : ''}`}
            key={`card-${transaction.id}`}
          >
            <dl>
              <div className="kv-row">
                <dt>Date</dt>
                <dd>{transaction.date}</dd>
              </div>
              <div className="kv-row">
                <dt>Time</dt>
                <dd>{transaction.time}</dd>
              </div>
              <div className="kv-row">
                <dt>Type</dt>
                <dd>{transaction.type}</dd>
              </div>
              {!personHistory && (
                <div className="kv-row">
                  <dt>Customer</dt>
                  <dd>{transaction.customer}</dd>
                </div>
              )}
              <div className="kv-row">
                <dt>Amount</dt>
                <dd className={moneyToneClass(transaction.cashEffect)}>
                  {transaction.amount}
                </dd>
              </div>
              {showBalance ? (
                <div className="kv-row">
                  <dt>Balance</dt>
                  <dd className={moneyToneClass(transaction.balance ?? 0)}>
                    {formatSignedMoney(transaction.balance ?? 0)}
                  </dd>
                </div>
              ) : (
                <div className="kv-row">
                  <dt>Account</dt>
                  <dd>{transaction.account}</dd>
                </div>
              )}
              <div className="kv-row">
                <dt>Description</dt>
                <dd>{transaction.description}</dd>
              </div>
              <div className="kv-row">
                <dt>Receipt</dt>
                <dd>
                  <Link to={`/transactions/${transaction.id}/receipt`} className="receipt-link">
                    <ReceiptText size={14} />
                    View receipt
                  </Link>
                </dd>
              </div>
            </dl>
          </article>
        ))}
      </div>
    </>
  )
}

function Sales() {
  const [searchParams] = useSearchParams()
  const [people, setPeople] = useState<Person[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [accounts, setAccounts] =
    useState<Account[]>([])
  const [transactions, setTransactions] =
    useState<Transaction[]>([])

  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] =
    useState(searchParams.get('record') === '1')
  const [error, setError] = useState('')

  const [invoices, setInvoices] = useState<Sale[]>([])
  const [invoicesLoading, setInvoicesLoading] = useState(true)
  const [invoicesError, setInvoicesError] = useState('')
  const [invoicesPage, setInvoicesPage] = useState(1)
  const [invoicesLastPage, setInvoicesLastPage] = useState(1)
  const [invoicesTotal, setInvoicesTotal] = useState(0)

  const [viewingInvoiceId, setViewingInvoiceId] = useState<number | null>(null)

  const [reportCustomer, setReportCustomer] = useState('all')
  const [reportPaymentStatus, setReportPaymentStatus] = useState('all')
  const [reportFrom, setReportFrom] = useState('')
  const [reportTo, setReportTo] = useState('')
  const [downloadingReport, setDownloadingReport] = useState(false)
  const [reportError, setReportError] = useState('')

  async function handleDownloadSalesExcel() {
    setReportError('')
    setDownloadingReport(true)
    try {
      const params = new URLSearchParams()
      if (reportCustomer !== 'all') params.set('customer_id', reportCustomer)
      if (reportPaymentStatus !== 'all') params.set('payment_status', reportPaymentStatus)
      if (reportFrom) params.set('from', reportFrom)
      if (reportTo) params.set('to', reportTo)

      const selectedCustomer = people.find((person) => String(person.id) === reportCustomer)
      const filename = selectedCustomer
        ? `customer-statement-${slugForFilename(selectedCustomer.name)}.xlsx`
        : 'sales-report.xlsx'

      await downloadExcelReport('/api/reports/sales/excel', params, filename)
    } catch (err) {
      setReportError(err instanceof Error ? err.message : 'Unable to generate the Excel file.')
    } finally {
      setDownloadingReport(false)
    }
  }

  function clearReportFilters() {
    setReportCustomer('all')
    setReportPaymentStatus('all')
    setReportFrom('')
    setReportTo('')
  }

  async function loadSalesData() {
    try {
      setLoading(true)
      setError('')

      const [
        peopleResponse,
        productsResponse,
        accountsResponse,
        allTransactions,
      ] = await Promise.all([
        apiFetch('/api/people'),
        apiFetch('/api/products'),
        apiFetch('/api/accounts'),
        fetchAllTransactions(),
      ])

      if (
        !peopleResponse.ok ||
        !productsResponse.ok ||
        !accountsResponse.ok
      ) {
        throw new Error(
          'Unable to load sales data.',
        )
      }

      const peopleData: PeopleResponse =
        await peopleResponse.json()

      const productsData: ProductsResponse =
        await productsResponse.json()

      const accountsData: Account[] =
        await accountsResponse.json()

      setPeople(peopleData.data)
      setProducts(productsData.data)
      setAccounts(accountsData)

      setTransactions(
        allTransactions.filter(
          (transaction) =>
            transaction.type ===
              'cash_sale' ||
            transaction.type ===
              'credit_sale' ||
            transaction.type ===
              'customer_payment',
        ),
      )
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to load sales data.',
      )
    } finally {
      setLoading(false)
    }
  }

  async function loadInvoices(page = 1) {
    try {
      setInvoicesLoading(true)
      setInvoicesError('')

      const response = await apiFetch(`/api/sales?page=${page}`)

      if (!response.ok) {
        throw new Error('Unable to load invoices.')
      }

      const data: SalesResponse = await response.json()

      setInvoices(data.data)
      setInvoicesPage(data.current_page)
      setInvoicesLastPage(data.last_page)
      setInvoicesTotal(data.total)
    } catch (err) {
      setInvoicesError(
        err instanceof Error ? err.message : 'Unable to load invoices.',
      )
    } finally {
      setInvoicesLoading(false)
    }
  }

  useEffect(() => {
    setShowForm(searchParams.get('record') === '1')
  }, [searchParams])

  useEffect(() => {
    void loadSalesData()
  }, [])

  useEffect(() => {
    void loadInvoices(1)
  }, [])

  const gridTransactions: TransactionGridItem[] =
    transactions.map((transaction) => ({
      id: transaction.id,
      date: formatDate(
        transaction.transaction_date,
      ),
      time: formatTime(transactionTimestamp(transaction)),
      type: formatTransactionType(
        transaction.type,
      ),
      cashEffect: transactionCashEffect(transaction),
      customer:
        transaction.person?.name ||
        'Not specified',
      amount: formatMoney(
        transaction.amount,
      ),
      account:
        transaction.account?.name ||
        'Credit',
      description:
        transaction.description,
    }))

  return (
    <div className="page">
      <div className="sales-page-content">
        <PageHeader
          eyebrow="Wholesale"
          title="Sales"
          description="Record cash sales, credit sales and customer payments."
          actions={
            <button
              className="primary-button"
              onClick={() => setShowForm(true)}
            >
              + Record Sale
            </button>
          }
        />

        {error && (
          <div className="error-banner">
            {error}
          </div>
        )}

        {showForm && (
          <SaleForm
            people={people}
            products={products}
            accounts={accounts}
            onClose={() =>
              setShowForm(false)
            }
            onCreated={async (createdSaleId) => {
              setShowForm(false)
              await Promise.all([loadSalesData(), loadInvoices(1)])
              setViewingInvoiceId(createdSaleId)
            }}
          />
        )}

        <section className="panel report-download-panel">
          <div className="panel-header">
            <div>
              <h2>Download Sales Report</h2>
              <p>Get an Excel workbook - a full sales report, or a single customer's statement.</p>
            </div>
          </div>

          <div className="form-grid">
            <label>
              <span>Customer</span>
              <select value={reportCustomer} onChange={(event) => setReportCustomer(event.target.value)}>
                <option value="all">All customers</option>
                {people.filter((person) => person.is_customer).map((person) => (
                  <option value={person.id} key={person.id}>
                    {person.name}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Payment Status</span>
              <select value={reportPaymentStatus} onChange={(event) => setReportPaymentStatus(event.target.value)}>
                <option value="all">All</option>
                <option value="paid">Paid</option>
                <option value="partial">Partial</option>
                <option value="unpaid">Unpaid</option>
              </select>
            </label>

            <label>
              <span>From Date</span>
              <input type="date" value={reportFrom} onChange={(event) => setReportFrom(event.target.value)} />
            </label>

            <label>
              <span>To Date</span>
              <input type="date" value={reportTo} onChange={(event) => setReportTo(event.target.value)} />
            </label>
          </div>

          {reportError && <div className="error-banner">{reportError}</div>}

          <div className="report-download-actions">
            <button type="button" className="secondary-button" onClick={clearReportFilters}>
              Clear Filters
            </button>
            <button
              type="button"
              className="primary-button"
              onClick={() => void handleDownloadSalesExcel()}
              disabled={downloadingReport}
            >
              <Download size={16} />
              {downloadingReport ? 'Preparing Excel...' : 'Download Excel'}
            </button>
          </div>
        </section>

        <section className="panel invoices-panel">
          <div className="panel-header">
            <div>
              <h2>Invoices</h2>

              <p>
                {invoicesLoading
                  ? 'Loading...'
                  : `${invoicesTotal} invoice(s)`}
              </p>
            </div>
          </div>

          {invoicesError && (
            <div className="error-banner">
              {invoicesError}
            </div>
          )}

          {invoicesLoading ? (
            <div className="people-loading">
              Loading invoices...
            </div>
          ) : invoices.length === 0 ? (
            <EmptyState
              icon={<ReceiptText size={32} />}
              title="No invoices yet."
              description="Sales recorded with itemized products will appear here as invoices."
            />
          ) : (
            <>
              <div className="sales-table-wrapper record-table-desktop">
                <table className="sales-table">
                  <thead>
                    <tr>
                      <th>Invoice</th>
                      <th>Date</th>
                      <th>Customer</th>
                      <th>Total</th>
                      <th>Paid</th>
                      <th>Balance</th>
                      <th>Status</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((sale) => {
                      const badge = recordStatusBadge(sale)

                      return (
                        <tr key={sale.id} className={sale.status === 'voided' ? 'txn-voided' : ''}>
                          <td>{sale.invoice_number}</td>
                          <td>{formatDate(sale.sale_date)}</td>
                          <td className="cell-wrap">{sale.customer?.name || 'Walk-in Customer'}</td>
                          <td>{formatMoney(sale.total)}</td>
                          <td className={moneyToneClass(Number(sale.amount_paid))}>
                            {formatMoney(sale.amount_paid)}
                          </td>
                          <td className={moneyToneClass(-Number(sale.balance_due))}>
                            {formatMoney(sale.balance_due)}
                          </td>
                          <td>
                            <span className={`status-badge ${badge.className}`}>
                              {badge.label}
                            </span>
                          </td>
                          <td>
                            <button
                              type="button"
                              className="secondary-button"
                              onClick={() => setViewingInvoiceId(sale.id)}
                            >
                              <Eye size={16} />
                              View Invoice
                            </button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              <div className="record-card-list">
                {invoices.map((sale) => {
                  const badge = recordStatusBadge(sale)

                  return (
                    <div className={`record-card ${sale.status === 'voided' ? 'txn-voided' : ''}`} key={sale.id}>
                      <div className="record-card-header">
                        <strong>{sale.invoice_number}</strong>
                        <span className={`status-badge ${badge.className}`}>
                          {badge.label}
                        </span>
                      </div>
                      <div className="record-card-subhead">
                        <span>{formatDate(sale.sale_date)}</span>
                        <span className="record-card-party">{sale.customer?.name || 'Walk-in Customer'}</span>
                      </div>

                      <div className="record-card-figures">
                        <div className="kv-row">
                          <dt>Total</dt>
                          <dd>{formatMoney(sale.total)}</dd>
                        </div>
                        <div className="kv-row">
                          <dt>Paid</dt>
                          <dd className={moneyToneClass(Number(sale.amount_paid))}>{formatMoney(sale.amount_paid)}</dd>
                        </div>
                        <div className="kv-row">
                          <dt>Balance</dt>
                          <dd className={moneyToneClass(-Number(sale.balance_due))}>{formatMoney(sale.balance_due)}</dd>
                        </div>
                      </div>

                      <button
                        type="button"
                        className="secondary-button record-card-action"
                        onClick={() => setViewingInvoiceId(sale.id)}
                      >
                        <Eye size={16} />
                        View Invoice
                      </button>
                    </div>
                  )
                })}
              </div>

              {invoicesLastPage > 1 && (
                <div className="report-pagination">
                  <button
                    type="button"
                    className="secondary-button"
                    disabled={invoicesLoading || invoicesPage <= 1}
                    onClick={() => void loadInvoices(invoicesPage - 1)}
                  >
                    Previous
                  </button>
                  <span>
                    Page {invoicesPage} of {invoicesLastPage} &middot; {invoicesTotal} total
                  </span>
                  <button
                    type="button"
                    className="secondary-button"
                    disabled={invoicesLoading || invoicesPage >= invoicesLastPage}
                    onClick={() => void loadInvoices(invoicesPage + 1)}
                  >
                    Next
                  </button>
                </div>
              )}
            </>
          )}
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Sales & Payments</h2>

              <p>
                {loading
                  ? 'Loading...'
                  : `${transactions.length} transaction(s)`}
              </p>
            </div>
          </div>

          {loading ? (
            <div className="people-loading">
              Loading sales...
            </div>
          ) : transactions.length === 0 ? (
            <EmptyState
              icon={<ReceiptText size={32} />}
              title="You haven't recorded any sales yet."
              description="Cash sales, credit sales and customer payments will appear here."
              action={
                <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
                  + Add Transaction
                </button>
              }
            />
          ) : (
            <TransactionGrid
              transactions={gridTransactions}
            />
          )}
        </section>
      </div>

      {viewingInvoiceId !== null && (
        <InvoiceModal
          saleId={viewingInvoiceId}
          accounts={accounts}
          onClose={() => setViewingInvoiceId(null)}
          onPaid={() => {
            void loadInvoices(invoicesPage)
            void loadSalesData()
          }}
        />
      )}
    </div>
  )
}

/**
 * Confirmation required before voiding a sale or purchase - shows exactly
 * what's about to happen and requires an explicit confirm click, per "Void
 * Sale"/"Void Purchase" never being a one-click action. The optional reason
 * is recorded on the voided record for audit purposes. Also surfaces the
 * backend's safety-refusal message verbatim (e.g. "would make Cash's
 * balance negative...") when the void is unsafe to auto-apply.
 */
function VoidConfirmDialog({
  title,
  documentLabel,
  description,
  saving,
  error,
  onCancel,
  onConfirm,
}: {
  title: string
  documentLabel: string
  description: string
  saving: boolean
  error: string
  onCancel: () => void
  onConfirm: (reason: string) => void
}) {
  const [reason, setReason] = useState('')

  return (
    <div className="modal-backdrop" onClick={onCancel}>
      <div className="modal-card" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <div>
            <h2>{title}</h2>
            <p className="muted-text">{documentLabel}</p>
          </div>
          <button type="button" className="icon-button" onClick={onCancel} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        <div className="void-confirm-body">
          <p>{description}</p>
          <p>
            <strong>{documentLabel}</strong> will remain in the system marked as voided for audit purposes - it will
            not be deleted, and no longer counts as active in reports.
          </p>

          {error && <div className="error-banner form-error">{error}</div>}

          <label>
            <span>Reason (optional)</span>
            <textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={2}
              placeholder="e.g. Wrong customer selected"
            />
          </label>
        </div>

        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onCancel} disabled={saving}>
            Cancel
          </button>
          <button type="button" className="danger-button" disabled={saving} onClick={() => onConfirm(reason)}>
            {saving ? 'Voiding...' : title}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * Invoice preview for a single sale, shown as an in-page overlay so the
 * user never leaves the Sales page. Toggles a body class for the lifetime
 * of the modal (see the print rules in App.css) so Print / Save as PDF
 * produces only the invoice document, not the rest of the app.
 */
function InvoiceModal({
  saleId,
  accounts,
  onClose,
  onPaid,
}: {
  saleId: number
  accounts: Account[]
  onClose: () => void
  onPaid: () => void
}) {
  const [sale, setSale] = useState<Sale | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentAccountId, setPaymentAccountId] = useState('')
  const [paymentSaving, setPaymentSaving] = useState(false)
  const [paymentError, setPaymentError] = useState('')

  const [voiding, setVoiding] = useState(false)
  const [voidError, setVoidError] = useState('')
  const [showVoidConfirm, setShowVoidConfirm] = useState(false)

  useEffect(() => {
    document.body.classList.add('invoice-print-mode')
    return () => {
      document.body.classList.remove('invoice-print-mode')
    }
  }, [])

  async function loadInvoice() {
    try {
      setLoading(true)
      setError('')

      const response = await apiFetch(`/api/sales/${saleId}`)

      if (!response.ok) {
        throw new Error(
          response.status === 404 ? 'Invoice not found.' : 'Unable to load invoice.',
        )
      }

      const data: Sale = await response.json()
      setSale(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load invoice.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadInvoice()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [saleId])

  async function handleRecordPayment() {
    if (!sale) return

    const amount = Number(paymentAmount)
    if (!amount || amount <= 0) {
      setPaymentError('Enter a payment amount greater than zero.')
      return
    }
    if (amount > Number(sale.balance_due) + 0.005) {
      setPaymentError('Amount cannot exceed the balance due.')
      return
    }
    if (!paymentAccountId) {
      setPaymentError('Select the account the payment is going into.')
      return
    }

    try {
      setPaymentSaving(true)
      setPaymentError('')

      const response = await apiFetch(`/api/sales/${sale.id}/payments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount,
          account_id: Number(paymentAccountId),
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.amount?.[0] || 'Unable to record payment.')
      }

      setPaymentAmount('')
      setPaymentAccountId('')
      await loadInvoice()
      onPaid()
    } catch (err) {
      setPaymentError(err instanceof Error ? err.message : 'Unable to record payment.')
    } finally {
      setPaymentSaving(false)
    }
  }

  async function handleVoid(reason: string) {
    if (!sale) return

    try {
      setVoiding(true)
      setVoidError('')

      const response = await apiFetch(`/api/sales/${sale.id}/void`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: reason || null }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.sale?.[0] || 'Unable to void this sale.')
      }

      setShowVoidConfirm(false)
      await loadInvoice()
      onPaid()
    } catch (err) {
      setVoidError(err instanceof Error ? err.message : 'Unable to void this sale.')
    } finally {
      setVoiding(false)
    }
  }

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  const status = sale ? invoicePaymentStatus(sale) : 'unpaid'

  return (
    <div className="invoice-modal-backdrop" onClick={onClose}>
      <div className="invoice-modal-card" onClick={(event) => event.stopPropagation()}>
        <div className="invoice-actions">
          <button type="button" className="secondary-button" onClick={onClose}>
            <X size={16} />
            Close
          </button>
          <div className="invoice-actions-right">
            {sale && sale.status === 'posted' && (
              <button
                type="button"
                className="danger-button"
                onClick={() => {
                  setVoidError('')
                  setShowVoidConfirm(true)
                }}
              >
                Void Sale
              </button>
            )}
            {sale && (
              <button type="button" className="primary-button" onClick={() => window.print()}>
                <Printer size={16} />
                Print / Save as PDF
              </button>
            )}
          </div>
        </div>

        {showVoidConfirm && sale && (
          <VoidConfirmDialog
            title="Void Sale"
            documentLabel={sale.invoice_number}
            description="This reverses the sale's inventory and financial effects. The sale stays in the system, marked as voided, for audit purposes."
            saving={voiding}
            error={voidError}
            onCancel={() => setShowVoidConfirm(false)}
            onConfirm={(reason) => void handleVoid(reason)}
          />
        )}

        {loading ? (
          <div className="invoice-document panel">
            <div className="people-loading">Loading invoice...</div>
          </div>
        ) : error || !sale ? (
          <div className="invoice-document panel">
            <div className="error-banner">{error || 'Invoice not found.'}</div>
          </div>
        ) : (
          <div className="invoice-document panel">
            <div className="invoice-header">
              <div className="invoice-brand">
                <img src={logoIcon} alt="Gedi Finance" className="brand-mark" />
                <div>
                  <strong>GEDI FINANCE</strong>
                  <span>Wholesale Supplier</span>
                </div>
              </div>

              <div className="invoice-header-meta">
                <div className="invoice-heading">
                  <span className="invoice-heading-kicker">Wholesale Sales</span>
                  <h1>Invoice</h1>
                </div>
                <div className="invoice-meta-row">
                  <span>Invoice No.</span>
                  <strong>{sale.invoice_number}</strong>
                </div>
                <div className="invoice-meta-row">
                  <span>Date</span>
                  <strong>{formatDate(sale.sale_date)}</strong>
                </div>
              </div>
            </div>

            <div className="invoice-bill-to">
              <span>Bill To</span>
              <strong>{sale.customer?.name || 'Walk-in Customer'}</strong>
              {sale.customer?.customer_code && <p>Customer Code: {sale.customer.customer_code}</p>}
              {sale.customer?.phone && <p>{sale.customer.phone}</p>}
              {sale.customer?.address && <p>{sale.customer.address}</p>}
            </div>

            <div className="invoice-divider" />

            <div className="invoice-items-table-wrapper">
              <table className="invoice-items-table invoice-items-table-sales">
                <thead>
                  <tr>
                    <th className="invoice-col-qty">Qty</th>
                    <th>Item</th>
                    <th>Unit</th>
                    <th className="invoice-col-money">Unit Price</th>
                    <th className="invoice-col-money">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {sale.items.map((item) => (
                    <tr key={item.id}>
                      <td className="invoice-col-qty">{item.quantity}</td>
                      <td>{item.product?.name || 'Unknown product'}</td>
                      <td>
                        {item.product_unit?.unit?.abbreviation ||
                          item.product_unit?.unit?.name ||
                          item.product?.base_unit?.abbreviation ||
                          item.product?.base_unit?.name ||
                          '—'}
                      </td>
                      <td className="invoice-col-money">{formatMoney(item.unit_price)}</td>
                      <td className="invoice-col-money">{formatMoney(item.line_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="invoice-totals">
              <div className="invoice-totals-box">
                <div className="invoice-totals-row">
                  <span>Subtotal</span>
                  <strong>{formatMoney(sale.subtotal)}</strong>
                </div>
                <div className="invoice-totals-row">
                  <span>Discount</span>
                  <strong>{formatMoney(sale.discount)}</strong>
                </div>
                <div className="invoice-totals-row invoice-total-row">
                  <span>Total</span>
                  <strong>{formatMoney(sale.total)}</strong>
                </div>
                <div className="invoice-totals-row">
                  <span>Paid</span>
                  <strong className={moneyToneClass(Number(sale.amount_paid))}>
                    {formatMoney(sale.amount_paid)}
                  </strong>
                </div>
                <div className="invoice-totals-row invoice-balance-row">
                  <span>Balance Due</span>
                  <strong className={moneyToneClass(-Number(sale.balance_due))}>
                    {formatMoney(sale.balance_due)}
                  </strong>
                </div>
              </div>
            </div>

            <div className="invoice-status-banner">
              {sale.status === 'voided' && (
                <span className="invoice-status invoice-status-voided">VOIDED</span>
              )}
              <span className={`invoice-status invoice-status-${status}`}>
                {invoiceStatusLabel(status)}
              </span>
            </div>

            {sale.status === 'voided' && (
              <div className="voided-notice">
                <strong>This sale was voided{sale.voided_by ? ` by ${sale.voided_by.name}` : ''}{sale.voided_at ? ` on ${formatDate(sale.voided_at)}` : ''}.</strong>
                {sale.void_reason && <p>Reason: {sale.void_reason}</p>}
                <p className="muted-text">Its inventory and financial effects have been reversed. This record is kept for audit purposes and no longer counts toward active sales.</p>
              </div>
            )}

            {sale.status === 'posted' && Number(sale.balance_due) > 0.005 && (
              <div className="invoice-payment-panel">
                <h3>Record a Customer Payment</h3>
                <p className="muted-text">Reduces the balance due and adds the money to the selected account.</p>
                {paymentError && <div className="error-banner form-error">{paymentError}</div>}
                <div className="invoice-payment-fields">
                  <label>
                    <span>Amount</span>
                    <input
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={sale.balance_due}
                      value={paymentAmount}
                      onChange={(event) => setPaymentAmount(event.target.value)}
                      placeholder={`Up to ${formatMoney(sale.balance_due)}`}
                    />
                  </label>
                  <label>
                    <span>Received Into</span>
                    <select value={paymentAccountId} onChange={(event) => setPaymentAccountId(event.target.value)}>
                      <option value="">Select account</option>
                      {accounts.map((account) => (
                        <option value={account.id} key={account.id}>
                          {account.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <button
                    type="button"
                    className="primary-button"
                    disabled={paymentSaving}
                    onClick={() => void handleRecordPayment()}
                  >
                    {paymentSaving ? 'Recording...' : 'Record Payment'}
                  </button>
                </div>
              </div>
            )}

            <div className="invoice-divider" />

            <p className="invoice-footer">Thank you for your business.</p>
          </div>
        )}
      </div>
    </div>
  )
}

type SaleItemRow = {
  productId: string
  productUnitId: string
  quantity: string
  unitPrice: string
  discount: string
}

function emptySaleItemRow(): SaleItemRow {
  return { productId: '', productUnitId: '', quantity: '', unitPrice: '', discount: '0' }
}

/**
 * Records a real, itemized Sale via POST /api/sales - the same endpoint
 * and backend contract (SaleService) exercised by SaleInvoiceWorkflowTest.
 * This deliberately does NOT post a flat amount to /api/transactions: doing
 * that would create a ledger entry with no Sale/SaleItem rows behind it, so
 * inventory would never be deducted, COGS/gross profit would never be
 * computed, and there would be no invoice_number for the invoice view to
 * show - the customer would only ever see the generic Transaction Receipt.
 */
function SaleForm({
  people,
  products,
  accounts,
  onClose,
  onCreated,
}: {
  people: Person[]
  products: Product[]
  accounts: Account[]
  onClose: () => void
  onCreated: (createdSaleId: number) => Promise<void>
}) {
  const [saleType, setSaleType] = useState<'cash' | 'credit'>('cash')
  const [customerId, setCustomerId] = useState('')
  const [items, setItems] = useState<SaleItemRow[]>([emptySaleItemRow()])
  const [invoiceDiscount, setInvoiceDiscount] = useState('0')
  const [amountPaid, setAmountPaid] = useState('0')
  const [accountId, setAccountId] = useState('')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const customers = people.filter((person) => person.is_customer)

  function updateItem(index: number, patch: Partial<SaleItemRow>) {
    setItems((previous) => previous.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function addItem() {
    setItems((previous) => [...previous, emptySaleItemRow()])
  }

  function removeItem(index: number) {
    setItems((previous) => (previous.length > 1 ? previous.filter((_, i) => i !== index) : previous))
  }

  const subtotal = items.reduce(
    (sum, item) =>
      sum + Math.max(0, (Number(item.quantity) || 0) * (Number(item.unitPrice) || 0) - (Number(item.discount) || 0)),
    0,
  )
  const total = Math.max(0, subtotal - (Number(invoiceDiscount) || 0))

  // A cash sale is always paid in full right now - the customer picker
  // stays optional (walk-in allowed) precisely because there's no balance
  // left over to track. A credit sale can take any deposit from $0 up to
  // the full total; whatever isn't paid now becomes the customer's
  // balance, payable later either in full or a little at a time via the
  // existing "Record a Customer Payment" panel on the invoice.
  const effectiveAmountPaid = saleType === 'cash' ? total : Number(amountPaid) || 0
  const remainingOwed = Math.max(0, total - effectiveAmountPaid)
  const needsAccount = effectiveAmountPaid > 0.005

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const validItems = items.filter((item) => item.productId && item.quantity && item.unitPrice)

    if (validItems.length === 0) {
      setError('Add at least one item with a product, quantity and unit price.')
      return
    }

    if (saleType === 'credit' && !customerId) {
      setError('Select a customer for a credit sale - a walk-in sale must be paid in full as a cash sale.')
      return
    }

    if (needsAccount && !accountId) {
      setError('Select the account the payment is going into.')
      return
    }

    try {
      setSaving(true)
      setError('')

      const payload: Record<string, unknown> = {
        customer_id: customerId ? Number(customerId) : null,
        discount: Number(invoiceDiscount) || 0,
        amount_paid: effectiveAmountPaid,
        notes: notes || null,
        items: validItems.map((item) => ({
          product_id: Number(item.productId),
          product_unit_id: item.productUnitId ? Number(item.productUnitId) : undefined,
          quantity: Number(item.quantity),
          unit_price: Number(item.unitPrice),
          discount: Number(item.discount) || 0,
        })),
      }

      if (needsAccount) {
        payload.account_id = Number(accountId)
      }

      const response = await apiFetch('/api/sales', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      const data = await response.json()

      if (!response.ok) {
        const message =
          data?.errors?.customer_id?.[0] ||
          data?.errors?.items?.[0] ||
          data?.errors?.account_id?.[0] ||
          data?.errors?.amount_paid?.[0] ||
          data?.errors?.discount?.[0] ||
          data?.message ||
          'Unable to record sale.'

        throw new Error(message)
      }

      const createdSaleId = data?.sale?.id
      if (createdSaleId) {
        await onCreated(createdSaleId)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to record sale.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>Record Sale</h2>
          <p>Sell goods to a customer, for cash or on credit.</p>
        </div>
        <button type="button" className="secondary-button" onClick={onClose}>
          Cancel
        </button>
      </div>

      {error && <div className="error-banner form-error">{error}</div>}

      <form className="person-form" onSubmit={handleSubmit}>
        <div className="sale-type-grid">
          <label className={`sale-type-option ${saleType === 'cash' ? 'sale-type-option-active' : ''}`}>
            <input
              type="radio"
              name="saleType"
              value="cash"
              checked={saleType === 'cash'}
              onChange={() => setSaleType('cash')}
            />
            <strong>Cash Sale</strong>
            <span>Customer pays the full amount right now.</span>
          </label>
          <label className={`sale-type-option ${saleType === 'credit' ? 'sale-type-option-active' : ''}`}>
            <input
              type="radio"
              name="saleType"
              value="credit"
              checked={saleType === 'credit'}
              onChange={() => setSaleType('credit')}
            />
            <strong>Credit Sale</strong>
            <span>Customer takes the goods now and pays later - in full or a little at a time.</span>
          </label>
        </div>

        <div className="form-grid">
          <label>
            <span>Customer{saleType === 'credit' ? ' *' : ''}</span>
            <select
              value={customerId}
              onChange={(event) => setCustomerId(event.target.value)}
              required={saleType === 'credit'}
            >
              <option value="">{saleType === 'cash' ? 'Walk-in customer' : 'Select customer'}</option>
              {customers.map((person) => (
                <option value={person.id} key={person.id}>
                  {person.name}
                </option>
              ))}
            </select>
            {saleType === 'credit' && customers.length === 0 && (
              <p className="form-field-hint">
                No customers yet - add one from the Customers page first (mark them as a customer to give them
                credit).
              </p>
            )}
          </label>

          <label>
            <span>Invoice Discount</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={invoiceDiscount}
              onChange={(event) => setInvoiceDiscount(event.target.value)}
            />
          </label>

          {saleType === 'credit' && (
            <label>
              <span>Amount Paid Now (optional)</span>
              <input
                type="number"
                min="0"
                step="0.01"
                max={total}
                value={amountPaid}
                onChange={(event) => setAmountPaid(event.target.value)}
                placeholder="0.00"
              />
              <p className="form-field-hint">
                Leave at $0 to put the whole sale on credit. Whatever isn't paid now becomes {customerId ? "the customer's" : 'their'} balance - they can pay it off in full or in smaller payments later, from the invoice.
              </p>
            </label>
          )}

          {needsAccount && (
            <label>
              <span>Received Into *</span>
              <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
                <option value="">Select account</option>
                {accounts
                  .filter((account) => account.is_active)
                  .map((account) => (
                    <option value={account.id} key={account.id}>
                      {account.name}
                    </option>
                  ))}
              </select>
            </label>
          )}

          {saleType === 'credit' && remainingOwed > 0.005 && (
            <div className="form-field-full sale-credit-preview">
              <span>Customer Will Owe</span>
              <strong className="money-neg">{formatMoney(remainingOwed)}</strong>
            </div>
          )}
        </div>

        <div className="purchase-items-section">
          <div className="purchase-items-header">
            <span>Items</span>
            <button type="button" className="secondary-button" onClick={addItem}>
              + Add Item
            </button>
          </div>

          <div className="purchase-items-table-wrapper">
            <table className="purchase-items-form-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Unit</th>
                  <th>Qty</th>
                  <th>Unit Price</th>
                  <th>Discount</th>
                  <th>Line Total</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {items.map((item, index) => {
                  const product = products.find((candidate) => String(candidate.id) === item.productId)
                  const lineTotal = Math.max(
                    0,
                    (Number(item.quantity) || 0) * (Number(item.unitPrice) || 0) - (Number(item.discount) || 0),
                  )

                  return (
                    <tr key={index}>
                      <td>
                        <select
                          value={item.productId}
                          onChange={(event) => {
                            const selected = products.find((candidate) => String(candidate.id) === event.target.value)
                            updateItem(index, {
                              productId: event.target.value,
                              productUnitId: '',
                              unitPrice:
                                item.unitPrice || (selected?.default_selling_price != null
                                  ? String(selected.default_selling_price)
                                  : ''),
                            })
                          }}
                        >
                          <option value="">Select product</option>
                          {products.map((candidate) => (
                            <option value={candidate.id} key={candidate.id}>
                              {candidate.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <select
                          value={item.productUnitId}
                          onChange={(event) => updateItem(index, { productUnitId: event.target.value })}
                          disabled={!product}
                        >
                          <option value="">{product?.base_unit?.abbreviation || product?.base_unit?.name || 'Base unit'}</option>
                          {product?.units?.map((productUnit) => (
                            <option value={productUnit.id} key={productUnit.id}>
                              {productUnit.unit?.abbreviation || productUnit.unit?.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0.0001"
                          step="0.0001"
                          value={item.quantity}
                          onChange={(event) => updateItem(index, { quantity: event.target.value })}
                          placeholder="0"
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={item.unitPrice}
                          onChange={(event) => updateItem(index, { unitPrice: event.target.value })}
                          placeholder="0.00"
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={item.discount}
                          onChange={(event) => updateItem(index, { discount: event.target.value })}
                          placeholder="0.00"
                        />
                      </td>
                      <td>{formatMoney(lineTotal)}</td>
                      <td>
                        <button
                          type="button"
                          className="icon-button"
                          onClick={() => removeItem(index)}
                          disabled={items.length === 1}
                          aria-label="Remove item"
                        >
                          <X size={16} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="purchase-items-summary">
            <div>
              <span>Subtotal</span>
              <strong>{formatMoney(subtotal)}</strong>
            </div>
            <div>
              <span>Discount</span>
              <strong>{formatMoney(Number(invoiceDiscount) || 0)}</strong>
            </div>
            <div className="purchase-items-total">
              <span>Total</span>
              <strong>{formatMoney(total)}</strong>
            </div>
          </div>
        </div>

        <div className="form-grid">
          <label className="form-field-full">
            <span>Notes</span>
            <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} placeholder="optional" />
          </label>
        </div>

        <div className="form-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Saving...' : 'Save Sale'}
          </button>
        </div>
      </form>
    </section>
  )
}

function AddPersonForm({
  onClose,
  onCreated,
}: {
  onClose: () => void
  onCreated: () => Promise<void>
}) {
  const [name, setName] =
    useState('')

  const [phone, setPhone] =
    useState('')

  const [address, setAddress] =
    useState('')

  const [notes, setNotes] =
    useState('')

  const [role, setRole] =
    useState('customer')

  const [creditLimit, setCreditLimit] = useState('')

  const [saving, setSaving] =
    useState(false)

  const [error, setError] =
    useState('')

  async function handleSubmit(
    event: FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')

      const response = await apiFetch(
        '/api/people',
        {
          method: 'POST',
          headers: {
            'Content-Type':
              'application/json',
            Accept:
              'application/json',
          },
          body: JSON.stringify({
            name,
            phone: phone || null,
            address:
              address || null,
            notes: notes || null,
            roles: [role],
            // Distinct from `roles` (a cosmetic tag) - this is the actual
            // flag that lets this person be selected as a sale's customer.
            is_customer: role === 'customer',
            credit_limit:
              role === 'customer' && creditLimit
                ? Number(creditLimit)
                : null,
          }),
        },
      )

      const data =
        await response.json()

      if (!response.ok) {
        const message =
          data?.message ||
          data?.errors
            ?.name?.[0] ||
          'Unable to create person.'

        throw new Error(message)
      }

      await onCreated()
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to create person.',
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>Add Person</h2>

          <p>
            Add a customer or another
            business contact.
          </p>
        </div>

        <button
          type="button"
          className="secondary-button"
          onClick={onClose}
        >
          Cancel
        </button>
      </div>

      {error && (
        <div className="error-banner form-error">
          {error}
        </div>
      )}

      <form
        className="person-form"
        onSubmit={handleSubmit}
      >
        <div className="form-grid">
          <label>
            <span>Name *</span>

            <input
              type="text"
              value={name}
              onChange={(event) =>
                setName(
                  event.target.value,
                )
              }
              placeholder="e.g. Ahmed"
              required
            />
          </label>

          <label>
            <span>Phone</span>

            <input
              type="tel"
              value={phone}
              onChange={(event) =>
                setPhone(
                  event.target.value,
                )
              }
              placeholder="+252..."
            />
          </label>

          <label>
            <span>Role</span>

            <select
              value={role}
              onChange={(event) =>
                setRole(
                  event.target.value,
                )
              }
            >
              <option value="customer">
                Customer
              </option>

              <option value="supplier">
                Supplier
              </option>

              <option value="borrower">
                Borrower
              </option>

              <option value="lender">
                Lender
              </option>

              <option value="other">
                Other
              </option>
            </select>
          </label>

          {role === 'customer' && (
            <label>
              <span>Credit Limit</span>

              <input
                type="number"
                min="0"
                step="0.01"
                value={creditLimit}
                onChange={(event) => setCreditLimit(event.target.value)}
                placeholder="No limit"
              />
            </label>
          )}

          <label>
            <span>Address</span>

            <input
              type="text"
              value={address}
              onChange={(event) =>
                setAddress(
                  event.target.value,
                )
              }
              placeholder="e.g. Mogadishu"
            />
          </label>

          <label className="form-field-full">
            <span>Notes</span>

            <textarea
              value={notes}
              onChange={(event) =>
                setNotes(
                  event.target.value,
                )
              }
              placeholder="Additional information"
              rows={3}
            />
          </label>
        </div>

        <div className="form-actions">
          <button
            type="button"
            className="secondary-button"
            onClick={onClose}
            disabled={saving}
          >
            Cancel
          </button>

          <button
            type="submit"
            className="primary-button"
            disabled={saving}
          >
            {saving
              ? 'Saving...'
              : 'Save Person'}
          </button>
        </div>
      </form>
    </section>
  )
}

function Suppliers() {
  const [suppliers, setSuppliers] = useState<Supplier[]>([])
  const [owedToSupplier, setOwedToSupplier] = useState<Map<number, string>>(new Map())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null)

  async function loadSuppliers() {
    try {
      setLoading(true)
      setError('')

      const [suppliersResponse, payablesResponse] = await Promise.all([
        apiFetch('/api/suppliers'),
        apiFetch('/api/reports/supplier-payables'),
      ])

      if (!suppliersResponse.ok) {
        throw new Error('Unable to load suppliers.')
      }

      const data: SuppliersResponse = await suppliersResponse.json()
      setSuppliers(data.data)

      if (payablesResponse.ok) {
        const payablesData: { data: ReceivablePayableRow[] } = await payablesResponse.json()
        setOwedToSupplier(new Map(payablesData.data.map((row) => [row.id, row.balance])))
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load suppliers.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadSuppliers()
  }, [])

  return (
    <div className="page">
      <PageHeader
        eyebrow="Wholesale"
        title="Suppliers"
        description="Who we buy from - and how much we owe them."
        actions={
          <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
            + Add Supplier
          </button>
        }
      />

      {error && <div className="error-banner">{error}</div>}

      {showForm && (
        <AddSupplierForm
          onClose={() => setShowForm(false)}
          onCreated={async () => {
            setShowForm(false)
            await loadSuppliers()
          }}
        />
      )}

      {editingSupplier && (
        <AddSupplierForm
          supplier={editingSupplier}
          onClose={() => setEditingSupplier(null)}
          onCreated={async () => {
            setEditingSupplier(null)
            await loadSuppliers()
          }}
        />
      )}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Suppliers</h2>
            <p>{loading ? 'Loading...' : `${suppliers.length} supplier(s)`}</p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading suppliers...</div>
        ) : suppliers.length === 0 ? (
          <EmptyState
            icon={<Truck size={32} />}
            title="No suppliers yet"
            description="Add a supplier before recording a purchase."
            action={
              <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
                + Add Supplier
              </button>
            }
          />
        ) : (
          <>
            <div className="sales-table-wrapper record-table-desktop">
              <table className="sales-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>We Owe Them</th>
                    <th>Credit Limit</th>
                    <th>Terms</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {suppliers.map((supplier) => {
                    const owed = owedToSupplier.get(supplier.id) ?? null

                    return (
                    <tr key={supplier.id}>
                      <td>{supplier.supplier_code}</td>
                      <td>{supplier.name}</td>
                      <td>{supplier.phone || '—'}</td>
                      <td className={owed != null && Number(owed) > 0.005 ? 'money-neg' : ''}>
                        {owed != null && Number(owed) > 0.005 ? formatMoney(owed) : '—'}
                      </td>
                      <td>{supplier.credit_limit != null ? formatMoney(supplier.credit_limit) : 'No limit'}</td>
                      <td>{supplier.payment_terms_days != null ? `${supplier.payment_terms_days} days` : '—'}</td>
                      <td>
                        <button
                          type="button"
                          className="icon-button"
                          onClick={() => setEditingSupplier(supplier)}
                          aria-label={`Edit ${supplier.name}`}
                        >
                          <Pencil size={16} />
                        </button>
                      </td>
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="record-card-list">
              {suppliers.map((supplier) => {
                const owed = owedToSupplier.get(supplier.id) ?? null
                const weOweThem = owed != null && Number(owed) > 0.005

                return (
                <div className="record-card" key={supplier.id}>
                  <div className="record-card-header">
                    <strong>{supplier.name}</strong>
                    <button
                      type="button"
                      className="icon-button"
                      onClick={() => setEditingSupplier(supplier)}
                      aria-label={`Edit ${supplier.name}`}
                    >
                      <Pencil size={16} />
                    </button>
                  </div>
                  <div className="record-card-subhead">
                    <span>{supplier.supplier_code}</span>
                    {supplier.phone && <span>{supplier.phone}</span>}
                  </div>

                  {weOweThem && owed != null && (
                    <p className="person-card-owed">
                      We Owe Them <strong className="money-neg">{formatMoney(owed)}</strong>
                    </p>
                  )}

                  <div className="record-card-figures">
                    {supplier.email && (
                      <div className="kv-row">
                        <dt>Email</dt>
                        <dd className="cell-wrap">{supplier.email}</dd>
                      </div>
                    )}
                    <div className="kv-row">
                      <dt>Credit Limit</dt>
                      <dd>{supplier.credit_limit != null ? formatMoney(supplier.credit_limit) : 'No limit'}</dd>
                    </div>
                    <div className="kv-row">
                      <dt>Terms</dt>
                      <dd>{supplier.payment_terms_days != null ? `${supplier.payment_terms_days} days` : '—'}</dd>
                    </div>
                  </div>
                </div>
                )
              })}
            </div>
          </>
        )}
      </section>
    </div>
  )
}

function AddSupplierForm({
  supplier,
  onClose,
  onCreated,
}: {
  supplier?: Supplier
  onClose: () => void
  onCreated: () => Promise<void>
}) {
  const isEditing = Boolean(supplier)
  const [name, setName] = useState(supplier?.name ?? '')
  const [phone, setPhone] = useState(supplier?.phone ?? '')
  const [email, setEmail] = useState(supplier?.email ?? '')
  const [address, setAddress] = useState(supplier?.address ?? '')
  const [creditLimit, setCreditLimit] = useState(supplier?.credit_limit != null ? String(supplier.credit_limit) : '')
  const [paymentTermsDays, setPaymentTermsDays] = useState(
    supplier?.payment_terms_days != null ? String(supplier.payment_terms_days) : '',
  )
  const [notes, setNotes] = useState(supplier?.notes ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')

      const payload = {
        name,
        phone: phone || null,
        email: email || null,
        address: address || null,
        credit_limit: creditLimit ? Number(creditLimit) : null,
        payment_terms_days: paymentTermsDays ? Number(paymentTermsDays) : null,
        notes: notes || null,
      }

      const response = await apiFetch(
        isEditing ? `/api/suppliers/${supplier!.id}` : '/api/suppliers',
        {
          method: isEditing ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        },
      )

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || `Unable to ${isEditing ? 'update' : 'create'} supplier.`)
      }

      await onCreated()
    } catch (err) {
      setError(err instanceof Error ? err.message : `Unable to ${isEditing ? 'update' : 'create'} supplier.`)
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>{isEditing ? 'Edit Supplier' : 'Add Supplier'}</h2>
          <p>{isEditing ? `Update details for ${supplier!.name}.` : 'Add a vendor you purchase stock from.'}</p>
        </div>
        <button type="button" className="secondary-button" onClick={onClose}>
          Cancel
        </button>
      </div>

      {error && <div className="error-banner form-error">{error}</div>}

      <form className="person-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label>
            <span>Name *</span>
            <input
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. Bakaaro Wholesalers"
              required
            />
          </label>

          <label>
            <span>Phone</span>
            <input type="text" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="+252 ..." />
          </label>

          <label>
            <span>Email</span>
            <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="optional" />
          </label>

          <label>
            <span>Credit Limit</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={creditLimit}
              onChange={(event) => setCreditLimit(event.target.value)}
              placeholder="No limit"
            />
          </label>

          <label>
            <span>Payment Terms (days)</span>
            <input
              type="number"
              min="0"
              step="1"
              value={paymentTermsDays}
              onChange={(event) => setPaymentTermsDays(event.target.value)}
              placeholder="e.g. 30"
            />
          </label>

          <label className="form-field-full">
            <span>Address</span>
            <input type="text" value={address} onChange={(event) => setAddress(event.target.value)} placeholder="optional" />
          </label>

          <label className="form-field-full">
            <span>Notes</span>
            <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} placeholder="optional" />
          </label>
        </div>

        <div className="form-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Saving...' : isEditing ? 'Update Supplier' : 'Save Supplier'}
          </button>
        </div>
      </form>
    </section>
  )
}

function Products() {
  const [products, setProducts] = useState<Product[]>([])
  const [categories, setCategories] = useState<ProductCategory[]>([])
  const [units, setUnits] = useState<Unit[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [addingUnitFor, setAddingUnitFor] = useState<Product | null>(null)
  const [editingProduct, setEditingProduct] = useState<Product | null>(null)

  async function loadAll() {
    try {
      setLoading(true)
      setError('')

      const [productsResponse, categoriesResponse, unitsResponse] = await Promise.all([
        apiFetch('/api/products'),
        apiFetch('/api/product-categories'),
        apiFetch('/api/units'),
      ])

      if (!productsResponse.ok || !categoriesResponse.ok || !unitsResponse.ok) {
        throw new Error('Unable to load products.')
      }

      const productsData: ProductsResponse = await productsResponse.json()
      const categoriesData: ProductCategory[] = await categoriesResponse.json()
      const unitsData: Unit[] = await unitsResponse.json()

      setProducts(productsData.data)
      setCategories(categoriesData)
      setUnits(unitsData)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load products.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadAll()
  }, [])

  return (
    <div className="page">
      <PageHeader
        eyebrow="Wholesale"
        title="Products"
        description="Your catalog of items you buy and sell, with units and pricing."
        actions={
          <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
            + Add Product
          </button>
        }
      />

      {error && <div className="error-banner">{error}</div>}

      {showForm && (
        <AddProductForm
          categories={categories}
          units={units}
          onClose={() => setShowForm(false)}
          onCreated={async () => {
            setShowForm(false)
            await loadAll()
          }}
          onCategoryCreated={(category) =>
            setCategories((previous) => [...previous, category].sort((a, b) => a.name.localeCompare(b.name)))
          }
          onUnitCreated={(unit) =>
            setUnits((previous) => [...previous, unit].sort((a, b) => a.name.localeCompare(b.name)))
          }
        />
      )}

      {editingProduct && (
        <AddProductForm
          product={editingProduct}
          categories={categories}
          units={units}
          onClose={() => setEditingProduct(null)}
          onCreated={async () => {
            setEditingProduct(null)
            await loadAll()
          }}
          onCategoryCreated={(category) =>
            setCategories((previous) => [...previous, category].sort((a, b) => a.name.localeCompare(b.name)))
          }
          onUnitCreated={(unit) =>
            setUnits((previous) => [...previous, unit].sort((a, b) => a.name.localeCompare(b.name)))
          }
        />
      )}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Product Catalog</h2>
            <p>{loading ? 'Loading...' : `${products.length} product(s)`}</p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading products...</div>
        ) : products.length === 0 ? (
          <EmptyState
            icon={<Package size={32} />}
            title="No products yet"
            description="Add your first product to start recording purchases and sales."
            action={
              <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
                + Add Product
              </button>
            }
          />
        ) : (
          <div className="sales-table-wrapper">
            <table className="sales-table">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Sellable Unit</th>
                  <th>Cost</th>
                  <th>Selling Price</th>
                  <th>Alt. Units</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {products.map((product) => (
                  <tr key={product.id}>
                    <td>{product.sku}</td>
                    <td>{product.name}</td>
                    <td>{product.category?.name || '—'}</td>
                    <td>{product.base_unit?.abbreviation || product.base_unit?.name || '—'}</td>
                    <td>{product.default_cost_price != null ? formatMoney(product.default_cost_price) : '—'}</td>
                    <td>{product.default_selling_price != null ? formatMoney(product.default_selling_price) : '—'}</td>
                    <td>
                      {product.units && product.units.length > 0
                        ? product.units
                            .map(
                              (productUnit) =>
                                `${productUnit.conversion_factor} ${product.base_unit?.abbreviation || ''} / ${productUnit.unit?.abbreviation || productUnit.unit?.name}`,
                            )
                            .join(', ')
                        : '—'}
                    </td>
                    <td>
                      <div className="table-row-actions">
                        <button
                          type="button"
                          className="secondary-button"
                          onClick={() => setAddingUnitFor(product)}
                        >
                          + Unit
                        </button>
                        <button
                          type="button"
                          className="icon-button"
                          onClick={() => setEditingProduct(product)}
                          aria-label={`Edit ${product.name}`}
                        >
                          <Pencil size={16} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {addingUnitFor && (
        <AddProductUnitModal
          product={addingUnitFor}
          units={units}
          onClose={() => setAddingUnitFor(null)}
          onCreated={async () => {
            setAddingUnitFor(null)
            await loadAll()
          }}
          onUnitCreated={(unit) =>
            setUnits((previous) => [...previous, unit].sort((a, b) => a.name.localeCompare(b.name)))
          }
        />
      )}
    </div>
  )
}

function AddProductForm({
  product,
  categories,
  units,
  onClose,
  onCreated,
  onCategoryCreated,
  onUnitCreated,
}: {
  product?: Product
  categories: ProductCategory[]
  units: Unit[]
  onClose: () => void
  onCreated: () => Promise<void>
  onCategoryCreated: (category: ProductCategory) => void
  onUnitCreated: (unit: Unit) => void
}) {
  const isEditing = Boolean(product)
  const [name, setName] = useState(product?.name ?? '')
  const [sku, setSku] = useState(product?.sku ?? '')
  const [categoryId, setCategoryId] = useState(product?.category?.id != null ? String(product.category.id) : '')
  const [baseUnitId, setBaseUnitId] = useState(product?.base_unit?.id != null ? String(product.base_unit.id) : '')
  const [costPrice, setCostPrice] = useState(product?.default_cost_price != null ? String(product.default_cost_price) : '')
  const [sellingPrice, setSellingPrice] = useState(
    product?.default_selling_price != null ? String(product.default_selling_price) : '',
  )
  const [wholesalePrice, setWholesalePrice] = useState(
    product?.default_wholesale_price != null ? String(product.default_wholesale_price) : '',
  )
  const [minimumStock, setMinimumStock] = useState(
    product?.minimum_stock != null ? String(product.minimum_stock) : '',
  )
  const [notes, setNotes] = useState(product?.notes ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const [showNewCategory, setShowNewCategory] = useState(false)
  const [newCategoryName, setNewCategoryName] = useState('')
  const [savingCategory, setSavingCategory] = useState(false)

  const [showNewUnit, setShowNewUnit] = useState(false)
  const [newUnitName, setNewUnitName] = useState('')
  const [newUnitAbbreviation, setNewUnitAbbreviation] = useState('')
  const [savingUnit, setSavingUnit] = useState(false)

  // Gedi Finance sells wholesale grains by the bag, not the individual
  // piece - default new products to "Bag" (seeded out of the box) instead
  // of leaving the sellable unit blank, so bag reads as the natural choice
  // rather than something the user has to remember to pick.
  useEffect(() => {
    if (baseUnitId) return
    const bag = units.find((unit) => unit.name.toLowerCase() === 'bag')
    if (bag) setBaseUnitId(String(bag.id))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [units])

  const selectedUnit = units.find((unit) => String(unit.id) === baseUnitId)
  const priceUnitLabel = selectedUnit ? ` (per ${selectedUnit.abbreviation || selectedUnit.name})` : ''

  async function handleCreateCategory() {
    if (!newCategoryName.trim()) return

    try {
      setSavingCategory(true)

      const response = await apiFetch('/api/product-categories', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: newCategoryName.trim() }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || 'Unable to create category.')
      }

      onCategoryCreated(data.category)
      setCategoryId(String(data.category.id))
      setNewCategoryName('')
      setShowNewCategory(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to create category.')
    } finally {
      setSavingCategory(false)
    }
  }

  async function handleCreateUnit() {
    if (!newUnitName.trim() || !newUnitAbbreviation.trim()) return

    try {
      setSavingUnit(true)

      const response = await apiFetch('/api/units', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: newUnitName.trim(), abbreviation: newUnitAbbreviation.trim() }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || data?.errors?.abbreviation?.[0] || 'Unable to create unit.')
      }

      onUnitCreated(data.unit)
      setBaseUnitId(String(data.unit.id))
      setNewUnitName('')
      setNewUnitAbbreviation('')
      setShowNewUnit(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to create unit.')
    } finally {
      setSavingUnit(false)
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (!baseUnitId) {
      setError('Select or add a base unit first.')
      return
    }

    try {
      setSaving(true)
      setError('')

      // base_unit_id is only sent on create - the backend ignores it on
      // update anyway (see ProductController@update), since existing
      // inventory movements were already recorded against the current unit.
      const payload: Record<string, unknown> = {
        name,
        sku: sku || null,
        category_id: categoryId ? Number(categoryId) : null,
        default_cost_price: costPrice ? Number(costPrice) : null,
        default_selling_price: sellingPrice ? Number(sellingPrice) : null,
        default_wholesale_price: wholesalePrice ? Number(wholesalePrice) : null,
        minimum_stock: minimumStock ? Number(minimumStock) : null,
        notes: notes || null,
      }
      if (!isEditing) {
        payload.base_unit_id = Number(baseUnitId)
      }

      const response = await apiFetch(
        isEditing ? `/api/products/${product!.id}` : '/api/products',
        {
          method: isEditing ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        },
      )

      const data = await response.json()

      if (!response.ok) {
        throw new Error(
          data?.message ||
            data?.errors?.name?.[0] ||
            data?.errors?.base_unit_id?.[0] ||
            data?.errors?.sku?.[0] ||
            `Unable to ${isEditing ? 'update' : 'create'} product.`,
        )
      }

      await onCreated()
    } catch (err) {
      setError(err instanceof Error ? err.message : `Unable to ${isEditing ? 'update' : 'create'} product.`)
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>{isEditing ? 'Edit Product' : 'Add Product'}</h2>
          <p>
            {isEditing
              ? `Update details for ${product!.name}.`
              : 'Most wholesale goods are sold by the bag - set the sellable unit and price per unit.'}
          </p>
        </div>
        <button type="button" className="secondary-button" onClick={onClose}>
          Cancel
        </button>
      </div>

      {error && <div className="error-banner form-error">{error}</div>}

      <form className="person-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label>
            <span>Name *</span>
            <input
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. Rice 25kg"
              required
            />
          </label>

          <label>
            <span>SKU</span>
            <input
              type="text"
              value={sku}
              onChange={(event) => setSku(event.target.value)}
              placeholder="Auto-generated if left blank"
            />
          </label>

          <label>
            <span>Category</span>
            <select value={categoryId} onChange={(event) => setCategoryId(event.target.value)}>
              <option value="">No category</option>
              {categories.map((category) => (
                <option value={category.id} key={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
            <button type="button" className="link-button" onClick={() => setShowNewCategory((open) => !open)}>
              {showNewCategory ? 'Cancel' : '+ New category'}
            </button>
            {showNewCategory && (
              <div className="inline-add-row">
                <input
                  type="text"
                  value={newCategoryName}
                  onChange={(event) => setNewCategoryName(event.target.value)}
                  placeholder="Category name"
                />
                <button
                  type="button"
                  className="secondary-button"
                  disabled={savingCategory}
                  onClick={() => void handleCreateCategory()}
                >
                  {savingCategory ? 'Adding...' : 'Add'}
                </button>
              </div>
            )}
          </label>

          <label>
            <span>Sellable Unit *</span>
            <select
              value={baseUnitId}
              onChange={(event) => setBaseUnitId(event.target.value)}
              disabled={isEditing}
              required
            >
              <option value="">Select unit</option>
              {units.map((unit) => (
                <option value={unit.id} key={unit.id}>
                  {unit.name} ({unit.abbreviation})
                </option>
              ))}
            </select>
            <p className="form-field-hint">
              {isEditing
                ? "The sellable unit can't be changed once a product has stock history - create a new product instead if you need a different one."
                : 'How this product is normally sold, e.g. Bag. If you stock more than one bag size, give each its own product (Rice 25kg, Rice 50kg) rather than switching units here.'}
            </p>
            {!isEditing && (
              <button type="button" className="link-button" onClick={() => setShowNewUnit((open) => !open)}>
                {showNewUnit ? 'Cancel' : '+ New unit'}
              </button>
            )}
            {showNewUnit && (
              <div className="inline-add-row">
                <input
                  type="text"
                  value={newUnitName}
                  onChange={(event) => setNewUnitName(event.target.value)}
                  placeholder="e.g. Piece"
                />
                <input
                  type="text"
                  value={newUnitAbbreviation}
                  onChange={(event) => setNewUnitAbbreviation(event.target.value)}
                  placeholder="e.g. pc"
                />
                <button
                  type="button"
                  className="secondary-button"
                  disabled={savingUnit}
                  onClick={() => void handleCreateUnit()}
                >
                  {savingUnit ? 'Adding...' : 'Add'}
                </button>
              </div>
            )}
          </label>

          <label>
            <span>Cost Price{priceUnitLabel}</span>
            <input type="number" min="0" step="0.01" value={costPrice} onChange={(event) => setCostPrice(event.target.value)} placeholder="0.00" />
          </label>

          <label>
            <span>Selling Price{priceUnitLabel}</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={sellingPrice}
              onChange={(event) => setSellingPrice(event.target.value)}
              placeholder="0.00"
            />
          </label>

          <label>
            <span>Wholesale Price{priceUnitLabel}</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={wholesalePrice}
              onChange={(event) => setWholesalePrice(event.target.value)}
              placeholder="0.00"
            />
          </label>

          <label>
            <span>Minimum Stock</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={minimumStock}
              onChange={(event) => setMinimumStock(event.target.value)}
              placeholder="Low-stock alert level"
            />
          </label>

          <label className="form-field-full">
            <span>Notes</span>
            <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} placeholder="optional" />
          </label>
        </div>

        <div className="form-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Saving...' : isEditing ? 'Update Product' : 'Save Product'}
          </button>
        </div>
      </form>
    </section>
  )
}

function AddProductUnitModal({
  product,
  units,
  onClose,
  onCreated,
  onUnitCreated,
}: {
  product: Product
  units: Unit[]
  onClose: () => void
  onCreated: () => Promise<void>
  onUnitCreated: (unit: Unit) => void
}) {
  const [unitId, setUnitId] = useState('')
  const [conversionFactor, setConversionFactor] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const [showNewUnit, setShowNewUnit] = useState(false)
  const [newUnitName, setNewUnitName] = useState('')
  const [newUnitAbbreviation, setNewUnitAbbreviation] = useState('')
  const [savingUnit, setSavingUnit] = useState(false)

  const availableUnits = units.filter((unit) => unit.id !== product.base_unit_id)

  async function handleCreateUnit() {
    if (!newUnitName.trim() || !newUnitAbbreviation.trim()) return

    try {
      setSavingUnit(true)

      const response = await apiFetch('/api/units', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: newUnitName.trim(), abbreviation: newUnitAbbreviation.trim() }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || 'Unable to create unit.')
      }

      onUnitCreated(data.unit)
      setUnitId(String(data.unit.id))
      setNewUnitName('')
      setNewUnitAbbreviation('')
      setShowNewUnit(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to create unit.')
    } finally {
      setSavingUnit(false)
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')

      const response = await apiFetch(`/api/products/${product.id}/units`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          unit_id: Number(unitId),
          conversion_factor: Number(conversionFactor),
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(
          data?.message ||
            data?.errors?.unit_id?.[0] ||
            data?.errors?.conversion_factor?.[0] ||
            'Unable to add unit.',
        )
      }

      await onCreated()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to add unit.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card add-unit-modal" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <div>
            <h2>Add Unit</h2>
            <p className="muted-text">
              How many {product.base_unit?.abbreviation || product.base_unit?.name || 'base units'} make up one of
              the new unit, for {product.name}.
            </p>
          </div>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner form-error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <div className="form-grid">
            <label>
              <div className="field-header">
                <span>Unit *</span>
              </div>
              <select value={unitId} onChange={(event) => setUnitId(event.target.value)} required>
                <option value="">Select unit</option>
                {availableUnits.map((unit) => (
                  <option value={unit.id} key={unit.id}>
                    {unit.name} ({unit.abbreviation})
                  </option>
                ))}
              </select>
              <button type="button" className="link-button" onClick={() => setShowNewUnit((open) => !open)}>
                {showNewUnit ? 'Cancel' : '+ New unit'}
              </button>
              {showNewUnit && (
                <div className="inline-add-row">
                  <input
                    type="text"
                    value={newUnitName}
                    onChange={(event) => setNewUnitName(event.target.value)}
                    placeholder="e.g. Carton"
                  />
                  <input
                    type="text"
                    value={newUnitAbbreviation}
                    onChange={(event) => setNewUnitAbbreviation(event.target.value)}
                    placeholder="e.g. ctn"
                  />
                  <button
                    type="button"
                    className="secondary-button"
                    disabled={savingUnit}
                    onClick={() => void handleCreateUnit()}
                  >
                    {savingUnit ? 'Adding...' : 'Add'}
                  </button>
                </div>
              )}
            </label>

            <label>
              <div className="field-header">
                <span>Conversion Factor *</span>
                <small className="field-hint">(base units per 1 of this unit)</small>
              </div>
              <input
                type="number"
                min="0.0001"
                step="0.0001"
                value={conversionFactor}
                onChange={(event) => setConversionFactor(event.target.value)}
                placeholder={`e.g. 10 (${product.base_unit?.abbreviation || 'base units'} per unit)`}
                required
              />
            </label>
          </div>

          <div className="modal-actions">
            <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="primary-button" disabled={saving}>
              {saving ? 'Saving...' : 'Add Unit'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

type PurchaseItemRow = {
  productId: string
  productUnitId: string
  quantity: string
  unitCost: string
}

function emptyPurchaseItemRow(): PurchaseItemRow {
  return { productId: '', productUnitId: '', quantity: '', unitCost: '' }
}

function Purchases() {
  const [searchParams] = useSearchParams()
  const [purchases, setPurchases] = useState<Purchase[]>([])
  const [suppliers, setSuppliers] = useState<Supplier[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [showForm, setShowForm] = useState(searchParams.get('record') === '1')
  const [viewingPurchaseId, setViewingPurchaseId] = useState<number | null>(null)

  const [reportSupplier, setReportSupplier] = useState('all')
  const [reportPaymentStatus, setReportPaymentStatus] = useState('all')
  const [reportFrom, setReportFrom] = useState('')
  const [reportTo, setReportTo] = useState('')
  const [downloadingReport, setDownloadingReport] = useState(false)
  const [reportError, setReportError] = useState('')

  async function handleDownloadPurchasesExcel() {
    setReportError('')
    setDownloadingReport(true)
    try {
      const params = new URLSearchParams()
      if (reportSupplier !== 'all') params.set('supplier_id', reportSupplier)
      if (reportPaymentStatus !== 'all') params.set('payment_status', reportPaymentStatus)
      if (reportFrom) params.set('from', reportFrom)
      if (reportTo) params.set('to', reportTo)

      const selectedSupplier = suppliers.find((supplier) => String(supplier.id) === reportSupplier)
      const filename = selectedSupplier
        ? `supplier-statement-${slugForFilename(selectedSupplier.name)}.xlsx`
        : 'purchases-report.xlsx'

      await downloadExcelReport('/api/reports/purchases/excel', params, filename)
    } catch (err) {
      setReportError(err instanceof Error ? err.message : 'Unable to generate the Excel file.')
    } finally {
      setDownloadingReport(false)
    }
  }

  function clearReportFilters() {
    setReportSupplier('all')
    setReportPaymentStatus('all')
    setReportFrom('')
    setReportTo('')
  }

  async function loadPurchases(pageToLoad = 1) {
    try {
      setLoading(true)
      setError('')

      const response = await apiFetch(`/api/purchases?page=${pageToLoad}`)

      if (!response.ok) {
        throw new Error('Unable to load purchases.')
      }

      const data: PurchasesResponse = await response.json()

      setPurchases(data.data)
      setPage(data.current_page)
      setLastPage(data.last_page)
      setTotal(data.total)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load purchases.')
    } finally {
      setLoading(false)
    }
  }

  async function loadFormData() {
    try {
      const [suppliersResponse, productsResponse, accountsResponse] = await Promise.all([
        apiFetch('/api/suppliers'),
        apiFetch('/api/products'),
        apiFetch('/api/accounts'),
      ])

      if (suppliersResponse.ok) {
        const data: SuppliersResponse = await suppliersResponse.json()
        setSuppliers(data.data)
      }

      if (productsResponse.ok) {
        const data: ProductsResponse = await productsResponse.json()
        setProducts(data.data)
      }

      if (accountsResponse.ok) {
        const data: Account[] = await accountsResponse.json()
        setAccounts(data)
      }
    } catch {
      // Non-fatal: the purchases list itself already loaded independently;
      // the record-purchase form will just show empty dropdowns if this
      // fails, rather than blocking the whole page.
    }
  }

  useEffect(() => {
    void loadPurchases(1)
  }, [])

  useEffect(() => {
    void loadFormData()
  }, [])

  return (
    <div className="page">
      <div className="sales-page-content">
        <PageHeader
          eyebrow="Wholesale"
          title="Purchases"
          description="Stock received from suppliers."
          actions={
            <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
              + Record Purchase
            </button>
          }
        />

        {error && <div className="error-banner">{error}</div>}

        {showForm && (
          <PurchaseForm
            suppliers={suppliers}
            products={products}
            accounts={accounts}
            onClose={() => setShowForm(false)}
            onCreated={async () => {
              setShowForm(false)
              await loadPurchases(1)
            }}
          />
        )}

        <section className="panel report-download-panel">
          <div className="panel-header">
            <div>
              <h2>Download Purchase Report</h2>
              <p>Get an Excel workbook - a full purchase report, or a single supplier's statement.</p>
            </div>
          </div>

          <div className="form-grid">
            <label>
              <span>Supplier</span>
              <select value={reportSupplier} onChange={(event) => setReportSupplier(event.target.value)}>
                <option value="all">All suppliers</option>
                {suppliers.map((supplier) => (
                  <option value={supplier.id} key={supplier.id}>
                    {supplier.name}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Payment Status</span>
              <select value={reportPaymentStatus} onChange={(event) => setReportPaymentStatus(event.target.value)}>
                <option value="all">All</option>
                <option value="paid">Paid</option>
                <option value="partial">Partial</option>
                <option value="unpaid">Unpaid</option>
              </select>
            </label>

            <label>
              <span>From Date</span>
              <input type="date" value={reportFrom} onChange={(event) => setReportFrom(event.target.value)} />
            </label>

            <label>
              <span>To Date</span>
              <input type="date" value={reportTo} onChange={(event) => setReportTo(event.target.value)} />
            </label>
          </div>

          {reportError && <div className="error-banner">{reportError}</div>}

          <div className="report-download-actions">
            <button type="button" className="secondary-button" onClick={clearReportFilters}>
              Clear Filters
            </button>
            <button
              type="button"
              className="primary-button"
              onClick={() => void handleDownloadPurchasesExcel()}
              disabled={downloadingReport}
            >
              <Download size={16} />
              {downloadingReport ? 'Preparing Excel...' : 'Download Excel'}
            </button>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Purchases</h2>
              <p>{loading ? 'Loading...' : `${total} purchase(s)`}</p>
            </div>
          </div>

          {loading ? (
            <div className="people-loading">Loading purchases...</div>
          ) : purchases.length === 0 ? (
            <EmptyState
              icon={<Truck size={32} />}
              title="No purchases yet"
              description="Record stock received from a supplier to see it here."
              action={
                <button className="primary-button" type="button" onClick={() => setShowForm(true)}>
                  + Record Purchase
                </button>
              }
            />
          ) : (
            <>
              <div className="sales-table-wrapper record-table-desktop">
                <table className="sales-table">
                  <thead>
                    <tr>
                      <th>Purchase #</th>
                      <th>Date</th>
                      <th>Supplier</th>
                      <th>Total</th>
                      <th>Paid</th>
                      <th>Balance</th>
                      <th>Status</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {purchases.map((purchase) => {
                      const badge = recordStatusBadge(purchase)

                      return (
                        <tr key={purchase.id} className={purchase.status === 'voided' ? 'txn-voided' : ''}>
                          <td>{purchase.purchase_number}</td>
                          <td>{formatDate(purchase.purchase_date)}</td>
                          <td className="cell-wrap">{purchase.supplier?.name || 'Unknown supplier'}</td>
                          <td>{formatMoney(purchase.total)}</td>
                          <td className={moneyToneClass(Number(purchase.amount_paid))}>
                            {formatMoney(purchase.amount_paid)}
                          </td>
                          <td className={moneyToneClass(-Number(purchase.balance_due))}>
                            {formatMoney(purchase.balance_due)}
                          </td>
                          <td>
                            <span className={`status-badge ${badge.className}`}>
                              {badge.label}
                            </span>
                          </td>
                          <td>
                            <button
                              type="button"
                              className="secondary-button"
                              onClick={() => setViewingPurchaseId(purchase.id)}
                            >
                              <Eye size={16} />
                              View
                            </button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              <div className="record-card-list">
                {purchases.map((purchase) => {
                  const badge = recordStatusBadge(purchase)

                  return (
                    <div className={`record-card ${purchase.status === 'voided' ? 'txn-voided' : ''}`} key={purchase.id}>
                      <div className="record-card-header">
                        <strong>{purchase.purchase_number}</strong>
                        <span className={`status-badge ${badge.className}`}>
                          {badge.label}
                        </span>
                      </div>
                      <div className="record-card-subhead">
                        <span>{formatDate(purchase.purchase_date)}</span>
                        <span className="record-card-party">{purchase.supplier?.name || 'Unknown supplier'}</span>
                      </div>

                      <div className="record-card-figures">
                        <div className="kv-row">
                          <dt>Total</dt>
                          <dd>{formatMoney(purchase.total)}</dd>
                        </div>
                        <div className="kv-row">
                          <dt>Paid</dt>
                          <dd className={moneyToneClass(Number(purchase.amount_paid))}>{formatMoney(purchase.amount_paid)}</dd>
                        </div>
                        <div className="kv-row">
                          <dt>Balance</dt>
                          <dd className={moneyToneClass(-Number(purchase.balance_due))}>{formatMoney(purchase.balance_due)}</dd>
                        </div>
                      </div>

                      <button
                        type="button"
                        className="secondary-button record-card-action"
                        onClick={() => setViewingPurchaseId(purchase.id)}
                      >
                        <Eye size={16} />
                        View Purchase
                      </button>
                    </div>
                  )
                })}
              </div>

              {lastPage > 1 && (
                <div className="report-pagination">
                  <button
                    type="button"
                    className="secondary-button"
                    disabled={loading || page <= 1}
                    onClick={() => void loadPurchases(page - 1)}
                  >
                    Previous
                  </button>
                  <span>
                    Page {page} of {lastPage} &middot; {total} total
                  </span>
                  <button
                    type="button"
                    className="secondary-button"
                    disabled={loading || page >= lastPage}
                    onClick={() => void loadPurchases(page + 1)}
                  >
                    Next
                  </button>
                </div>
              )}
            </>
          )}
        </section>
      </div>

      {viewingPurchaseId !== null && (
        <PurchaseDetailModal
          purchaseId={viewingPurchaseId}
          accounts={accounts}
          onClose={() => setViewingPurchaseId(null)}
          onPaid={() => void loadPurchases(page)}
        />
      )}
    </div>
  )
}

function PurchaseForm({
  suppliers,
  products,
  accounts,
  onClose,
  onCreated,
}: {
  suppliers: Supplier[]
  products: Product[]
  accounts: Account[]
  onClose: () => void
  onCreated: () => Promise<void>
}) {
  const [supplierId, setSupplierId] = useState('')
  const [items, setItems] = useState<PurchaseItemRow[]>([emptyPurchaseItemRow()])
  const [discount, setDiscount] = useState('0')
  const [amountPaid, setAmountPaid] = useState('0')
  const [accountId, setAccountId] = useState('')
  const [notes, setNotes] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  function updateItem(index: number, patch: Partial<PurchaseItemRow>) {
    setItems((previous) => previous.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function addItem() {
    setItems((previous) => [...previous, emptyPurchaseItemRow()])
  }

  function removeItem(index: number) {
    setItems((previous) => (previous.length > 1 ? previous.filter((_, i) => i !== index) : previous))
  }

  const subtotal = items.reduce(
    (sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unitCost) || 0),
    0,
  )
  const total = Math.max(0, subtotal - (Number(discount) || 0))
  const needsAccount = Number(amountPaid) > 0

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const validItems = items.filter((item) => item.productId && item.quantity && item.unitCost)

    if (!supplierId) {
      setError('Select a supplier.')
      return
    }

    if (validItems.length === 0) {
      setError('Add at least one item with a product, quantity and unit cost.')
      return
    }

    if (needsAccount && !accountId) {
      setError('Select the account the payment came from.')
      return
    }

    try {
      setSaving(true)
      setError('')

      const payload: Record<string, unknown> = {
        supplier_id: Number(supplierId),
        discount: Number(discount) || 0,
        amount_paid: Number(amountPaid) || 0,
        notes: notes || null,
        items: validItems.map((item) => ({
          product_id: Number(item.productId),
          product_unit_id: item.productUnitId ? Number(item.productUnitId) : undefined,
          quantity: Number(item.quantity),
          unit_cost: Number(item.unitCost),
        })),
      }

      if (needsAccount) {
        payload.account_id = Number(accountId)
      }

      const response = await apiFetch('/api/purchases', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      const data = await response.json()

      if (!response.ok) {
        const message =
          data?.errors?.supplier_id?.[0] ||
          data?.errors?.items?.[0] ||
          data?.errors?.account_id?.[0] ||
          data?.errors?.amount_paid?.[0] ||
          data?.message ||
          'Unable to record purchase.'

        throw new Error(message)
      }

      await onCreated()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to record purchase.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>Record Purchase</h2>
          <p>Receive stock from a supplier and update inventory.</p>
        </div>
        <button type="button" className="secondary-button" onClick={onClose}>
          Cancel
        </button>
      </div>

      {error && <div className="error-banner form-error">{error}</div>}

      <form className="person-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <label>
            <span>Supplier *</span>
            <select value={supplierId} onChange={(event) => setSupplierId(event.target.value)} required>
              <option value="">Select supplier</option>
              {suppliers.map((supplier) => (
                <option value={supplier.id} key={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Invoice Discount</span>
            <input type="number" min="0" step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} />
          </label>

          <label>
            <span>Amount Paid Now</span>
            <input
              type="number"
              min="0"
              step="0.01"
              value={amountPaid}
              onChange={(event) => setAmountPaid(event.target.value)}
            />
          </label>

          {needsAccount && (
            <label>
              <span>Paid From Account *</span>
              <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
                <option value="">Select account</option>
                {accounts
                  .filter((account) => account.is_active)
                  .map((account) => (
                    <option value={account.id} key={account.id}>
                      {account.name}
                    </option>
                  ))}
              </select>
            </label>
          )}
        </div>

        <div className="purchase-items-section">
          <div className="purchase-items-header">
            <span>Items</span>
            <button type="button" className="secondary-button" onClick={addItem}>
              + Add Item
            </button>
          </div>

          <div className="purchase-items-table-wrapper">
            <table className="purchase-items-form-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Unit</th>
                  <th>Qty</th>
                  <th>Unit Cost</th>
                  <th>Line Total</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {items.map((item, index) => {
                  const product = products.find((candidate) => String(candidate.id) === item.productId)
                  const lineTotal = (Number(item.quantity) || 0) * (Number(item.unitCost) || 0)

                  return (
                    <tr key={index}>
                      <td>
                        <select
                          value={item.productId}
                          onChange={(event) => updateItem(index, { productId: event.target.value, productUnitId: '' })}
                        >
                          <option value="">Select product</option>
                          {products.map((candidate) => (
                            <option value={candidate.id} key={candidate.id}>
                              {candidate.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <select
                          value={item.productUnitId}
                          onChange={(event) => updateItem(index, { productUnitId: event.target.value })}
                          disabled={!product}
                        >
                          <option value="">{product?.base_unit?.abbreviation || product?.base_unit?.name || 'Base unit'}</option>
                          {product?.units?.map((productUnit) => (
                            <option value={productUnit.id} key={productUnit.id}>
                              {productUnit.unit?.abbreviation || productUnit.unit?.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0.0001"
                          step="0.0001"
                          value={item.quantity}
                          onChange={(event) => updateItem(index, { quantity: event.target.value })}
                          placeholder="0"
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={item.unitCost}
                          onChange={(event) => updateItem(index, { unitCost: event.target.value })}
                          placeholder="0.00"
                        />
                      </td>
                      <td>{formatMoney(lineTotal)}</td>
                      <td>
                        <button
                          type="button"
                          className="icon-button"
                          onClick={() => removeItem(index)}
                          disabled={items.length === 1}
                          aria-label="Remove item"
                        >
                          <X size={16} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="purchase-items-summary">
            <div>
              <span>Subtotal</span>
              <strong>{formatMoney(subtotal)}</strong>
            </div>
            <div>
              <span>Discount</span>
              <strong>{formatMoney(Number(discount) || 0)}</strong>
            </div>
            <div className="purchase-items-total">
              <span>Total</span>
              <strong>{formatMoney(total)}</strong>
            </div>
          </div>
        </div>

        <div className="form-grid">
          <label className="form-field-full">
            <span>Notes</span>
            <textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} placeholder="optional" />
          </label>
        </div>

        <div className="form-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Saving...' : 'Save Purchase'}
          </button>
        </div>
      </form>
    </section>
  )
}

/**
 * Print/preview for a single purchase, mirroring InvoiceModal's structure
 * and reusing the same .invoice-* CSS and invoice-print-mode body class -
 * the two are never open at once, so sharing the print styling avoids
 * duplicating an entire near-identical stylesheet for what is visually the
 * same kind of document (branded header, party details, itemized table,
 * totals, status).
 */
function PurchaseDetailModal({
  purchaseId,
  accounts,
  onClose,
  onPaid,
}: {
  purchaseId: number
  accounts: Account[]
  onClose: () => void
  onPaid: () => void
}) {
  const [purchase, setPurchase] = useState<Purchase | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentAccountId, setPaymentAccountId] = useState('')
  const [paymentSaving, setPaymentSaving] = useState(false)
  const [paymentError, setPaymentError] = useState('')

  const [voiding, setVoiding] = useState(false)
  const [voidError, setVoidError] = useState('')
  const [showVoidConfirm, setShowVoidConfirm] = useState(false)

  useEffect(() => {
    document.body.classList.add('invoice-print-mode')
    return () => {
      document.body.classList.remove('invoice-print-mode')
    }
  }, [])

  async function loadPurchase() {
    try {
      setLoading(true)
      setError('')

      const response = await apiFetch(`/api/purchases/${purchaseId}`)

      if (!response.ok) {
        throw new Error(response.status === 404 ? 'Purchase not found.' : 'Unable to load purchase.')
      }

      const data: Purchase = await response.json()
      setPurchase(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load purchase.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadPurchase()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [purchaseId])

  async function handleRecordPayment() {
    if (!purchase) return

    const amount = Number(paymentAmount)
    if (!amount || amount <= 0) {
      setPaymentError('Enter a payment amount greater than zero.')
      return
    }
    if (amount > Number(purchase.balance_due) + 0.005) {
      setPaymentError('Amount cannot exceed the balance due.')
      return
    }
    if (!paymentAccountId) {
      setPaymentError('Select the account the payment is coming from.')
      return
    }

    try {
      setPaymentSaving(true)
      setPaymentError('')

      const response = await apiFetch(`/api/purchases/${purchase.id}/payments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount,
          account_id: Number(paymentAccountId),
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.amount?.[0] || 'Unable to record payment.')
      }

      setPaymentAmount('')
      setPaymentAccountId('')
      await loadPurchase()
      onPaid()
    } catch (err) {
      setPaymentError(err instanceof Error ? err.message : 'Unable to record payment.')
    } finally {
      setPaymentSaving(false)
    }
  }

  async function handleVoid(reason: string) {
    if (!purchase) return

    try {
      setVoiding(true)
      setVoidError('')

      const response = await apiFetch(`/api/purchases/${purchase.id}/void`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: reason || null }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.purchase?.[0] || 'Unable to void this purchase.')
      }

      setShowVoidConfirm(false)
      await loadPurchase()
      onPaid()
    } catch (err) {
      setVoidError(err instanceof Error ? err.message : 'Unable to void this purchase.')
    } finally {
      setVoiding(false)
    }
  }

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  const status = purchase ? invoicePaymentStatus(purchase) : 'unpaid'

  return (
    <div className="invoice-modal-backdrop" onClick={onClose}>
      <div className="invoice-modal-card" onClick={(event) => event.stopPropagation()}>
        <div className="invoice-actions">
          <button type="button" className="secondary-button" onClick={onClose}>
            <X size={16} />
            Close
          </button>
          <div className="invoice-actions-right">
            {purchase && purchase.status === 'posted' && (
              <button
                type="button"
                className="danger-button"
                onClick={() => {
                  setVoidError('')
                  setShowVoidConfirm(true)
                }}
              >
                Void Purchase
              </button>
            )}
            {purchase && (
              <button type="button" className="primary-button" onClick={() => window.print()}>
                <Printer size={16} />
                Print / Save as PDF
              </button>
            )}
          </div>
        </div>

        {showVoidConfirm && purchase && (
          <VoidConfirmDialog
            title="Void Purchase"
            documentLabel={purchase.purchase_number}
            description="This reverses the purchase's inventory and financial effects. The purchase stays in the system, marked as voided, for audit purposes."
            saving={voiding}
            error={voidError}
            onCancel={() => setShowVoidConfirm(false)}
            onConfirm={(reason) => void handleVoid(reason)}
          />
        )}

        {loading ? (
          <div className="invoice-document panel">
            <div className="people-loading">Loading purchase...</div>
          </div>
        ) : error || !purchase ? (
          <div className="invoice-document panel">
            <div className="error-banner">{error || 'Purchase not found.'}</div>
          </div>
        ) : (
          <div className="invoice-document panel">
            <div className="invoice-header">
              <div className="invoice-brand">
                <img src={logoIcon} alt="Gedi Finance" className="brand-mark" />
                <div>
                  <strong>GEDI FINANCE</strong>
                  <span>Purchase Record</span>
                </div>
              </div>

              <div className="invoice-header-meta">
                <span>Purchase No.</span>
                <strong>{purchase.purchase_number}</strong>
                <small>Date: {formatDate(purchase.purchase_date)}</small>
              </div>
            </div>

            <div className="invoice-bill-to">
              <span>Supplier</span>
              <strong>{purchase.supplier?.name || 'Unknown supplier'}</strong>
              {purchase.supplier?.phone && <p>{purchase.supplier.phone}</p>}
              {purchase.supplier?.address && <p>{purchase.supplier.address}</p>}
            </div>

            <div className="invoice-divider" />

            <div className="invoice-items-table-wrapper">
              <table className="invoice-items-table">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Unit</th>
                    <th>Qty</th>
                    <th>Unit Cost</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {purchase.items.map((item) => (
                    <tr key={item.id}>
                      <td>{item.product?.name || 'Unknown product'}</td>
                      <td>
                        {item.product_unit?.unit?.abbreviation ||
                          item.product_unit?.unit?.name ||
                          item.product?.base_unit?.abbreviation ||
                          item.product?.base_unit?.name ||
                          '—'}
                      </td>
                      <td>{item.quantity}</td>
                      <td>{formatMoney(item.unit_cost)}</td>
                      <td>{formatMoney(item.line_total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="invoice-totals">
              <div className="invoice-totals-box">
                <div className="invoice-totals-row">
                  <span>Subtotal</span>
                  <strong>{formatMoney(purchase.subtotal)}</strong>
                </div>
                <div className="invoice-totals-row">
                  <span>Discount</span>
                  <strong>{formatMoney(purchase.discount)}</strong>
                </div>
                <div className="invoice-totals-row invoice-total-row">
                  <span>Total</span>
                  <strong>{formatMoney(purchase.total)}</strong>
                </div>
                <div className="invoice-totals-row">
                  <span>Paid</span>
                  <strong className={moneyToneClass(Number(purchase.amount_paid))}>
                    {formatMoney(purchase.amount_paid)}
                  </strong>
                </div>
                <div className="invoice-totals-row invoice-balance-row">
                  <span>Balance Due</span>
                  <strong className={moneyToneClass(-Number(purchase.balance_due))}>
                    {formatMoney(purchase.balance_due)}
                  </strong>
                </div>
              </div>
            </div>

            <div className="invoice-status-banner">
              {purchase.status === 'voided' && (
                <span className="invoice-status invoice-status-voided">VOIDED</span>
              )}
              <span className={`invoice-status invoice-status-${status}`}>{invoiceStatusLabel(status)}</span>
            </div>

            {purchase.status === 'voided' && (
              <div className="voided-notice">
                <strong>This purchase was voided{purchase.voided_by ? ` by ${purchase.voided_by.name}` : ''}{purchase.voided_at ? ` on ${formatDate(purchase.voided_at)}` : ''}.</strong>
                {purchase.void_reason && <p>Reason: {purchase.void_reason}</p>}
                <p className="muted-text">Its inventory and financial effects have been reversed. This record is kept for audit purposes and no longer counts toward active purchases.</p>
              </div>
            )}

            {purchase.status === 'posted' && Number(purchase.balance_due) > 0.005 && (
              <div className="invoice-payment-panel">
                <h3>Record a Supplier Payment</h3>
                <p className="muted-text">Reduces the balance due and deducts the money from the selected account.</p>
                {paymentError && <div className="error-banner form-error">{paymentError}</div>}
                <div className="invoice-payment-fields">
                  <label>
                    <span>Amount</span>
                    <input
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={purchase.balance_due}
                      value={paymentAmount}
                      onChange={(event) => setPaymentAmount(event.target.value)}
                      placeholder={`Up to ${formatMoney(purchase.balance_due)}`}
                    />
                  </label>
                  <label>
                    <span>Paid From</span>
                    <select value={paymentAccountId} onChange={(event) => setPaymentAccountId(event.target.value)}>
                      <option value="">Select account</option>
                      {accounts.map((account) => (
                        <option value={account.id} key={account.id}>
                          {account.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <button
                    type="button"
                    className="primary-button"
                    disabled={paymentSaving}
                    onClick={() => void handleRecordPayment()}
                  >
                    {paymentSaving ? 'Recording...' : 'Record Payment'}
                  </button>
                </div>
              </div>
            )}

            <div className="invoice-divider" />

            <p className="invoice-footer">Recorded via Gedi Finance.</p>
          </div>
        )}
      </div>
    </div>
  )
}

function Transactions() {
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Default (no person selected) state for the "People" summary card -
  // the same customer_outstanding figure the Dashboard shows, not a
  // second calculation.
  const [totalReceivable, setTotalReceivable] = useState<string | null>(null)

  // Once a specific person is selected (via the search suggestions or the
  // Customer/Person filter dropdown), the card shows THEIR real balance -
  // fetched from GET /api/people/{id}, the same server-computed
  // BalanceService::personBalance() value PersonDetail uses. Never derived
  // from the local transactions list or a transaction count.
  const [selectedPerson, setSelectedPerson] = useState<Person | null>(null)
  const [selectedPersonBalance, setSelectedPersonBalance] = useState<string | null>(null)
  const [selectedPersonLoading, setSelectedPersonLoading] = useState(false)

  const [search, setSearch] = useState('')
  // The input stays instant; only the (more expensive) filter/grid
  // re-render is debounced, so typing never feels laggy even once the
  // ledger has thousands of rows loaded client-side.
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [suggestionsOpen, setSuggestionsOpen] = useState(false)
  const [typeFilter, setTypeFilter] = useState('all')
  const [personFilter, setPersonFilter] = useState('all')
  const [accountFilter, setAccountFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [downloadingExcel, setDownloadingExcel] = useState(false)
  const [excelError, setExcelError] = useState('')

  useEffect(() => {
    const timeout = setTimeout(() => setDebouncedSearch(search), 200)
    return () => clearTimeout(timeout)
  }, [search])
  const searchInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    async function loadSelectedPersonBalance() {
      if (personFilter === 'all') {
        setSelectedPerson(null)
        setSelectedPersonBalance(null)
        return
      }

      try {
        setSelectedPersonLoading(true)
        const response = await apiFetch(`/api/people/${personFilter}`)
        if (!response.ok) throw new Error('Unable to load balance.')
        const data: { person: Person; balance: string } = await response.json()
        setSelectedPerson(data.person)
        setSelectedPersonBalance(data.balance)
      } catch {
        setSelectedPerson(null)
        setSelectedPersonBalance(null)
      } finally {
        setSelectedPersonLoading(false)
      }
    }

    void loadSelectedPersonBalance()
  }, [personFilter])

  async function loadTransactions() {
    try {
      setLoading(true)
      setError('')

      const [allTransactions, peopleResponse, accountsResponse, dashboardResponse] =
        await Promise.all([
          fetchAllTransactions(),
          apiFetch('/api/people'),
          apiFetch('/api/accounts'),
          apiFetch('/api/dashboard'),
        ])

      if (!peopleResponse.ok || !accountsResponse.ok) {
        throw new Error('Unable to load transaction data.')
      }

      const peopleData: PeopleResponse = await peopleResponse.json()
      const accountsData: Account[] = await accountsResponse.json()

      setTransactions(allTransactions)
      setPeople(peopleData.data)
      setAccounts(accountsData)

      if (dashboardResponse.ok) {
        const dashboardData: DashboardData = await dashboardResponse.json()
        setTotalReceivable(dashboardData.receivables.customer_outstanding)
      }
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to load transaction data.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadTransactions()
  }, [])

  const transactionTypes = Array.from(
    new Set(transactions.map((transaction) => transaction.type)),
  ).sort()

  const normalizedSearch = debouncedSearch.trim().toLowerCase()

  const filteredTransactions = transactions.filter((transaction) => {
    const customer = transaction.person?.name ?? ''
    const supplier = transaction.supplier?.name ?? ''
    const account = transaction.account?.name ?? ''
    const type = formatTransactionType(transaction.type)
    const transactionNumber = transaction.transaction_number ?? ''
    // Invoice/purchase numbers live in `reference`, not `transaction_number`
    // (a separate internal ledger id) - both need to be searchable so
    // "search for an invoice number" and "search for a reference number"
    // both actually work.
    const reference = transaction.reference ?? ''
    const haystack =
      `${transactionNumber} ${reference} ${transaction.description} ${customer} ${supplier} ${account} ${transaction.type} ${type}`.toLowerCase()
    const transactionDate = transaction.transaction_date.slice(0, 10)

    if (normalizedSearch && !haystack.includes(normalizedSearch)) {
      return false
    }

    if (typeFilter !== 'all' && transaction.type !== typeFilter) {
      return false
    }

    if (
      personFilter !== 'all' &&
      String(transaction.person?.id ?? '') !== personFilter
    ) {
      return false
    }

    if (
      accountFilter !== 'all' &&
      String(transaction.account?.id ?? '') !== accountFilter
    ) {
      return false
    }

    if (statusFilter !== 'all' && transaction.status !== statusFilter) {
      return false
    }

    if (fromDate && transactionDate < fromDate) {
      return false
    }

    if (toDate && transactionDate > toDate) {
      return false
    }

    return true
  })

  const filteredTotal = filteredTransactions.reduce(
    (total, transaction) => total + Number(transaction.amount),
    0,
  )

  const selectedPersonBalanceNumber = selectedPersonBalance != null ? Number(selectedPersonBalance) : 0
  const accountsTotal = accounts.reduce((sum, account) => sum + Number(account.current_balance), 0)

  function clearFilters() {
    setSearch('')
    setDebouncedSearch('')
    setSuggestionsOpen(false)
    setTypeFilter('all')
    setPersonFilter('all')
    setAccountFilter('all')
    setStatusFilter('all')
    setFromDate('')
    setToDate('')
  }

  async function handleDownloadExcel() {
    setExcelError('')
    setDownloadingExcel(true)
    try {
      const params = new URLSearchParams()
      if (personFilter !== 'all') params.set('person_id', personFilter)
      if (typeFilter !== 'all') params.set('type', typeFilter)
      if (accountFilter !== 'all') params.set('account_id', accountFilter)
      if (statusFilter !== 'all') params.set('status', statusFilter)
      if (fromDate) params.set('from', fromDate)
      if (toDate) params.set('to', toDate)
      if (debouncedSearch.trim()) params.set('search', debouncedSearch.trim())

      const filename =
        personFilter !== 'all' && selectedPerson
          ? `customer-statement-${slugForFilename(selectedPerson.name)}.xlsx`
          : 'transactions-report.xlsx'

      await downloadExcelReport('/api/reports/transactions/excel', params, filename)
    } catch (err) {
      setExcelError(err instanceof Error ? err.message : 'Unable to generate the Excel file.')
    } finally {
      setDownloadingExcel(false)
    }
  }

  const matchingPeopleSuggestions = (() => {
    const term = search.trim().toLowerCase()
    if (!term) return []
    return people.filter((person) => person.name.toLowerCase().includes(term)).slice(0, 6)
  })()

  const balances = personBalanceMap(transactions)

  const gridTransactions: TransactionGridItem[] = filteredTransactions.map(
    (transaction) => ({
      id: transaction.id,
      date: formatDate(transaction.transaction_date),
      time: formatTime(transactionTimestamp(transaction)),
      type: formatTransactionType(transaction.type),
      cashEffect: transactionCashEffect(transaction),
      customer: transaction.person?.name || 'Unassigned',
      amount: formatMoney(transaction.amount),
      account: transaction.account?.name || 'Credit',
      balance: transaction.person?.id ? balances.get(transaction.person.id) ?? 0 : null,
      description: transaction.description,
      status: transaction.status,
    }),
  )

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <p className="eyebrow">History</p>
          <h1>Transaction History</h1>
          <p className="muted">
            The complete record of business activity, for audit. Sales, purchases, payments and loans are recorded
            from their own pages.
          </p>
        </div>

        <div className="page-header-actions transaction-page-actions">
          <button
            type="button"
            className="primary-button"
            onClick={() => void handleDownloadExcel()}
            disabled={downloadingExcel}
          >
            <Download size={16} />
            {downloadingExcel ? 'Preparing Excel...' : 'Download Excel'}
          </button>

          <button
            type="button"
            className="icon-button"
            onClick={() => void loadTransactions()}
            disabled={loading}
            aria-label={loading ? 'Refreshing...' : 'Refresh'}
            title="Refresh"
          >
            <RefreshCw size={17} />
          </button>
        </div>
      </div>

      {excelError && <div className="error-banner">{excelError}</div>}

      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid">
        <div className="stat-card">
          <div className="stat-icon">
            <ReceiptText size={20} />
          </div>
          <span>Transactions</span>
          <strong>{loading ? 'Loading...' : filteredTransactions.length}</strong>
          <small>Matching current filters</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <CreditCard size={20} />
          </div>
          <span>Transaction Value</span>
          <strong className={moneyToneClass(filteredTotal)}>
            {loading ? 'Loading...' : formatMoney(filteredTotal)}
          </strong>
          <small>Gross value of filtered entries</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <Users size={20} />
          </div>
          {personFilter !== 'all' ? (
            <>
              <span>{selectedPersonLoading ? 'Loading...' : selectedPerson?.name || 'Customer'}</span>
              <strong
                className={
                  selectedPersonBalanceNumber > 0.005
                    ? 'money-neg'
                    : selectedPersonBalanceNumber < -0.005
                      ? 'money-pos'
                      : ''
                }
              >
                {selectedPersonLoading ? '...' : formatMoney(Math.abs(selectedPersonBalanceNumber))}
              </strong>
              <small>
                {selectedPersonLoading
                  ? ' '
                  : selectedPersonBalanceNumber > 0.005
                    ? 'Owes us'
                    : selectedPersonBalanceNumber < -0.005
                      ? 'We owe them'
                      : 'Paid in full'}
              </small>
            </>
          ) : (
            <>
              <span>Customers</span>
              <strong className="money-neg">
                {loading || totalReceivable == null ? '...' : formatMoney(totalReceivable)}
              </strong>
              <small>Total owed to us</small>
            </>
          )}
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <Wallet size={20} />
          </div>
          <span>Money Available</span>
          <strong className={moneyToneClass(accountsTotal)}>
            {loading ? '...' : formatMoney(accountsTotal)}
          </strong>
          <small>Across {accounts.length} account{accounts.length === 1 ? '' : 's'}</small>
        </div>
      </div>

      <section className="panel transaction-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Filter Transactions</h2>
            <p>Find a specific entry without changing the ledger.</p>
          </div>

          <button
            type="button"
            className="secondary-button"
            onClick={clearFilters}
          >
            Clear Filters
          </button>
        </div>

        <div className="form-grid">
          <label className="transaction-search-field">
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                ref={searchInputRef}
                type="search"
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value)
                  setSuggestionsOpen(true)
                }}
                onFocus={() => setSuggestionsOpen(true)}
                onBlur={() => {
                  // Delay so a suggestion's onClick still registers before
                  // the dropdown unmounts.
                  window.setTimeout(() => setSuggestionsOpen(false), 150)
                }}
                placeholder="Search by customer, invoice, reference, description..."
                autoComplete="off"
              />
              {search && (
                <button
                  type="button"
                  className="search-clear-button"
                  aria-label="Clear search"
                  onClick={() => {
                    setSearch('')
                    setDebouncedSearch('')
                    setSuggestionsOpen(false)
                  }}
                >
                  <X size={15} />
                </button>
              )}
            </div>

            {suggestionsOpen && matchingPeopleSuggestions.length > 0 && (
              <div className="search-suggestions">
                {matchingPeopleSuggestions.map((person) => {
                  // Reuses `balances` (personBalanceMap over the full,
                  // already-loaded ledger) - the same figure PersonDetail's
                  // balance card and this page's own grid "Balance" column
                  // already show, not a second calculation.
                  const balance = balances.get(person.id) ?? 0
                  const owesUs = balance > 0.005
                  const weOwe = balance < -0.005

                  return (
                    <button
                      type="button"
                      key={person.id}
                      className="search-suggestion-row"
                      onClick={() => {
                        setPersonFilter(String(person.id))
                        setSearch('')
                        setDebouncedSearch('')
                        setSuggestionsOpen(false)
                      }}
                    >
                      <Users size={14} />
                      <span className="search-suggestion-text">
                        <strong>{person.name}</strong>
                        <small className={owesUs ? 'money-neg' : weOwe ? 'money-pos' : ''}>
                          {owesUs ? 'Owes us' : weOwe ? 'We owe them' : 'Paid in full'}: {formatMoney(Math.abs(balance))}
                        </small>
                      </span>
                    </button>
                  )
                })}
              </div>
            )}
          </label>

          <label>
            <span>Type</span>
            <select
              value={typeFilter}
              onChange={(event) => setTypeFilter(event.target.value)}
            >
              <option value="all">All transaction types</option>
              {transactionTypes.map((type) => (
                <option value={type} key={type}>
                  {formatTransactionType(type)}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Customer / Person</span>
            <select
              value={personFilter}
              onChange={(event) => setPersonFilter(event.target.value)}
            >
              <option value="all">All people</option>
              {people.map((person) => (
                <option value={person.id} key={person.id}>
                  {person.name}
                </option>
              ))}
            </select>
            {personFilter !== 'all' && (
              <span className="active-filter-chip">
                {selectedPersonLoading ? 'Loading...' : selectedPerson?.name || 'Selected'}
                <button
                  type="button"
                  aria-label="Clear customer filter"
                  onClick={() => setPersonFilter('all')}
                >
                  <X size={12} />
                </button>
              </span>
            )}
          </label>

          <label>
            <span>Account</span>
            <select
              value={accountFilter}
              onChange={(event) => setAccountFilter(event.target.value)}
            >
              <option value="all">All accounts</option>
              {accounts.map((account) => (
                <option value={account.id} key={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Status</span>
            <select
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
            >
              <option value="all">All statuses</option>
              <option value="posted">Posted</option>
              <option value="voided">Voided</option>
            </select>
          </label>

          <label>
            <span>From Date</span>
            <input
              type="date"
              value={fromDate}
              onChange={(event) => setFromDate(event.target.value)}
            />
          </label>

          <label>
            <span>To Date</span>
            <input
              type="date"
              value={toDate}
              onChange={(event) => setToDate(event.target.value)}
            />
          </label>
        </div>

        <div className="transaction-misc-links">
          <span>Miscellaneous entry not tied to goods:</span>
          <Link to="/record?kind=income">+ Other Income</Link>
          <Link to="/record?kind=expense">+ Other Expense</Link>
        </div>
      </section>

      <section className="panel transaction-history-panel">
        <div className="panel-header">
          <div>
            <h2>Complete Ledger</h2>
            <p>
              {loading
                ? 'Loading transactions...'
                : `${filteredTransactions.length} of ${transactions.length} transaction(s)`}
            </p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading transactions...</div>
        ) : filteredTransactions.length === 0 ? (
          <div className="empty-state">
            <ReceiptText size={36} />
            <h3>No transactions found</h3>
            <p>
              Try changing the filters or record a new transaction from the
              Sales section.
            </p>
          </div>
        ) : (
          <TransactionGrid transactions={gridTransactions} showBalance />
        )}
      </section>
    </div>
  )
}


const LOAN_DEBT_TRANSACTION_TYPES = [
  'loan_given',
  'loan_received',
  'loan_repayment',
  'loan_payment',
  'debt_created',
  'debt_payment',
]

function todayIsoDate() {
  return new Date().toISOString().slice(0, 10)
}

function LoansDebts() {
  const [searchParams] = useSearchParams()
  const [people, setPeople] = useState<Person[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(
    LOAN_DEBT_TRANSACTION_TYPES.includes(searchParams.get('kind') ?? ''),
  )
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const [transactionType, setTransactionType] = useState(() => {
    const kind = searchParams.get('kind')
    return kind && LOAN_DEBT_TRANSACTION_TYPES.includes(kind) ? kind : 'loan_given'
  })
  const [personId, setPersonId] = useState('')
  const [accountId, setAccountId] = useState('')
  const [amount, setAmount] = useState('')
  const [transactionDate, setTransactionDate] = useState(todayIsoDate)
  const [description, setDescription] = useState('')

  async function loadData() {
    try {
      setLoading(true)
      setError('')

      const [peopleResponse, accountsResponse, allTransactions] =
        await Promise.all([
          apiFetch('/api/people'),
          apiFetch('/api/accounts'),
          fetchAllTransactions(),
        ])

      if (!peopleResponse.ok || !accountsResponse.ok) {
        throw new Error('Unable to load loans and debts data.')
      }

      const peopleData: PeopleResponse = await peopleResponse.json()
      const accountsData: Account[] = await accountsResponse.json()

      setPeople(peopleData.data)
      setAccounts(accountsData)
      setTransactions(allTransactions)
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to load loans and debts data.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  // Loans/debts only - credit_sale/customer_payment are a customer's sales
  // receivable, a completely different concept from a loan (see
  // BalanceService::customerReceivableBalance on the backend, which applies
  // the same separation). Merging them here would make a customer who both
  // owes money for goods AND has an outstanding loan show one contaminated
  // number instead of two honest ones.
  const balances = new Map<number, number>()
  for (const transaction of transactions) {
    if (!transaction.person?.id) continue

    const amountValue = Number(transaction.amount)
    const effect =
      transaction.type === 'loan_given' ||
      transaction.type === 'debt_created' ||
      transaction.type === 'loan_payment'
        ? amountValue
        : transaction.type === 'loan_repayment' ||
            transaction.type === 'loan_received' ||
            transaction.type === 'debt_payment'
          ? -amountValue
          : 0

    balances.set(
      transaction.person.id,
      (balances.get(transaction.person.id) ?? 0) + effect,
    )
  }

  // Loans specifically (excluding debt_created/debt_payment) for the
  // "Active Loans" stat below - previously sourced from GET /api/loans,
  // a table nothing in the backend ever writes a row to, so the stat
  // always read 0 no matter how many loans were actually given or taken.
  const loanOnlyBalances = new Map<number, number>()
  for (const transaction of transactions) {
    if (!transaction.person?.id) continue

    const amountValue = Number(transaction.amount)
    const effect =
      transaction.type === 'loan_given' || transaction.type === 'loan_payment'
        ? amountValue
        : transaction.type === 'loan_repayment' || transaction.type === 'loan_received'
          ? -amountValue
          : 0

    loanOnlyBalances.set(
      transaction.person.id,
      (loanOnlyBalances.get(transaction.person.id) ?? 0) + effect,
    )
  }
  const activeLoanCount = Array.from(loanOnlyBalances.values()).filter(
    (balance) => Math.abs(balance) > 0.005,
  ).length

  const owedToGedi = people
    .map((person) => ({ person, balance: balances.get(person.id) ?? 0 }))
    .filter((entry) => entry.balance > 0.005)
    .sort((a, b) => b.balance - a.balance)

  const owedByGedi = people
    .map((person) => ({ person, balance: Math.abs(balances.get(person.id) ?? 0) }))
    .filter((entry) => (balances.get(entry.person.id) ?? 0) < -0.005)
    .sort((a, b) => b.balance - a.balance)

  const totalReceivable = owedToGedi.reduce((sum, entry) => sum + entry.balance, 0)
  const totalPayable = owedByGedi.reduce((sum, entry) => sum + entry.balance, 0)
  const netPosition = totalReceivable - totalPayable

  const recentLoanTransactions = transactions
    .filter((transaction) =>
      [
        'loan_given',
        'loan_repayment',
        'loan_received',
        'loan_payment',
        'debt_created',
        'debt_payment',
      ].includes(transaction.type),
    )
    .slice(0, 8)

  function resetForm() {
    setTransactionType('loan_given')
    setPersonId('')
    setAccountId('')
    setAmount('')
    setTransactionDate(todayIsoDate())
    setDescription('')
    setFormError('')
  }

  function handleCloseForm() {
    if (saving) return
    setShowForm(false)
    resetForm()
  }

  async function submitTransaction(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError('')

    if (!personId || !amount || !description.trim()) {
      setFormError('Person, amount and description are required.')
      return
    }

    const needsAccount = [
      'loan_given',
      'loan_repayment',
      'loan_received',
      'loan_payment',
      'debt_payment',
    ].includes(transactionType)

    if (needsAccount && !accountId) {
      setFormError('Select the money account used for this transaction.')
      return
    }

    try {
      setSaving(true)

      const response = await apiFetch('/api/transactions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          type: transactionType,
          person_id: Number(personId),
          account_id: accountId ? Number(accountId) : null,
          amount: Number(amount),
          currency: 'USD',
          description: description.trim(),
          transaction_date: transactionDate || todayIsoDate(),
          status: 'posted',
        }),
      })

      const result = await response.json()
      if (!response.ok) {
        throw new Error(result.message || 'Unable to save transaction.')
      }

      // Stay on Loans & Debts - close the modal and refresh the balances/
      // activity in place, rather than navigating away to a receipt page.
      setShowForm(false)
      resetForm()
      await loadData()
    } catch (err) {
      setFormError(
        err instanceof Error ? err.message : 'Unable to save transaction.',
      )
    } finally {
      setSaving(false)
    }
  }

  const selectedPerson = people.find((person) => String(person.id) === personId)
  const typeLabel = transactionType === 'loan_given'
    ? 'Loan Given'
    : transactionType === 'loan_received'
      ? 'Loan Received'
      : transactionType === 'loan_repayment'
        ? 'Loan Repayment'
        : transactionType === 'loan_payment'
          ? 'Loan Payment'
          : transactionType === 'debt_created'
            ? 'Debt Created'
            : 'Debt Payment'

  return (
    <div className="page loans-page">
      <div className="page-header">
        <div>
          <p className="eyebrow">Gedi Finance</p>
          <h1>Loans &amp; Debts</h1>
          <p className="muted">Track money owed to and from the business.</p>
        </div>

        <div className="page-header-actions">
          <button
            type="button"
            className="secondary-button"
            onClick={() => void loadData()}
            disabled={loading}
          >
            <RefreshCw size={16} />
            {loading ? 'Refreshing...' : 'Refresh'}
          </button>
          <button
            type="button"
            className="primary-button"
            onClick={() => {
              resetForm()
              setShowForm(true)
            }}
          >
            + Add Transaction
          </button>
        </div>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid loans-stats-grid">
        <div className="stat-card">
          <div className="stat-icon"><ArrowDownLeft size={20} /></div>
          <span>Money Owed to Gedi</span>
          <strong className={moneyToneClass(totalReceivable)}>{loading ? 'Loading...' : formatMoney(totalReceivable)}</strong>
          <small>{owedToGedi.length} outstanding balance(s)</small>
        </div>
        <div className="stat-card">
          <div className="stat-icon"><ArrowUpRight size={20} /></div>
          <span>Money Gedi Owes</span>
          <strong className={moneyToneClass(-totalPayable)}>{loading ? 'Loading...' : formatMoney(totalPayable)}</strong>
          <small>{owedByGedi.length} outstanding balance(s)</small>
        </div>
        <div className="stat-card">
          <div className="stat-icon"><Wallet size={20} /></div>
          <span>Net Position</span>
          <strong className={moneyToneClass(netPosition)}>{loading ? 'Loading...' : formatMoney(netPosition)}</strong>
          <small>{netPosition >= 0 ? 'Net receivable' : 'Net payable'}</small>
        </div>
        <div className="stat-card">
          <div className="stat-icon"><CreditCard size={20} /></div>
          <span>Active Loans</span>
          <strong>{loading ? 'Loading...' : activeLoanCount}</strong>
          <small>Loan records currently active</small>
        </div>
      </div>

      {showForm && (
        <div className="modal-backdrop loan-form-backdrop" onClick={handleCloseForm}>
          <div
            className="modal-card loan-form-modal"
            onClick={(event) => event.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="loan-form-title"
          >
            <div className="modal-header">
              <div>
                <h2 id="loan-form-title">Record Loan or Debt Transaction</h2>
                <p className="muted-text">Create a posted ledger entry and update the person&apos;s balance.</p>
              </div>
              <button
                type="button"
                className="icon-button"
                onClick={handleCloseForm}
                disabled={saving}
                aria-label="Close"
              >
                <X size={18} />
              </button>
            </div>

            <div className="modal-scroll-body">
              {formError && <div className="error-banner form-error">{formError}</div>}

              <form id="loan-transaction-form" className="loan-form" onSubmit={submitTransaction}>
                <label>
                  <span>Transaction Type *</span>
                  <select value={transactionType} onChange={(event) => setTransactionType(event.target.value)}>
                    <option value="loan_given">Loan Given</option>
                    <option value="loan_received">Loan Received</option>
                    <option value="loan_repayment">Loan Repayment</option>
                    <option value="loan_payment">Loan Payment</option>
                    <option value="debt_created">Debt Created</option>
                    <option value="debt_payment">Debt Payment</option>
                  </select>
                </label>

                <label>
                  <span>Person *</span>
                  <select value={personId} onChange={(event) => setPersonId(event.target.value)}>
                    <option value="">Select person</option>
                    {people.map((person) => (
                      <option value={person.id} key={person.id}>{person.name}</option>
                    ))}
                  </select>
                </label>

                <label>
                  <span>Amount *</span>
                  <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                    placeholder="0.00"
                  />
                </label>

                <label>
                  <span>Money Account {transactionType === 'debt_created' ? '' : '*'}</span>
                  <select value={accountId} onChange={(event) => setAccountId(event.target.value)}>
                    <option value="">{transactionType === 'debt_created' ? 'No account movement' : 'Select account'}</option>
                    {accounts.map((account) => (
                      <option value={account.id} key={account.id}>{account.name}</option>
                    ))}
                  </select>
                </label>

                <label>
                  <span>Date *</span>
                  <input
                    type="date"
                    value={transactionDate}
                    onChange={(event) => setTransactionDate(event.target.value)}
                  />
                </label>

                <label>
                  <span>Current Balance</span>
                  <input
                    className={selectedPerson ? moneyToneClass(balances.get(selectedPerson.id) ?? 0) : ''}
                    value={selectedPerson ? formatMoney(balances.get(selectedPerson.id) ?? 0) : 'Select a person'}
                    readOnly
                  />
                </label>

                <label className="form-field-full">
                  <span>Notes / Description *</span>
                  <textarea
                    rows={3}
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    placeholder={`e.g. ${typeLabel} for wholesale supplies`}
                  />
                </label>

                <div className="loan-form-note">
                  <strong>{typeLabel}</strong>
                  <span>
                    {transactionType === 'loan_given' || transactionType === 'debt_created'
                      ? 'This increases the amount the person owes Gedi.'
                      : transactionType === 'loan_received'
                        ? "This records money Gedi received as a loan and reduces the person's receivable balance."
                        : transactionType === 'loan_payment'
                          ? 'This records Gedi paying back a loan received from the person.'
                          : "This reduces the person's outstanding balance with Gedi."}
                  </span>
                </div>
              </form>
            </div>

            <div className="modal-actions loan-form-modal-actions">
              <button type="button" className="secondary-button" onClick={handleCloseForm} disabled={saving}>
                Cancel
              </button>
              <button type="submit" form="loan-transaction-form" className="primary-button" disabled={saving}>
                {saving ? 'Saving...' : 'Save Transaction'}
              </button>
            </div>
          </div>
        </div>
      )}

      <div className="loan-columns">
        <section className="panel loan-balance-panel">
          <div className="panel-header">
            <div>
              <h2>Money Owed to Gedi</h2>
              <p>Customers and borrowers with outstanding balances.</p>
            </div>
            <strong className={`panel-total ${moneyToneClass(totalReceivable)}`}>{formatMoney(totalReceivable)}</strong>
          </div>

          {owedToGedi.length === 0 ? (
            <div className="empty-state compact-empty">
              <ArrowDownLeft size={30} />
              <h3>No receivables</h3>
              <p>No person currently owes Gedi money.</p>
            </div>
          ) : (
            <div className="loan-person-list">
              {owedToGedi.map(({ person, balance }) => (
                <div className="loan-person-card" key={person.id}>
                  <div>
                    <strong>{person.name}</strong>
                    <span>{person.roles.join(', ') || 'Business contact'}</span>
                  </div>
                  <strong className={`loan-amount ${moneyToneClass(balance)}`}>{formatMoney(balance)}</strong>
                </div>
              ))}
            </div>
          )}
        </section>

        <section className="panel loan-balance-panel">
          <div className="panel-header">
            <div>
              <h2>Money Gedi Owes</h2>
              <p>People with balances payable by the business.</p>
            </div>
            <strong className={`panel-total ${moneyToneClass(-totalPayable)}`}>{formatMoney(totalPayable)}</strong>
          </div>

          {owedByGedi.length === 0 ? (
            <div className="empty-state compact-empty">
              <ArrowUpRight size={30} />
              <h3>No payables</h3>
              <p>Gedi does not currently owe anyone money.</p>
            </div>
          ) : (
            <div className="loan-person-list">
              {owedByGedi.map(({ person, balance }) => (
                <div className="loan-person-card" key={person.id}>
                  <div>
                    <strong>{person.name}</strong>
                    <span>{person.roles.join(', ') || 'Business contact'}</span>
                  </div>
                  <strong className={`loan-amount ${moneyToneClass(-balance)}`}>{formatMoney(balance)}</strong>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Loan &amp; Debt Activity</h2>
            <p>Recent loan, repayment and debt entries.</p>
          </div>
        </div>

        {recentLoanTransactions.length === 0 ? (
          <div className="empty-state compact-empty">
            <ReceiptText size={30} />
            <h3>No loan or debt activity</h3>
            <p>Use Add Transaction to record the first entry.</p>
          </div>
        ) : (
          <div className="transaction-grid loan-activity-grid">
            <div className="transaction-grid-header">
              <span>Date</span>
              <span>Time</span>
              <span>Type</span>
              <span>Person</span>
              <span>Amount</span>
              <span>Account</span>
              <span>Description</span>
            </div>
            <div className="transaction-grid-body">
              {recentLoanTransactions.map((transaction) => (
                <div className="transaction-grid-row" key={transaction.id}>
                  <div className="transaction-grid-cell" data-label="Date">{formatDate(transaction.transaction_date)}</div>
                  <div className="transaction-grid-cell" data-label="Time">{formatTime(transactionTimestamp(transaction))}</div>
                  <div className="transaction-grid-cell" data-label="Type">{formatTransactionType(transaction.type)}</div>
                  <div className="transaction-grid-cell" data-label="Person">{transaction.person?.name || 'Not specified'}</div>
                  <div className="transaction-grid-cell amount-cell" data-label="Amount"><strong className={moneyToneClass(transactionCashEffect(transaction))}>{formatMoney(transaction.amount)}</strong></div>
                  <div className="transaction-grid-cell" data-label="Account">{transaction.account?.name || 'No account'}</div>
                  <div className="transaction-grid-cell description-cell" data-label="Description">{transaction.description}</div>
                </div>
              ))}
            </div>
          </div>
        )}
      </section>
    </div>
  )
}

const RECORD_TRANSACTION_KINDS = ['income', 'expense', 'account_transfer'] as const
type RecordTransactionKind = (typeof RECORD_TRANSACTION_KINDS)[number]

const RECORD_TRANSACTION_COPY: Record<RecordTransactionKind, { title: string; description: string }> = {
  income: {
    title: 'Receive Money',
    description: 'Record money coming into the business from a customer or other source.',
  },
  expense: {
    title: 'Expense',
    description: 'Record money leaving an account for a business expense.',
  },
  account_transfer: {
    title: 'Transfer',
    description: 'Move money between two of your accounts. Total balance stays unchanged.',
  },
}

/**
 * Generic form for the three transaction types that have no dedicated
 * recording flow elsewhere in the app (income/expense/transfer - loan and
 * sale types already have their own forms on Loans & Debts / Sales). The
 * backend already supports all of these via POST /api/transactions; this
 * page just fills the gap the Dashboard's quick actions were already
 * linking to.
 */
function RecordTransaction() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const kindParam = searchParams.get('kind')
  const kind: RecordTransactionKind = (RECORD_TRANSACTION_KINDS as readonly string[]).includes(kindParam ?? '')
    ? (kindParam as RecordTransactionKind)
    : 'income'

  const [accounts, setAccounts] = useState<Account[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')

  const [accountId, setAccountId] = useState('')
  const [destinationAccountId, setDestinationAccountId] = useState('')
  const [personId, setPersonId] = useState('')
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState('')
  const [transactionDate, setTransactionDate] = useState(() => new Date().toISOString().slice(0, 10))

  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  useEffect(() => {
    async function loadData() {
      try {
        setLoading(true)
        setLoadError('')

        const [accountsResponse, peopleResponse] = await Promise.all([
          apiFetch('/api/accounts'),
          apiFetch('/api/people'),
        ])

        if (!accountsResponse.ok || !peopleResponse.ok) {
          throw new Error('Unable to load accounts.')
        }

        const accountsData: Account[] = await accountsResponse.json()
        const peopleData: PeopleResponse = await peopleResponse.json()

        setAccounts(accountsData)
        setPeople(peopleData.data)
      } catch (err) {
        setLoadError(err instanceof Error ? err.message : 'Unable to load accounts.')
      } finally {
        setLoading(false)
      }
    }

    void loadData()
  }, [])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError('')

    if (!accountId || !amount || !description.trim()) {
      setFormError('Account, amount and description are required.')
      return
    }

    if (kind === 'account_transfer' && (!destinationAccountId || destinationAccountId === accountId)) {
      setFormError('Choose two different accounts to transfer between.')
      return
    }

    try {
      setSaving(true)

      const payload: Record<string, string | number> = {
        type: kind,
        account_id: Number(accountId),
        amount: Number(amount),
        currency: 'USD',
        description: description.trim(),
        transaction_date: transactionDate,
      }

      if (kind === 'account_transfer') {
        payload.destination_account_id = Number(destinationAccountId)
      }

      if (kind === 'income' && personId) {
        payload.person_id = Number(personId)
      }

      const response = await apiFetch('/api/transactions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })

      const result = await response.json()

      if (!response.ok) {
        const message =
          result?.errors?.account_id?.[0] ||
          result?.errors?.destination_account_id?.[0] ||
          result?.errors?.amount?.[0] ||
          result?.errors?.description?.[0] ||
          result?.message ||
          'Unable to save transaction.'

        throw new Error(message)
      }

      const createdId = result?.transaction?.id

      if (createdId) {
        navigate(`/transactions/${createdId}/receipt`)
      } else {
        navigate('/')
      }
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Unable to save transaction.')
    } finally {
      setSaving(false)
    }
  }

  const copy = RECORD_TRANSACTION_COPY[kind]
  const activeAccounts = accounts.filter((account) => account.is_active)

  return (
    <div className="page">
      <PageHeader
        eyebrow="Wholesale"
        title={copy.title}
        description={copy.description}
        actions={
          <button type="button" className="secondary-button" onClick={() => navigate('/')}>
            <ArrowLeft size={16} />
            Back to Dashboard
          </button>
        }
      />

      {loadError && <div className="error-banner">{loadError}</div>}

      <section className="panel form-panel">
        {loading ? (
          <div className="people-loading">Loading...</div>
        ) : (
          <form className="person-form" onSubmit={handleSubmit}>
            {formError && <div className="error-banner form-error">{formError}</div>}

            <div className="form-grid">
              <label>
                <span>{kind === 'account_transfer' ? 'From Account *' : 'Account *'}</span>

                <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
                  <option value="">Select account</option>
                  {activeAccounts.map((account) => (
                    <option value={account.id} key={account.id}>
                      {account.name}
                    </option>
                  ))}
                </select>
              </label>

              {kind === 'account_transfer' && (
                <label>
                  <span>To Account *</span>

                  <select
                    value={destinationAccountId}
                    onChange={(event) => setDestinationAccountId(event.target.value)}
                    required
                  >
                    <option value="">Select account</option>
                    {activeAccounts
                      .filter((account) => String(account.id) !== accountId)
                      .map((account) => (
                        <option value={account.id} key={account.id}>
                          {account.name}
                        </option>
                      ))}
                  </select>
                </label>
              )}

              {kind === 'income' && (
                <label>
                  <span>From (optional)</span>

                  <select value={personId} onChange={(event) => setPersonId(event.target.value)}>
                    <option value="">Not specified</option>
                    {people.map((person) => (
                      <option value={person.id} key={person.id}>
                        {person.name}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              <label>
                <span>Amount *</span>

                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                  placeholder="0.00"
                  required
                />
              </label>

              <label>
                <span>Date</span>

                <input
                  type="date"
                  value={transactionDate}
                  onChange={(event) => setTransactionDate(event.target.value)}
                />
              </label>

              <label className="form-field-full">
                <span>Description *</span>

                <textarea
                  value={description}
                  onChange={(event) => setDescription(event.target.value)}
                  placeholder={
                    kind === 'income'
                      ? 'e.g. Freelance payment from Ahmed'
                      : kind === 'expense'
                        ? 'e.g. Tuk-tuk to warehouse'
                        : 'e.g. Move float from Bank to Cash'
                  }
                  rows={3}
                  required
                />
              </label>
            </div>

            <div className="form-actions">
              <button type="button" className="secondary-button" onClick={() => navigate('/')} disabled={saving}>
                Cancel
              </button>

              <button type="submit" className="primary-button" disabled={saving}>
                {saving ? 'Saving...' : 'Confirm & Save'}
              </button>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}

/**
 * The Dashboard's "Receive Payment" quick action. Records money coming in
 * FROM A CUSTOMER against what they actually owe - via
 * POST /api/people/{person}/payments (CustomerPaymentService::
 * receiveForCustomer), which applies the amount across that customer's
 * real outstanding sales oldest-first. This is deliberately NOT a generic
 * transaction form: the customer picker only lists people who actually
 * have a receivable balance (from the same receivables report the Reports
 * hub uses), and the amount is validated against that real balance before
 * it's ever submitted.
 */
function ReceiveCustomerPaymentPage() {
  const navigate = useNavigate()
  const [customers, setCustomers] = useState<ReceivablePayableRow[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')

  const [customerId, setCustomerId] = useState('')
  const [amount, setAmount] = useState('')
  const [accountId, setAccountId] = useState('')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  useEffect(() => {
    async function loadData() {
      try {
        setLoading(true)
        setLoadError('')

        const [receivablesResponse, accountsResponse] = await Promise.all([
          apiFetch('/api/reports/customer-receivables'),
          apiFetch('/api/accounts'),
        ])

        if (!receivablesResponse.ok || !accountsResponse.ok) {
          throw new Error('Unable to load customer balances.')
        }

        const receivablesData: { data: ReceivablePayableRow[] } = await receivablesResponse.json()
        const accountsData: Account[] = await accountsResponse.json()

        setCustomers(receivablesData.data)
        setAccounts(accountsData)
      } catch (err) {
        setLoadError(err instanceof Error ? err.message : 'Unable to load customer balances.')
      } finally {
        setLoading(false)
      }
    }

    void loadData()
  }, [])

  const selectedCustomer = customers.find((row) => String(row.id) === customerId)
  const owed = selectedCustomer ? Number(selectedCustomer.balance) : 0

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError('')

    if (!customerId) {
      setFormError('Select a customer.')
      return
    }
    if (!amount || Number(amount) <= 0) {
      setFormError('Enter a payment amount greater than zero.')
      return
    }
    if (Number(amount) > owed + 0.005) {
      setFormError(`Amount cannot exceed what this customer owes (${formatMoney(owed)}).`)
      return
    }
    if (!accountId) {
      setFormError('Select the account receiving the payment.')
      return
    }

    try {
      setSaving(true)

      const response = await apiFetch(`/api/people/${customerId}/payments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: Number(amount),
          account_id: Number(accountId),
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.amount?.[0] || 'Unable to record payment.')
      }

      navigate(`/people/${customerId}`)
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Unable to record payment.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page">
      <PageHeader
        eyebrow="Customers"
        title="Receive Customer Payment"
        description="Record money a customer has paid us against what they owe."
        actions={
          <button type="button" className="secondary-button" onClick={() => navigate('/')}>
            <ArrowLeft size={16} />
            Back to Dashboard
          </button>
        }
      />

      {loadError && <div className="error-banner">{loadError}</div>}

      <section className="panel form-panel">
        {loading ? (
          <div className="people-loading">Loading...</div>
        ) : customers.length === 0 ? (
          <EmptyState
            icon={<Users size={32} />}
            title="No customers currently owe money"
            description="Once a customer has an outstanding balance from a credit sale, they'll appear here."
          />
        ) : (
          <form className="person-form" onSubmit={handleSubmit}>
            {formError && <div className="error-banner form-error">{formError}</div>}

            <div className="form-grid">
              <label>
                <span>Customer *</span>
                <select
                  value={customerId}
                  onChange={(event) => {
                    setCustomerId(event.target.value)
                    setAmount('')
                  }}
                  required
                >
                  <option value="">Select customer</option>
                  {customers.map((row) => (
                    <option value={row.id} key={row.id}>
                      {row.name} - owes {formatMoney(row.balance)}
                    </option>
                  ))}
                </select>
              </label>

              {selectedCustomer && (
                <div className="sale-credit-preview">
                  <span>Customer Currently Owes</span>
                  <strong className="money-neg">{formatMoney(owed)}</strong>
                </div>
              )}

              <label>
                <span>Amount *</span>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  max={owed || undefined}
                  value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                  placeholder={selectedCustomer ? `Up to ${formatMoney(owed)}` : '0.00'}
                  disabled={!customerId}
                  required
                />
              </label>

              <label>
                <span>Receive Into *</span>
                <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
                  <option value="">Select account</option>
                  {accounts
                    .filter((account) => account.is_active)
                    .map((account) => (
                      <option value={account.id} key={account.id}>
                        {account.name}
                      </option>
                    ))}
                </select>
              </label>
            </div>

            <div className="form-actions">
              <button type="button" className="secondary-button" onClick={() => navigate('/')} disabled={saving}>
                Cancel
              </button>

              <button type="submit" className="primary-button" disabled={saving}>
                {saving ? 'Recording...' : 'Receive Payment'}
              </button>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}

/**
 * The Dashboard's "Pay Supplier" quick action - the mirror image of
 * ReceiveCustomerPaymentPage. Records money going out TO A SUPPLIER
 * against what the business actually owes them, via
 * POST /api/suppliers/{supplier}/payments (SupplierPaymentService::
 * payForSupplier), applied across their real outstanding purchases
 * oldest-first.
 */
function PaySupplierPage() {
  const navigate = useNavigate()
  const [suppliersOwed, setSuppliersOwed] = useState<ReceivablePayableRow[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')

  const [supplierId, setSupplierId] = useState('')
  const [amount, setAmount] = useState('')
  const [accountId, setAccountId] = useState('')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  useEffect(() => {
    async function loadData() {
      try {
        setLoading(true)
        setLoadError('')

        const [payablesResponse, accountsResponse] = await Promise.all([
          apiFetch('/api/reports/supplier-payables'),
          apiFetch('/api/accounts'),
        ])

        if (!payablesResponse.ok || !accountsResponse.ok) {
          throw new Error('Unable to load supplier balances.')
        }

        const payablesData: { data: ReceivablePayableRow[] } = await payablesResponse.json()
        const accountsData: Account[] = await accountsResponse.json()

        setSuppliersOwed(payablesData.data)
        setAccounts(accountsData)
      } catch (err) {
        setLoadError(err instanceof Error ? err.message : 'Unable to load supplier balances.')
      } finally {
        setLoading(false)
      }
    }

    void loadData()
  }, [])

  const selectedSupplier = suppliersOwed.find((row) => String(row.id) === supplierId)
  const owed = selectedSupplier ? Number(selectedSupplier.balance) : 0

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError('')

    if (!supplierId) {
      setFormError('Select a supplier.')
      return
    }
    if (!amount || Number(amount) <= 0) {
      setFormError('Enter a payment amount greater than zero.')
      return
    }
    if (Number(amount) > owed + 0.005) {
      setFormError(`Amount cannot exceed what we owe this supplier (${formatMoney(owed)}).`)
      return
    }
    if (!accountId) {
      setFormError('Select the account paying the supplier.')
      return
    }

    try {
      setSaving(true)

      const response = await apiFetch(`/api/suppliers/${supplierId}/payments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: Number(amount),
          account_id: Number(accountId),
        }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.amount?.[0] || 'Unable to record payment.')
      }

      navigate('/suppliers')
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Unable to record payment.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page">
      <PageHeader
        eyebrow="Suppliers"
        title="Pay Supplier"
        description="Record money we've paid a supplier against what we owe them."
        actions={
          <button type="button" className="secondary-button" onClick={() => navigate('/')}>
            <ArrowLeft size={16} />
            Back to Dashboard
          </button>
        }
      />

      {loadError && <div className="error-banner">{loadError}</div>}

      <section className="panel form-panel">
        {loading ? (
          <div className="people-loading">Loading...</div>
        ) : suppliersOwed.length === 0 ? (
          <EmptyState
            icon={<Truck size={32} />}
            title="We don't owe any supplier money"
            description="Once a purchase is made on supplier credit, they'll appear here."
          />
        ) : (
          <form className="person-form" onSubmit={handleSubmit}>
            {formError && <div className="error-banner form-error">{formError}</div>}

            <div className="form-grid">
              <label>
                <span>Supplier *</span>
                <select
                  value={supplierId}
                  onChange={(event) => {
                    setSupplierId(event.target.value)
                    setAmount('')
                  }}
                  required
                >
                  <option value="">Select supplier</option>
                  {suppliersOwed.map((row) => (
                    <option value={row.id} key={row.id}>
                      {row.name} - we owe {formatMoney(row.balance)}
                    </option>
                  ))}
                </select>
              </label>

              {selectedSupplier && (
                <div className="sale-credit-preview">
                  <span>We Currently Owe</span>
                  <strong className="money-neg">{formatMoney(owed)}</strong>
                </div>
              )}

              <label>
                <span>Amount *</span>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  max={owed || undefined}
                  value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                  placeholder={selectedSupplier ? `Up to ${formatMoney(owed)}` : '0.00'}
                  disabled={!supplierId}
                  required
                />
              </label>

              <label>
                <span>Pay From *</span>
                <select value={accountId} onChange={(event) => setAccountId(event.target.value)} required>
                  <option value="">Select account</option>
                  {accounts
                    .filter((account) => account.is_active)
                    .map((account) => (
                      <option value={account.id} key={account.id}>
                        {account.name}
                      </option>
                    ))}
                </select>
              </label>
            </div>

            <div className="form-actions">
              <button type="button" className="secondary-button" onClick={() => navigate('/')} disabled={saving}>
                Cancel
              </button>

              <button type="submit" className="primary-button" disabled={saving}>
                {saving ? 'Recording...' : 'Pay Supplier'}
              </button>
            </div>
          </form>
        )}
      </section>
    </div>
  )
}

function Accounts() {
  const [accounts, setAccounts] = useState<Account[]>([])
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('active')
  const [selectedAccountId, setSelectedAccountId] = useState<number | null>(null)
  const [editingAccount, setEditingAccount] = useState<Account | null>(null)

  async function loadAccounts() {
    try {
      setLoading(true)
      setError('')

      // Each account card's "Transactions" count is derived from this list
      // client-side, so it silently undercounts once the ledger passes one
      // page unless every page is fetched (the account BALANCE itself is
      // safe either way - it's computed server-side by BalanceService).
      const [accountsResponse, allTransactions] = await Promise.all([
        apiFetch('/api/accounts'),
        fetchAllTransactions(),
      ])

      if (!accountsResponse.ok) {
        throw new Error('Unable to load account data.')
      }

      const accountsData: Account[] = await accountsResponse.json()

      setAccounts(accountsData)
      setTransactions(allTransactions)

      if (
        selectedAccountId !== null &&
        !accountsData.some((account) => account.id === selectedAccountId)
      ) {
        setSelectedAccountId(null)
      }
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to load account data.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadAccounts()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const accountTypes = Array.from(
    new Set(accounts.map((account) => account.type)),
  ).sort()

  const filteredAccounts = accounts.filter((account) => {
    const matchesSearch = account.name
      .toLowerCase()
      .includes(search.trim().toLowerCase())
    const matchesType =
      typeFilter === 'all' || account.type === typeFilter
    const matchesStatus =
      statusFilter === 'all' ||
      (statusFilter === 'active' && account.is_active) ||
      (statusFilter === 'inactive' && !account.is_active)

    return matchesSearch && matchesType && matchesStatus
  })

  const activeAccounts = accounts.filter((account) => account.is_active)
  const totalBalance = activeAccounts.reduce(
    (total, account) => total + Number(account.current_balance),
    0,
  )

  const selectedAccount = accounts.find(
    (account) => account.id === selectedAccountId,
  )

  const selectedTransactions = selectedAccount
    ? transactions
        .filter(
          (transaction) => transaction.account?.id === selectedAccount.id,
        )
        .slice(0, 12)
    : []

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <p className="eyebrow">Where Is Our Money?</p>
          <h1>Accounts</h1>
          <p className="muted">
            Cash, Bank and mobile money - where the business actually holds its money.
          </p>
        </div>

        <button
          type="button"
          className="secondary-button"
          onClick={() => void loadAccounts()}
          disabled={loading}
        >
          <RefreshCw size={17} />
          {loading ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="account-overview-summary">
        <Wallet size={18} />
        <span>ACCOUNTS</span>
        <strong>{loading ? '...' : accounts.length}</strong>
        <small>{loading ? '' : `${activeAccounts.length} active`}</small>
      </div>

      {!loading && accounts.length > 0 && (
        <section className="panel account-balances-panel">
          <div className="panel-header">
            <div>
              <h2>Account Balances</h2>
              <p>What each account currently holds, straight from the ledger.</p>
            </div>
          </div>

          <div className="account-balance-list-header">
            <span>Account</span>
            <span>Balance</span>
          </div>

          <div className="account-balance-list">
            {accounts.map((account) => (
              <div className="account-balance-row" key={account.id}>
                <span className="account-balance-row-name">
                  {account.name}
                  {!account.is_active && <em className="account-balance-row-inactive">Inactive</em>}
                </span>
                <strong className={moneyToneClass(Number(account.current_balance))}>
                  {formatMoney(account.current_balance)}
                </strong>
              </div>
            ))}
          </div>
        </section>
      )}

      <div className="account-total-balance-card">
        <span>TOTAL BALANCE</span>
        <strong className={moneyToneClass(totalBalance)}>{loading ? '...' : formatMoney(totalBalance)}</strong>
        <small>Across all active accounts</small>
      </div>

      <section className="panel account-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Find an Account</h2>
            <p>Search and filter your configured money accounts.</p>
          </div>
        </div>

        <div className="form-grid account-filter-grid">
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search account name"
              />
            </div>
          </label>

          <label>
            <span>Account Type</span>
            <select
              value={typeFilter}
              onChange={(event) => setTypeFilter(event.target.value)}
            >
              <option value="all">All account types</option>
              {accountTypes.map((type) => (
                <option value={type} key={type}>
                  {formatTransactionType(type)}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Status</span>
            <select
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
            >
              <option value="active">Active accounts</option>
              <option value="all">All accounts</option>
              <option value="inactive">Inactive accounts</option>
            </select>
          </label>

          <div className="account-filter-summary">
            <span>Showing</span>
            <strong>{filteredAccounts.length}</strong>
            <small>account(s)</small>
          </div>
        </div>
      </section>

      <section className="panel account-list-panel">
        <div className="panel-header">
          <div>
            <h2>Money Accounts</h2>
            <p>
              {loading
                ? 'Loading accounts...'
                : `${filteredAccounts.length} account(s) shown`}
            </p>
          </div>
        </div>

        {loading ? (
          <div className="account-loading">Loading accounts...</div>
        ) : filteredAccounts.length === 0 ? (
          <div className="empty-state">
            <Wallet size={36} />
            <h3>No accounts found</h3>
            <p>Try changing your search or filters.</p>
          </div>
        ) : (
          <div className="account-card-grid">
            {filteredAccounts.map((account) => {
              const accountTransactions = transactions.filter(
                (transaction) => transaction.account?.id === account.id,
              )
              const isSelected = selectedAccountId === account.id

              return (
                <div
                  role="button"
                  tabIndex={0}
                  className={`account-card ${
                    isSelected ? 'account-card-selected' : ''
                  }`}
                  key={account.id}
                  onClick={() =>
                    setSelectedAccountId(
                      isSelected ? null : account.id,
                    )
                  }
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      setSelectedAccountId(isSelected ? null : account.id)
                    }
                  }}
                >
                  <div className="account-card-top">
                    <div>
                      <span className="account-type-label">
                        {formatTransactionType(account.type)}
                      </span>
                      <h3>{account.name}</h3>
                    </div>

                    <div className="account-card-top-actions">
                      <span
                        className={`status-badge ${
                          account.is_active
                            ? 'status-badge-active'
                            : 'status-badge-inactive'
                        }`}
                      >
                        {account.is_active ? 'Active' : 'Inactive'}
                      </span>
                      <button
                        type="button"
                        className="icon-button"
                        onClick={(event) => {
                          event.stopPropagation()
                          setEditingAccount(account)
                        }}
                        aria-label={`Edit ${account.name}`}
                      >
                        <Pencil size={16} />
                      </button>
                    </div>
                  </div>

                  <div className="account-balance-block">
                    <span>Current Balance</span>
                    <strong className={moneyToneClass(Number(account.current_balance))}>{formatMoney(account.current_balance)}</strong>
                  </div>

                  <div className="account-meta-grid">
                    <div>
                      <span>Opening Balance</span>
                      <strong className={moneyToneClass(Number(account.opening_balance))}>{formatMoney(account.opening_balance)}</strong>
                    </div>
                    <div>
                      <span>Currency</span>
                      <strong>{account.currency}</strong>
                    </div>
                    <div>
                      <span>Transactions</span>
                      <strong>{accountTransactions.length}</strong>
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </section>

      {editingAccount && (
        <EditAccountForm
          account={editingAccount}
          onClose={() => setEditingAccount(null)}
          onSaved={async () => {
            setEditingAccount(null)
            await loadAccounts()
          }}
        />
      )}

      {selectedAccount && (
        <section className="panel account-activity-panel">
          <div className="panel-header">
            <div>
              <p className="eyebrow">Account Activity</p>
              <h2>{selectedAccount.name}</h2>
              <p>Recent transactions recorded against this account.</p>
            </div>

            <button
              type="button"
              className="secondary-button"
              onClick={() => setSelectedAccountId(null)}
            >
              Close
            </button>
          </div>

          {selectedTransactions.length === 0 ? (
            <div className="empty-state">
              <ReceiptText size={32} />
              <h3>No transactions for this account</h3>
              <p>Transactions using this account will appear here.</p>
            </div>
          ) : (
            <div className="sales-table-wrapper">
              <table className="sales-table account-activity-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Person</th>
                    <th>Amount</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedTransactions.map((transaction) => (
                    <tr key={transaction.id}>
                      <td>{formatDate(transaction.transaction_date)}</td>
                      <td>{formatTime(transactionTimestamp(transaction))}</td>
                      <td>{formatTransactionType(transaction.type)}</td>
                      <td>{transaction.person?.name || 'General'}</td>
                      <td>
                        <strong className={moneyToneClass(transactionCashEffect(transaction))}>{formatMoney(transaction.amount)}</strong>
                      </td>
                      <td>{transaction.description}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}
    </div>
  )
}

function EditAccountForm({
  account,
  onClose,
  onSaved,
}: {
  account: Account
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [name, setName] = useState(account.name)
  const [type, setType] = useState(account.type)
  const [currency, setCurrency] = useState(account.currency)
  const [isActive, setIsActive] = useState(account.is_active)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')

      const response = await apiFetch(`/api/accounts/${account.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, type, currency, is_active: isActive }),
      })

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data?.message || data?.errors?.name?.[0] || 'Unable to update account.')
      }

      await onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to update account.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-card" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <div>
            <h2>Edit Account</h2>
            <p className="muted-text">
              Update {account.name}. Opening balance can't be changed here - it's baked into every past balance
              calculation for this account.
            </p>
          </div>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner form-error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <div className="form-grid">
            <label>
              <span>Name *</span>
              <input type="text" value={name} onChange={(event) => setName(event.target.value)} required />
            </label>

            <label>
              <span>Type *</span>
              <select value={type} onChange={(event) => setType(event.target.value)} required>
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </label>

            <label>
              <span>Currency *</span>
              <input
                type="text"
                value={currency}
                onChange={(event) => setCurrency(event.target.value.toUpperCase())}
                maxLength={3}
                required
              />
            </label>

            <label>
              <span>Status</span>
              <select value={isActive ? '1' : '0'} onChange={(event) => setIsActive(event.target.value === '1')}>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </select>
            </label>
          </div>

          <div className="modal-actions">
            <button type="button" className="secondary-button" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="primary-button" disabled={saving}>
              {saving ? 'Saving...' : 'Update Account'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function Reports() {
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const [personId, setPersonId] = useState('all')
  const [accountId, setAccountId] = useState('all')

  async function loadReports() {
    try {
      setLoading(true)
      setError('')

      // This page filters by an arbitrary selected month client-side, so it
      // needs the FULL ledger, not just the most recent page - otherwise
      // older months silently show as empty once the ledger passes one page.
      const [allTransactions, accountsResponse, peopleResponse] =
        await Promise.all([
          fetchAllTransactions(),
          apiFetch('/api/accounts'),
          apiFetch('/api/people'),
        ])

      if (!accountsResponse.ok || !peopleResponse.ok) {
        throw new Error('Unable to load report data.')
      }

      const accountsData: Account[] = await accountsResponse.json()
      const peopleData: PeopleResponse = await peopleResponse.json()

      setTransactions(allTransactions)
      setAccounts(accountsData)
      setPeople(peopleData.data)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to load report data.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadReports()
  }, [])

  const monthTransactions = transactions.filter((transaction) => {
    if (transaction.status !== 'posted') return false
    if (transaction.transaction_date.slice(0, 7) !== month) return false
    if (
      personId !== 'all' &&
      String(transaction.person?.id ?? '') !== personId
    ) {
      return false
    }
    if (
      accountId !== 'all' &&
      String(transaction.account?.id ?? '') !== accountId
    ) {
      return false
    }
    return true
  })

  const today = new Date().toISOString().slice(0, 10)
  const todayTransactions = monthTransactions.filter(
    (transaction) => transaction.transaction_date.slice(0, 10) === today,
  )

  // Sales are revenue. Customer payments are collections against receivables,
  // not additional sales revenue, so they are reported separately.
  const salesIncome = monthTransactions
    .filter((transaction) =>
      ['cash_sale', 'credit_sale'].includes(transaction.type),
    )
    .reduce((sum, transaction) => sum + Number(transaction.amount), 0)

  const otherIncome = monthTransactions
    .filter((transaction) => transaction.type === 'income')
    .reduce((sum, transaction) => sum + Number(transaction.amount), 0)

  const totalIncome = salesIncome + otherIncome

  const totalExpenses = monthTransactions
    .filter((transaction) => transaction.type === 'expense')
    .reduce((sum, transaction) => sum + Number(transaction.amount), 0)

  const customerPayments = monthTransactions
    .filter((transaction) => transaction.type === 'customer_payment')
    .reduce((sum, transaction) => sum + Number(transaction.amount), 0)

  const creditSales = monthTransactions
    .filter((transaction) => transaction.type === 'credit_sale')
    .reduce((sum, transaction) => sum + Number(transaction.amount), 0)

  const netIncome = totalIncome - totalExpenses

  const expenseByCategory = Array.from(
    monthTransactions
      .filter((transaction) => transaction.type === 'expense')
      .reduce((map, transaction) => {
        const category = transaction.category?.name || 'Uncategorized'
        map.set(category, (map.get(category) ?? 0) + Number(transaction.amount))
        return map
      }, new Map<string, number>())
      .entries(),
  ).sort((a, b) => b[1] - a[1])

  const accountActivity = accounts
    .map((account) => {
      const accountTransactions = monthTransactions.filter(
        (transaction) => transaction.account?.id === account.id,
      )
      const moneyIn = accountTransactions
        .filter((transaction) =>
          ['cash_sale', 'customer_payment', 'income', 'loan_received'].includes(
            transaction.type,
          ),
        )
        .reduce((sum, transaction) => sum + Number(transaction.amount), 0)
      const moneyOut = accountTransactions
        .filter((transaction) =>
          ['expense', 'loan_given', 'loan_payment'].includes(transaction.type),
        )
        .reduce((sum, transaction) => sum + Number(transaction.amount), 0)
      return {
        account,
        moneyIn,
        moneyOut,
        activity: accountTransactions.length,
      }
    })
    .filter((entry) => entry.activity > 0)
    .sort((a, b) => b.activity - a.activity)

  const receivables = new Map<number, number>()
  for (const transaction of transactions.filter(
    (item) => item.status === 'posted',
  )) {
    if (!transaction.person?.id) continue
    const amount = Number(transaction.amount)
    const effect =
      ['credit_sale', 'loan_given', 'debt_created'].includes(transaction.type)
        ? amount
        : ['customer_payment', 'loan_repayment', 'debt_payment'].includes(
              transaction.type,
            )
          ? -amount
          : 0
    receivables.set(
      transaction.person.id,
      (receivables.get(transaction.person.id) ?? 0) + effect,
    )
  }

  const owedToGedi = people
    .map((person) => ({ person, balance: receivables.get(person.id) ?? 0 }))
    .filter((entry) => entry.balance > 0.005)
    .sort((a, b) => b.balance - a.balance)

  const payableBalances = new Map<number, number>()
  for (const transaction of transactions.filter(
    (item) => item.status === 'posted',
  )) {
    if (!transaction.person?.id) continue
    const amount = Number(transaction.amount)
    const effect =
      ['loan_received'].includes(transaction.type)
        ? amount
        : ['loan_payment'].includes(transaction.type)
          ? -amount
          : 0
    payableBalances.set(
      transaction.person.id,
      (payableBalances.get(transaction.person.id) ?? 0) + effect,
    )
  }

  const owedByGedi = people
    .map((person) => ({ person, balance: payableBalances.get(person.id) ?? 0 }))
    .filter((entry) => entry.balance > 0.005)
    .sort((a, b) => b.balance - a.balance)

  const totalReceivable = owedToGedi.reduce(
    (sum, entry) => sum + entry.balance,
    0,
  )
  const totalPayable = owedByGedi.reduce((sum, entry) => sum + entry.balance, 0)

  const monthLabel = new Date(`${month}-01T00:00:00`).toLocaleDateString(
    'en-US',
    { month: 'long', year: 'numeric' },
  )

  return (
    <div className="page reports-page">
      <div className="page-header">
        <div>
          <p className="eyebrow">Financial Reports</p>
          <h1>Reports</h1>
          <p className="muted">
            Review income, expenses, account activity, receivables and payables.
          </p>
        </div>

        <button
          type="button"
          className="secondary-button"
          onClick={() => void loadReports()}
          disabled={loading}
        >
          <RefreshCw size={17} />
          {loading ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <section className="panel report-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Report Filters</h2>
            <p>Choose the month and optional person or account.</p>
          </div>
        </div>

        <div className="form-grid report-filter-grid">
          <label>
            <span>Month</span>
            <input
              type="month"
              value={month}
              onChange={(event) => setMonth(event.target.value)}
            />
          </label>

          <label>
            <span>Person</span>
            <select
              value={personId}
              onChange={(event) => setPersonId(event.target.value)}
            >
              <option value="all">All people</option>
              {people.map((person) => (
                <option key={person.id} value={person.id}>
                  {person.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Account</span>
            <select
              value={accountId}
              onChange={(event) => setAccountId(event.target.value)}
            >
              <option value="all">All accounts</option>
              {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                  {account.name}
                </option>
              ))}
            </select>
          </label>
        </div>
      </section>

      <section className="report-summary-panel panel">
        <div className="panel-header">
          <div>
            <h2>{monthLabel}</h2>
            <p>Monthly financial summary</p>
          </div>
        </div>

        <div className="report-summary-grid">
          <div className="report-summary-card">
            <span>Monthly Income</span>
            <strong className={moneyToneClass(totalIncome)}>{loading ? '...' : formatMoney(totalIncome)}</strong>
            <small>Sales and other income</small>
          </div>
          <div className="report-summary-card">
            <span>Monthly Expenses</span>
            <strong className={moneyToneClass(-totalExpenses)}>{loading ? '...' : formatMoney(totalExpenses)}</strong>
            <small>Posted expenses</small>
          </div>
          <div className="report-summary-card">
            <span>Net</span>
            <strong className={moneyToneClass(netIncome)}>
              {loading ? '...' : formatMoney(netIncome)}
            </strong>
            <small>Income minus expenses</small>
          </div>
        </div>

        <div className="report-detail-grid">
          <div className="report-detail-item">
            <span>Sales</span>
            <strong className={moneyToneClass(salesIncome)}>{formatMoney(salesIncome)}</strong>
          </div>
          <div className="report-detail-item">
            <span>Credit Sales</span>
            <strong className={moneyToneClass(creditSales)}>{formatMoney(creditSales)}</strong>
          </div>
          <div className="report-detail-item">
            <span>Customer Payments</span>
            <strong className={moneyToneClass(customerPayments)}>{formatMoney(customerPayments)}</strong>
          </div>
          <div className="report-detail-item">
            <span>Today's Transactions</span>
            <strong>{todayTransactions.length}</strong>
          </div>
        </div>
      </section>

      <div className="report-two-column">
        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Expenses by Category</h2>
              <p>{monthLabel}</p>
            </div>
          </div>

          {expenseByCategory.length === 0 ? (
            <div className="empty-state">
              <BarChart3 size={32} />
              <h3>No expenses</h3>
              <p>No posted expenses match the selected filters.</p>
            </div>
          ) : (
            <div className="report-list">
              {expenseByCategory.map(([category, amount]) => (
                <div className="report-list-row" key={category}>
                  <span>{category}</span>
                  <strong className={moneyToneClass(-amount)}>{formatMoney(amount)}</strong>
                </div>
              ))}
            </div>
          )}
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Account Activity</h2>
              <p>Money movement during {monthLabel}.</p>
            </div>
          </div>

          {accountActivity.length === 0 ? (
            <div className="empty-state">
              <Wallet size={32} />
              <h3>No account activity</h3>
              <p>No posted account transactions match the selected filters.</p>
            </div>
          ) : (
            <div className="report-list">
              {accountActivity.map(({ account, moneyIn, moneyOut, activity }) => (
                <div className="report-account-row" key={account.id}>
                  <div className="report-account-main">
                    <strong className="report-account-name">{account.name}</strong>
                    <span className="report-account-count">{activity} transaction(s)</span>
                  </div>
                  <div className="report-account-values">
                    <span className={moneyToneClass(moneyIn)}>In {formatMoney(moneyIn)}</span>
                    <span className={moneyToneClass(-moneyOut)}>Out {formatMoney(moneyOut)}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      <div className="report-two-column">
        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Money Owed to Gedi</h2>
              <p>Current outstanding customer and borrower balances.</p>
            </div>
            <strong className={moneyToneClass(totalReceivable)}>{formatMoney(totalReceivable)}</strong>
          </div>

          {owedToGedi.length === 0 ? (
            <div className="empty-state compact-empty">
              <h3>No receivables</h3>
              <p>No one currently owes Gedi money.</p>
            </div>
          ) : (
            <div className="report-list">
              {owedToGedi.map(({ person, balance }) => (
                <div className="report-list-row money-owed-row" key={person.id}>
                  <div className="money-owed-person">
                    <strong className="money-owed-name">{person.name}</strong>
                    <span className="money-owed-role">{person.roles.join(', ') || 'contact'}</span>
                  </div>
                  <strong className={`money-owed-amount ${moneyToneClass(balance)}`}>{formatMoney(balance)}</strong>
                </div>
              ))}
            </div>
          )}
        </section>

        <section className="panel">
          <div className="panel-header">
            <div>
              <h2>Money Gedi Owes</h2>
              <p>Current outstanding balances payable by the business.</p>
            </div>
            <strong className={moneyToneClass(-totalPayable)}>{formatMoney(totalPayable)}</strong>
          </div>

          {owedByGedi.length === 0 ? (
            <div className="empty-state compact-empty">
              <h3>No payables</h3>
              <p>Gedi does not currently owe anyone money.</p>
            </div>
          ) : (
            <div className="report-list">
              {owedByGedi.map(({ person, balance }) => (
                <div className="report-list-row money-owed-row" key={person.id}>
                  <div className="money-owed-person">
                    <strong className="money-owed-name">{person.name}</strong>
                    <span className="money-owed-role">{person.roles.join(', ') || 'contact'}</span>
                  </div>
                  <strong className={`money-owed-amount ${moneyToneClass(-balance)}`}>{formatMoney(balance)}</strong>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      <section className="panel report-activity-panel">
        <div className="panel-header">
          <div>
            <h2>Today's Transactions</h2>
            <p>{todayTransactions.length} posted transaction(s) for today.</p>
          </div>
        </div>

        {todayTransactions.length === 0 ? (
          <div className="empty-state">
            <ReceiptText size={32} />
            <h3>No transactions today</h3>
            <p>Transactions for the selected month will appear here when posted.</p>
          </div>
        ) : (
          <div className="sales-table-wrapper report-today-table-wrapper">
            <table className="sales-table report-activity-table report-today-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Time</th>
                  <th>Description</th>
                  <th>Type</th>
                  <th>Person</th>
                  <th>Account</th>
                  <th>Amount</th>
                </tr>
              </thead>
              <tbody>
                {todayTransactions.map((transaction) => (
                  <tr key={transaction.id}>
                    <td>{formatDate(transaction.transaction_date)}</td>
                    <td>{formatTime(transactionTimestamp(transaction))}</td>
                    <td>{transaction.description}</td>
                    <td>{formatTransactionType(transaction.type)}</td>
                    <td>{transaction.person?.name || 'General'}</td>
                    <td>{transaction.account?.name || 'Credit'}</td>
                    <td><strong className={moneyToneClass(transactionCashEffect(transaction))}>{formatMoney(transaction.amount)}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="report-today-cards">
              {todayTransactions.map((transaction) => (
                <article className="report-today-card" key={transaction.id}>
                  <div className="report-today-card-header">
                    <span>{formatDate(transaction.transaction_date)} · {formatTime(transactionTimestamp(transaction))}</span>
                    <strong className={moneyToneClass(transactionCashEffect(transaction))}>{formatMoney(transaction.amount)}</strong>
                  </div>
                  <div className="report-today-card-description">
                    {transaction.description}
                  </div>
                  <div className="report-today-card-meta">
                    <div>
                      <span>Type</span>
                      <strong>{formatTransactionType(transaction.type)}</strong>
                    </div>
                    <div>
                      <span>Person</span>
                      <strong>{transaction.person?.name || 'General'}</strong>
                    </div>
                    <div>
                      <span>Account</span>
                      <strong>{transaction.account?.name || 'Credit'}</strong>
                    </div>
                  </div>
                </article>
              ))}
            </div>
          </div>
        )}
      </section>

      <section className="panel report-statement-panel">
        <div className="panel-header">
          <div>
            <h2>Person Statement</h2>
            <p>View the selected person's financial activity for {monthLabel}.</p>
          </div>
        </div>

        {personId === 'all' ? (
          <div className="empty-state compact-empty">
            <Users size={32} />
            <h3>Select a person</h3>
            <p>Choose a person above to view their monthly statement.</p>
          </div>
        ) : (() => {
          const selectedPerson = people.find((person) => String(person.id) === personId)
          const statementTransactions = monthTransactions.filter(
            (transaction) => String(transaction.person?.id ?? '') === personId,
          )
          const statementBalance = transactions
            .filter(
              (transaction) =>
                transaction.status === 'posted' &&
                String(transaction.person?.id ?? '') === personId,
            )
            .reduce((sum, transaction) => {
              const amount = Number(transaction.amount)
              if (['credit_sale', 'loan_given', 'debt_created'].includes(transaction.type)) return sum + amount
              if (['customer_payment', 'loan_repayment', 'debt_payment'].includes(transaction.type)) return sum - amount
              return sum
            }, 0)

          return !selectedPerson ? (
            <div className="empty-state compact-empty">
              <h3>Person not found</h3>
            </div>
          ) : (
            <div className="report-statement-content">
              <div className="report-statement-summary">
                <div>
                  <span>Person</span>
                  <strong>{selectedPerson.name}</strong>
                </div>
                <div>
                  <span>Current Balance</span>
                  <strong className={moneyToneClass(statementBalance)}>{formatMoney(statementBalance)}</strong>
                </div>
                <div>
                  <span>Month Activity</span>
                  <strong>{statementTransactions.length}</strong>
                </div>
              </div>

              {statementTransactions.length === 0 ? (
                <div className="empty-state compact-empty">
                  <h3>No activity</h3>
                  <p>No posted transactions for this person in {monthLabel}.</p>
                </div>
              ) : (
                <div className="sales-table-wrapper">
                  <table className="sales-table report-activity-table">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Account</th>
                        <th>Description</th>
                      </tr>
                    </thead>
                    <tbody>
                      {statementTransactions.map((transaction) => (
                        <tr key={transaction.id}>
                          <td>{formatDate(transaction.transaction_date)}</td>
                          <td>{formatTime(transactionTimestamp(transaction))}</td>
                          <td>{formatTransactionType(transaction.type)}</td>
                          <td><strong className={moneyToneClass(transactionCashEffect(transaction))}>{formatMoney(transaction.amount)}</strong></td>
                          <td>{transaction.account?.name || 'Credit'}</td>
                          <td>{transaction.description}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )
        })()}
      </section>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Business Reports & Management Dashboard                            */
/* ------------------------------------------------------------------ */

const REPORT_RANGE_OPTIONS: { value: ReportDateRange; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: 'this_week', label: 'This Week' },
  { value: 'this_month', label: 'This Month' },
  { value: 'last_month', label: 'Last Month' },
  { value: 'this_year', label: 'This Year' },
  { value: 'custom', label: 'Custom' },
]

const PAYMENT_STATUS_OPTIONS = [
  { value: 'paid', label: 'Paid' },
  { value: 'partial', label: 'Partial' },
  { value: 'unpaid', label: 'Unpaid' },
]

const INVENTORY_STATUS_OPTIONS: { value: InventoryStatus; label: string }[] = [
  { value: 'in_stock', label: 'In Stock' },
  { value: 'low_stock', label: 'Low Stock' },
  { value: 'out_of_stock', label: 'Out of Stock' },
]

function formatStatusLabel(status: string) {
  return status
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

/**
 * Shared state + query-string builder for the range/from/to filter used by
 * every date-scoped business report endpoint. Mirrors how existing pages
 * (Reports, Transactions) keep filter state as plain useState values.
 */
function useReportDateRange(initial: ReportDateRange = 'this_month') {
  const [range, setRange] = useState<ReportDateRange>(initial)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  function queryParams(): URLSearchParams {
    const params = new URLSearchParams()
    if (range === 'custom') {
      if (from) params.set('from', from)
      if (to) params.set('to', to)
    } else {
      params.set('range', range)
    }
    return params
  }

  const ready = range !== 'custom' || Boolean(from && to)

  return { range, setRange, from, setFrom, to, setTo, queryParams, ready }
}

function DateRangeFilter({
  range,
  onRangeChange,
  from,
  onFromChange,
  to,
  onToChange,
}: {
  range: ReportDateRange
  onRangeChange: (value: ReportDateRange) => void
  from: string
  onFromChange: (value: string) => void
  to: string
  onToChange: (value: string) => void
}) {
  return (
    <div className="form-grid report-filter-grid">
      <label>
        <span>Date Range</span>
        <select
          value={range}
          onChange={(event) => onRangeChange(event.target.value as ReportDateRange)}
        >
          {REPORT_RANGE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      {range === 'custom' && (
        <>
          <label>
            <span>From</span>
            <input
              type="date"
              value={from}
              onChange={(event) => onFromChange(event.target.value)}
            />
          </label>
          <label>
            <span>To</span>
            <input
              type="date"
              value={to}
              onChange={(event) => onToChange(event.target.value)}
            />
          </label>
        </>
      )}
    </div>
  )
}

function ReportPagination({
  meta,
  onPageChange,
  loading,
}: {
  meta: ReportMeta
  onPageChange: (page: number) => void
  loading: boolean
}) {
  if (meta.last_page <= 1) return null

  return (
    <div className="report-pagination">
      <button
        type="button"
        className="secondary-button"
        disabled={loading || meta.current_page <= 1}
        onClick={() => onPageChange(meta.current_page - 1)}
      >
        Previous
      </button>
      <span>
        Page {meta.current_page} of {meta.last_page} &middot; {meta.total} total
      </span>
      <button
        type="button"
        className="secondary-button"
        disabled={loading || meta.current_page >= meta.last_page}
        onClick={() => onPageChange(meta.current_page + 1)}
      >
        Next
      </button>
    </div>
  )
}

const BUSINESS_REPORT_TABS: { to: string; label: string; end?: boolean }[] = [
  { to: '/reports/business', label: 'Overview', end: true },
  { to: '/reports/business/sales', label: 'Sales' },
  { to: '/reports/business/purchases', label: 'Purchases' },
  { to: '/reports/business/profit', label: 'Profit & Loss' },
  { to: '/reports/business/receivables', label: 'Receivables' },
  { to: '/reports/business/payables', label: 'Payables' },
  { to: '/reports/business/inventory', label: 'Inventory' },
]

function BusinessReportsHub() {
  return (
    <div className="page business-reports-page">
      <PageHeader
        eyebrow="Business Intelligence"
        title="Business Reports"
        description="A management view of sales, purchases, profit, credit and inventory."
      />

      <nav className="business-report-subnav" aria-label="Business report sections">
        {BUSINESS_REPORT_TABS.map(({ to, label, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `business-report-tab ${isActive ? 'business-report-tab-active' : ''}`
            }
          >
            {label}
          </NavLink>
        ))}
      </nav>

      <Outlet />
    </div>
  )
}

function BusinessDashboardPage() {
  const dateRange = useReportDateRange('this_month')
  const [summary, setSummary] = useState<BusinessSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    if (!dateRange.ready) return
    try {
      setLoading(true)
      setError('')
      const response = await apiFetch(
        `/api/reports/business-summary?${dateRange.queryParams().toString()}`,
      )
      if (!response.ok) throw new Error('Unable to load the business summary.')
      const data: BusinessSummary = await response.json()
      setSummary(data)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to load the business summary.',
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateRange.range, dateRange.from, dateRange.to])

  const f = summary?.financial
  const c = summary?.credit
  const inv = summary?.inventory
  const s = summary?.sales
  const p = summary?.purchases

  return (
    <section className="business-report-section">
      <section className="panel report-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Filters</h2>
            <p>Choose the reporting period for the financial and sales figures below.</p>
          </div>
        </div>
        <DateRangeFilter
          range={dateRange.range}
          onRangeChange={dateRange.setRange}
          from={dateRange.from}
          onFromChange={dateRange.setFrom}
          to={dateRange.to}
          onToChange={dateRange.setTo}
        />
      </section>

      {error && <div className="error-banner">{error}</div>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Financial</h2>
            <p>{summary ? `${summary.period.from} to ${summary.period.to}` : 'Sales, expenses and profit for the period.'}</p>
          </div>
        </div>
        <div className="stats-grid">
          <div className="stat-card">
            <span>Total Sales</span>
            <strong className={moneyToneClass(Number(f?.total_sales ?? 0))}>
              {loading ? '...' : formatMoney(f?.total_sales ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Cash Sales</span>
            <strong className={moneyToneClass(Number(f?.cash_sales ?? 0))}>
              {loading ? '...' : formatMoney(f?.cash_sales ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Credit Sales</span>
            <strong className={moneyToneClass(Number(f?.credit_sales ?? 0))}>
              {loading ? '...' : formatMoney(f?.credit_sales ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Customer Collections</span>
            <strong className={moneyToneClass(Number(f?.customer_collections ?? 0))}>
              {loading ? '...' : formatMoney(f?.customer_collections ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Total Expenses</span>
            <strong className={moneyToneClass(-Number(f?.total_expenses ?? 0))}>
              {loading ? '...' : formatMoney(f?.total_expenses ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Gross Profit</span>
            <strong className={moneyToneClass(Number(f?.gross_profit ?? 0))}>
              {loading ? '...' : formatMoney(f?.gross_profit ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Net Profit</span>
            <strong className={moneyToneClass(Number(f?.net_profit ?? 0))}>
              {loading ? '...' : formatMoney(f?.net_profit ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Accounts Balance</span>
            <strong className={moneyToneClass(Number(f?.accounts_balance ?? 0))}>
              {loading ? '...' : formatMoney(f?.accounts_balance ?? 0)}
            </strong>
          </div>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Credit</h2>
            <p>Outstanding balances as of now.</p>
          </div>
        </div>
        <div className="stats-grid">
          <div className="stat-card">
            <span>Accounts Receivable</span>
            <strong className={moneyToneClass(Number(c?.accounts_receivable ?? 0))}>
              {loading ? '...' : formatMoney(c?.accounts_receivable ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Accounts Payable</span>
            <strong className={moneyToneClass(-Number(c?.accounts_payable ?? 0))}>
              {loading ? '...' : formatMoney(c?.accounts_payable ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Overdue Customer Balance</span>
            <strong className={moneyToneClass(-Number(c?.overdue_customer_balance ?? 0))}>
              {loading ? '...' : formatMoney(c?.overdue_customer_balance ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Supplier Balances</span>
            <strong className={moneyToneClass(-Number(c?.supplier_balances ?? 0))}>
              {loading ? '...' : formatMoney(c?.supplier_balances ?? 0)}
            </strong>
          </div>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Inventory</h2>
            <p className="muted">Point-in-time snapshot, as of now &mdash; not affected by the date range above.</p>
          </div>
        </div>
        <div className="stats-grid">
          <div className="stat-card">
            <span>Inventory Value</span>
            <strong className={moneyToneClass(Number(inv?.inventory_value ?? 0))}>
              {loading ? '...' : formatMoney(inv?.inventory_value ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Current Stock Quantity</span>
            <strong>{loading ? '...' : (inv?.current_stock_quantity ?? 0)}</strong>
          </div>
          <div className="stat-card">
            <span>Active Products</span>
            <strong>{loading ? '...' : (inv?.active_products ?? 0)}</strong>
          </div>
          <div className="stat-card">
            <span>Low Stock Products</span>
            <strong className={moneyToneClass(-(inv?.low_stock_products ?? 0))}>
              {loading ? '...' : (inv?.low_stock_products ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Stock Requiring Attention</span>
            <strong className={moneyToneClass(-(inv?.stock_requiring_attention ?? 0))}>
              {loading ? '...' : (inv?.stock_requiring_attention ?? 0)}
            </strong>
          </div>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Sales</h2>
          </div>
        </div>
        <div className="stats-grid">
          <div className="stat-card">
            <span>Invoices</span>
            <strong>{loading ? '...' : (s?.invoices ?? 0)}</strong>
          </div>
          <div className="stat-card">
            <span>Average Sale Value</span>
            <strong className={moneyToneClass(Number(s?.average_sale_value ?? 0))}>
              {loading ? '...' : formatMoney(s?.average_sale_value ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Today's Sales</span>
            <strong className={moneyToneClass(Number(s?.today_sales ?? 0))}>
              {loading ? '...' : formatMoney(s?.today_sales ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>This Month's Sales</span>
            <strong className={moneyToneClass(Number(s?.this_month_sales ?? 0))}>
              {loading ? '...' : formatMoney(s?.this_month_sales ?? 0)}
            </strong>
          </div>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Purchases</h2>
          </div>
        </div>
        <div className="stats-grid">
          <div className="stat-card">
            <span>Purchases</span>
            <strong>{loading ? '...' : (p?.count ?? 0)}</strong>
          </div>
          <div className="stat-card">
            <span>Purchase Value</span>
            <strong className={moneyToneClass(Number(p?.purchase_value ?? 0))}>
              {loading ? '...' : formatMoney(p?.purchase_value ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Amount Paid</span>
            <strong className={moneyToneClass(Number(p?.amount_paid ?? 0))}>
              {loading ? '...' : formatMoney(p?.amount_paid ?? 0)}
            </strong>
          </div>
          <div className="stat-card">
            <span>Outstanding Supplier Balance</span>
            <strong className={moneyToneClass(-Number(p?.outstanding_supplier_balance ?? 0))}>
              {loading ? '...' : formatMoney(p?.outstanding_supplier_balance ?? 0)}
            </strong>
          </div>
        </div>
      </section>
    </section>
  )
}

function SalesReportPage() {
  const dateRange = useReportDateRange('this_month')
  const [customers, setCustomers] = useState<Person[]>([])
  const [customerId, setCustomerId] = useState('all')
  const [paymentStatus, setPaymentStatus] = useState('all')
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [report, setReport] = useState<SalesReportResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadCustomers() {
      try {
        const response = await apiFetch('/api/people?per_page=100')
        if (!response.ok) return
        const data: PeopleResponse = await response.json()
        const customerList = data.data.filter((person) => person.roles.includes('customer'))
        setCustomers(customerList.length > 0 ? customerList : data.data)
      } catch {
        // The customer filter is a convenience; leave it empty on failure.
      }
    }
    void loadCustomers()
  }, [])

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 400)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [dateRange.range, dateRange.from, dateRange.to, customerId, paymentStatus, debouncedSearch])

  async function load(pageToLoad: number) {
    if (!dateRange.ready) return
    try {
      setLoading(true)
      setError('')
      const params = dateRange.queryParams()
      if (customerId !== 'all') params.set('customer_id', customerId)
      if (paymentStatus !== 'all') params.set('payment_status', paymentStatus)
      if (debouncedSearch) params.set('search', debouncedSearch)
      params.set('page', String(pageToLoad))
      params.set('per_page', '25')

      const response = await apiFetch(`/api/reports/sales?${params.toString()}`)
      if (!response.ok) throw new Error('Unable to load the sales report.')
      const data: SalesReportResponse = await response.json()
      setReport(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load the sales report.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load(page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateRange.range, dateRange.from, dateRange.to, customerId, paymentStatus, debouncedSearch, page])

  const rows = report?.data ?? []

  return (
    <section className="business-report-section">
      <section className="panel report-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Filters</h2>
            <p>Narrow the sales invoices shown below.</p>
          </div>
        </div>
        <div className="form-grid report-filter-grid">
          <label>
            <span>Date Range</span>
            <select
              value={dateRange.range}
              onChange={(event) => dateRange.setRange(event.target.value as ReportDateRange)}
            >
              {REPORT_RANGE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          {dateRange.range === 'custom' && (
            <>
              <label>
                <span>From</span>
                <input type="date" value={dateRange.from} onChange={(event) => dateRange.setFrom(event.target.value)} />
              </label>
              <label>
                <span>To</span>
                <input type="date" value={dateRange.to} onChange={(event) => dateRange.setTo(event.target.value)} />
              </label>
            </>
          )}
          <label>
            <span>Customer</span>
            <select value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
              <option value="all">All customers</option>
              {customers.map((customer) => (
                <option key={customer.id} value={customer.id}>{customer.name}</option>
              ))}
            </select>
          </label>
          <label>
            <span>Payment Status</span>
            <select value={paymentStatus} onChange={(event) => setPaymentStatus(event.target.value)}>
              <option value="all">All statuses</option>
              {PAYMENT_STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Invoice number, customer..."
              />
            </div>
          </label>
        </div>
      </section>

      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid stats-grid-5">
        <div className="stat-card">
          <span>Total Sales</span>
          <strong className={moneyToneClass(Number(report?.summary.total_sales ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.total_sales ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Total COGS</span>
          <strong className={moneyToneClass(-Number(report?.summary.total_cogs ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.total_cogs ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Gross Profit</span>
          <strong className={moneyToneClass(Number(report?.summary.gross_profit ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.gross_profit ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Amount Collected</span>
          <strong className={moneyToneClass(Number(report?.summary.amount_collected ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.amount_collected ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Outstanding</span>
          <strong className={moneyToneClass(-Number(report?.summary.outstanding ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.outstanding ?? 0)}
          </strong>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Sales Invoices</h2>
            <p>{loading ? 'Loading...' : `${report?.meta.total ?? 0} invoice(s) match the current filters.`}</p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading sales...</div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<ReceiptText size={32} />}
            title="No sales found"
            description="Try changing the filters above."
          />
        ) : (
          <>
            <div className="sales-table-wrapper">
              <table className="sales-table">
                <thead>
                  <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance Due</th>
                    <th>COGS</th>
                    <th>Gross Profit</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td>{row.invoice_number}</td>
                      <td>{formatDate(row.date)}</td>
                      <td>{row.customer ?? 'Walk-in'}</td>
                      <td className={moneyToneClass(Number(row.total))}>{formatMoney(row.total)}</td>
                      <td className={moneyToneClass(Number(row.amount_paid))}>{formatMoney(row.amount_paid)}</td>
                      <td className={moneyToneClass(-Number(row.balance_due))}>{formatMoney(row.balance_due)}</td>
                      <td>{formatMoney(row.cogs)}</td>
                      <td className={moneyToneClass(Number(row.gross_profit))}>{formatMoney(row.gross_profit)}</td>
                      <td>
                        <span className={`status-badge status-badge-${row.payment_status}`}>
                          {formatStatusLabel(row.payment_status)}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {report?.meta && (
              <ReportPagination meta={report.meta} onPageChange={setPage} loading={loading} />
            )}
          </>
        )}
      </section>
    </section>
  )
}

function PurchasesReportPage() {
  const dateRange = useReportDateRange('this_month')
  const [paymentStatus, setPaymentStatus] = useState('all')
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [report, setReport] = useState<PurchasesReportResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 400)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [dateRange.range, dateRange.from, dateRange.to, paymentStatus, debouncedSearch])

  async function load(pageToLoad: number) {
    if (!dateRange.ready) return
    try {
      setLoading(true)
      setError('')
      const params = dateRange.queryParams()
      if (paymentStatus !== 'all') params.set('payment_status', paymentStatus)
      if (debouncedSearch) params.set('search', debouncedSearch)
      params.set('page', String(pageToLoad))
      params.set('per_page', '25')

      const response = await apiFetch(`/api/reports/purchases?${params.toString()}`)
      if (!response.ok) throw new Error('Unable to load the purchases report.')
      const data: PurchasesReportResponse = await response.json()
      setReport(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load the purchases report.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load(page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateRange.range, dateRange.from, dateRange.to, paymentStatus, debouncedSearch, page])

  const rows = report?.data ?? []

  return (
    <section className="business-report-section">
      <section className="panel report-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Filters</h2>
            <p>
              Narrow the purchases shown below. There is no supplier list endpoint yet, so
              search by supplier name instead of a dropdown.
            </p>
          </div>
        </div>
        <div className="form-grid report-filter-grid">
          <label>
            <span>Date Range</span>
            <select
              value={dateRange.range}
              onChange={(event) => dateRange.setRange(event.target.value as ReportDateRange)}
            >
              {REPORT_RANGE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          {dateRange.range === 'custom' && (
            <>
              <label>
                <span>From</span>
                <input type="date" value={dateRange.from} onChange={(event) => dateRange.setFrom(event.target.value)} />
              </label>
              <label>
                <span>To</span>
                <input type="date" value={dateRange.to} onChange={(event) => dateRange.setTo(event.target.value)} />
              </label>
            </>
          )}
          <label>
            <span>Payment Status</span>
            <select value={paymentStatus} onChange={(event) => setPaymentStatus(event.target.value)}>
              <option value="all">All statuses</option>
              {PAYMENT_STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Purchase number, supplier..."
              />
            </div>
          </label>
        </div>
      </section>

      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid stats-grid-3">
        <div className="stat-card">
          <span>Total Purchases</span>
          <strong className={moneyToneClass(Number(report?.summary.total_purchases ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.total_purchases ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Amount Paid</span>
          <strong className={moneyToneClass(Number(report?.summary.amount_paid ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.amount_paid ?? 0)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Outstanding Supplier Balance</span>
          <strong className={moneyToneClass(-Number(report?.summary.outstanding_supplier_balance ?? 0))}>
            {loading ? '...' : formatMoney(report?.summary.outstanding_supplier_balance ?? 0)}
          </strong>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Purchases</h2>
            <p>{loading ? 'Loading...' : `${report?.meta.total ?? 0} purchase(s) match the current filters.`}</p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading purchases...</div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={<Truck size={32} />}
            title="No purchases found"
            description="Try changing the filters above."
          />
        ) : (
          <>
            <div className="sales-table-wrapper">
              <table className="sales-table">
                <thead>
                  <tr>
                    <th>Purchase #</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance Due</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td>{row.purchase_number}</td>
                      <td>{formatDate(row.date)}</td>
                      <td>{row.supplier ?? 'Unknown'}</td>
                      <td className={moneyToneClass(-Number(row.total))}>{formatMoney(row.total)}</td>
                      <td className={moneyToneClass(Number(row.amount_paid))}>{formatMoney(row.amount_paid)}</td>
                      <td className={moneyToneClass(-Number(row.balance_due))}>{formatMoney(row.balance_due)}</td>
                      <td>
                        <span className={`status-badge status-badge-${row.payment_status}`}>
                          {formatStatusLabel(row.payment_status)}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {report?.meta && (
              <ReportPagination meta={report.meta} onPageChange={setPage} loading={loading} />
            )}
          </>
        )}
      </section>
    </section>
  )
}

function ProfitLossReportPage() {
  const dateRange = useReportDateRange('this_month')
  const [report, setReport] = useState<ProfitReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function load() {
    if (!dateRange.ready) return
    try {
      setLoading(true)
      setError('')
      const response = await apiFetch(`/api/reports/profit?${dateRange.queryParams().toString()}`)
      if (!response.ok) throw new Error('Unable to load the profit & loss report.')
      const data: ProfitReport = await response.json()
      setReport(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load the profit & loss report.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateRange.range, dateRange.from, dateRange.to])

  return (
    <section className="business-report-section">
      <section className="panel report-filter-panel">
        <div className="panel-header">
          <div>
            <h2>Filters</h2>
            <p>Choose the reporting period.</p>
          </div>
        </div>
        <DateRangeFilter
          range={dateRange.range}
          onRangeChange={dateRange.setRange}
          from={dateRange.from}
          onFromChange={dateRange.setFrom}
          to={dateRange.to}
          onToChange={dateRange.setTo}
        />
      </section>

      {error && <div className="error-banner">{error}</div>}

      <section className="panel report-statement-panel">
        <div className="panel-header">
          <div>
            <h2>Profit &amp; Loss Statement</h2>
            <p>{report ? `${report.period.from} to ${report.period.to}` : 'Revenue through net profit for the period.'}</p>
          </div>
        </div>

        {loading ? (
          <div className="people-loading">Loading profit &amp; loss...</div>
        ) : !report ? (
          <EmptyState
            icon={<TrendingUp size={32} />}
            title="No data"
            description="Select a period to see the profit & loss statement."
          />
        ) : (
          <div className="report-list pl-statement">
            <div className="report-list-row">
              <span>Sales Revenue (includes unpaid credit sales)</span>
              <strong className={moneyToneClass(Number(report.revenue.sales_revenue))}>
                {formatMoney(report.revenue.sales_revenue)}
              </strong>
            </div>
            <div className="report-list-row">
              <span>Cost of Goods Sold</span>
              <strong className={moneyToneClass(-Number(report.cost_of_goods_sold.cogs))}>
                {formatMoney(report.cost_of_goods_sold.cogs)}
              </strong>
            </div>
            <div className="report-list-row pl-subtotal">
              <span>Gross Profit</span>
              <strong className={moneyToneClass(Number(report.gross_profit))}>
                {formatMoney(report.gross_profit)}
              </strong>
            </div>

            {report.operating_expenses.by_category.map((expense) => (
              <div className="report-list-row pl-expense-row" key={expense.category}>
                <span>Operating Expense &mdash; {expense.category}</span>
                <strong className={moneyToneClass(-Number(expense.amount))}>
                  {formatMoney(expense.amount)}
                </strong>
              </div>
            ))}
            <div className="report-list-row">
              <span>Total Operating Expenses</span>
              <strong className={moneyToneClass(-Number(report.operating_expenses.total))}>
                {formatMoney(report.operating_expenses.total)}
              </strong>
            </div>

            <div className="report-list-row pl-subtotal">
              <span>Net Profit</span>
              <strong className={moneyToneClass(Number(report.net_profit))}>
                {formatMoney(report.net_profit)}
              </strong>
            </div>
          </div>
        )}
      </section>
    </section>
  )
}

function AgingBucketsPanel({
  title,
  description,
  aging,
}: {
  title: string
  description: string
  aging: AgingSummary | null
}) {
  if (!aging) return null

  return (
    <section className="panel aging-panel">
      <div className="panel-header">
        <div>
          <h2>{title}</h2>
          <p>{description}</p>
        </div>
      </div>
      <div className="aging-buckets">
        {aging.buckets.map((bucket) => (
          <div className="aging-bucket" key={bucket.label}>
            <span>{bucket.label} days</span>
            <strong className={moneyToneClass(Number(bucket.outstanding))}>{formatMoney(bucket.outstanding)}</strong>
          </div>
        ))}
      </div>
    </section>
  )
}

function CustomerReceivablesPage() {
  const [rows, setRows] = useState<ReceivablePayableRow[]>([])
  const [aging, setAging] = useState<AgingSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')

  useEffect(() => {
    async function load() {
      try {
        setLoading(true)
        setError('')
        const response = await apiFetch('/api/reports/customer-receivables')
        if (!response.ok) throw new Error('Unable to load customer receivables.')
        const data: { data: ReceivablePayableRow[]; aging: AgingSummary } = await response.json()
        setRows(data.data)
        setAging(data.aging)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Unable to load customer receivables.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [])

  const filteredRows = rows.filter((row) => {
    if (!search.trim()) return true
    const haystack = `${row.name} ${row.customer_code ?? ''}`.toLowerCase()
    return haystack.includes(search.trim().toLowerCase())
  })

  const totalBalance = filteredRows.reduce((sum, row) => sum + Number(row.balance), 0)

  return (
    <section className="business-report-section">
      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid stats-grid-2">
        <div className="stat-card">
          <span>Customers with a Balance</span>
          <strong>{loading ? '...' : filteredRows.length}</strong>
        </div>
        <div className="stat-card">
          <span>Total Receivable</span>
          <strong className={moneyToneClass(totalBalance)}>
            {loading ? '...' : formatMoney(totalBalance)}
          </strong>
        </div>
      </div>

      <AgingBucketsPanel title="Receivable Aging" description="How long customer balances have been outstanding, by sale date." aging={aging} />

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Customer Receivables</h2>
            <p>Customers who currently owe Gedi money, highest balance first.</p>
          </div>
        </div>

        <div className="form-grid report-filter-grid">
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Customer name or code..."
              />
            </div>
          </label>
        </div>

        {loading ? (
          <div className="people-loading">Loading receivables...</div>
        ) : filteredRows.length === 0 ? (
          <EmptyState
            icon={<Users size={32} />}
            title="No receivables"
            description="No customers currently owe Gedi money."
          />
        ) : (
          <div className="sales-table-wrapper">
            <table className="sales-table">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Code</th>
                  <th>Credit Limit</th>
                  <th>Balance</th>
                  <th>Available Credit</th>
                </tr>
              </thead>
              <tbody>
                {filteredRows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.name}</td>
                    <td>{row.customer_code ?? '—'}</td>
                    <td>{row.credit_limit !== null ? formatMoney(row.credit_limit) : '—'}</td>
                    <td className={moneyToneClass(Number(row.balance))}>{formatMoney(row.balance)}</td>
                    <td>{row.available_credit !== null ? formatMoney(row.available_credit) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </section>
  )
}

function SupplierPayablesPage() {
  const [rows, setRows] = useState<ReceivablePayableRow[]>([])
  const [aging, setAging] = useState<AgingSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')

  useEffect(() => {
    async function load() {
      try {
        setLoading(true)
        setError('')
        const response = await apiFetch('/api/reports/supplier-payables')
        if (!response.ok) throw new Error('Unable to load supplier payables.')
        const data: { data: ReceivablePayableRow[]; aging: AgingSummary } = await response.json()
        setRows(data.data)
        setAging(data.aging)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Unable to load supplier payables.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [])

  const filteredRows = rows.filter((row) => {
    if (!search.trim()) return true
    const haystack = `${row.name} ${row.supplier_code ?? ''}`.toLowerCase()
    return haystack.includes(search.trim().toLowerCase())
  })

  const totalBalance = filteredRows.reduce((sum, row) => sum + Number(row.balance), 0)

  return (
    <section className="business-report-section">
      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid stats-grid-2">
        <div className="stat-card">
          <span>Suppliers with a Balance</span>
          <strong>{loading ? '...' : filteredRows.length}</strong>
        </div>
        <div className="stat-card">
          <span>Total Payable</span>
          <strong className={moneyToneClass(-totalBalance)}>
            {loading ? '...' : formatMoney(totalBalance)}
          </strong>
        </div>
      </div>

      <AgingBucketsPanel title="Payable Aging" description="How long supplier balances have been outstanding, by purchase date." aging={aging} />

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Supplier Payables</h2>
            <p>Suppliers Gedi currently owes money to, highest balance first.</p>
          </div>
        </div>

        <div className="form-grid report-filter-grid">
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Supplier name or code..."
              />
            </div>
          </label>
        </div>

        {loading ? (
          <div className="people-loading">Loading payables...</div>
        ) : filteredRows.length === 0 ? (
          <EmptyState
            icon={<Truck size={32} />}
            title="No payables"
            description="Gedi does not currently owe any supplier money."
          />
        ) : (
          <div className="sales-table-wrapper">
            <table className="sales-table">
              <thead>
                <tr>
                  <th>Supplier</th>
                  <th>Code</th>
                  <th>Credit Limit</th>
                  <th>Balance</th>
                  <th>Available Credit</th>
                </tr>
              </thead>
              <tbody>
                {filteredRows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.name}</td>
                    <td>{row.supplier_code ?? '—'}</td>
                    <td>{row.credit_limit !== null ? formatMoney(row.credit_limit) : '—'}</td>
                    <td className={moneyToneClass(-Number(row.balance))}>{formatMoney(row.balance)}</td>
                    <td>{row.available_credit !== null ? formatMoney(row.available_credit) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </section>
  )
}

function InventoryReportPage() {
  const [rows, setRows] = useState<InventoryRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | InventoryStatus>('all')

  useEffect(() => {
    async function load() {
      try {
        setLoading(true)
        setError('')
        const response = await apiFetch('/api/reports/inventory')
        if (!response.ok) throw new Error('Unable to load the inventory report.')
        const data: { data: InventoryRow[] } = await response.json()
        setRows(data.data)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Unable to load the inventory report.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [])

  const filteredRows = rows.filter((row) => statusFilter === 'all' || row.status === statusFilter)
  const totalValue = filteredRows.reduce((sum, row) => sum + Number(row.inventory_value), 0)

  return (
    <section className="business-report-section">
      {error && <div className="error-banner">{error}</div>}

      <div className="stats-grid stats-grid-3">
        <div className="stat-card">
          <span>Products</span>
          <strong>{loading ? '...' : filteredRows.length}</strong>
        </div>
        <div className="stat-card">
          <span>Inventory Value</span>
          <strong className={moneyToneClass(totalValue)}>
            {loading ? '...' : formatMoney(totalValue)}
          </strong>
        </div>
        <div className="stat-card">
          <span>Low / Out of Stock</span>
          <strong className={moneyToneClass(-rows.filter((row) => row.status !== 'in_stock').length)}>
            {loading ? '...' : rows.filter((row) => row.status !== 'in_stock').length}
          </strong>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Inventory</h2>
            <p>Current stock levels, as of now.</p>
          </div>
        </div>

        <div className="form-grid report-filter-grid">
          <label>
            <span>Status</span>
            <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as 'all' | InventoryStatus)}>
              <option value="all">All statuses</option>
              {INVENTORY_STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
        </div>

        {loading ? (
          <div className="people-loading">Loading inventory...</div>
        ) : filteredRows.length === 0 ? (
          <EmptyState
            icon={<Package size={32} />}
            title="No products found"
            description="Try changing the status filter above."
          />
        ) : (
          <div className="sales-table-wrapper">
            <table className="sales-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>SKU</th>
                  <th>Stock</th>
                  <th>Minimum Stock</th>
                  <th>Unit</th>
                  <th>Cost / Unit</th>
                  <th>Inventory Value</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {filteredRows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.product}</td>
                    <td>{row.sku}</td>
                    <td>{row.stock}</td>
                    <td>{row.minimum_stock}</td>
                    <td>{row.unit}</td>
                    <td>{row.cost_per_unit !== null ? formatMoney(row.cost_per_unit) : '—'}</td>
                    <td className={moneyToneClass(Number(row.inventory_value))}>{formatMoney(row.inventory_value)}</td>
                    <td>
                      <span className={`status-badge status-badge-${row.status}`}>
                        {formatStatusLabel(row.status)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </section>
  )
}

function ReceiptField({
  label,
  value,
  valueClassName,
}: {
  label: string
  value?: string | null
  valueClassName?: string
}) {
  if (!value) return null

  return (
    <div className="receipt-field">
      <span>{label}</span>
      <strong className={valueClassName}>{value}</strong>
    </div>
  )
}

/**
 * Printable receipt for a single posted (or voided) transaction, covering
 * every transaction type the ledger supports. Fields that don't apply to a
 * given transaction (no reference, no category, no line items, ...) are
 * simply left out rather than shown empty.
 *
 * The page renders inside the normal authenticated app shell like every
 * other route, but toggles a body class (see the print rules in App.css)
 * that hides the sidebar/topbar/nav chrome specifically while printing, so
 * "Print" / "Save as PDF" from the browser produces just the document.
 */
function TransactionReceipt() {
  const { transactionId } = useParams()
  const navigate = useNavigate()

  const [data, setData] = useState<ReceiptResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    document.body.classList.add('receipt-print-mode')
    return () => {
      document.body.classList.remove('receipt-print-mode')
    }
  }, [])

  useEffect(() => {
    async function loadReceipt() {
      if (!transactionId) {
        setError('Transaction not found.')
        setLoading(false)
        return
      }

      try {
        setLoading(true)
        setError('')

        const response = await apiFetch(`/api/transactions/${transactionId}/receipt`)

        if (!response.ok) {
          throw new Error(
            response.status === 404
              ? 'Receipt not found.'
              : 'Unable to load receipt.',
          )
        }

        const receiptData: ReceiptResponse = await response.json()
        setData(receiptData)
      } catch (err) {
        setError(
          err instanceof Error ? err.message : 'Unable to load receipt.',
        )
      } finally {
        setLoading(false)
      }
    }

    void loadReceipt()
  }, [transactionId])

  if (loading) {
    return (
      <div className="page receipt-page">
        <div className="people-loading">Loading receipt...</div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="page receipt-page">
        <div className="error-banner">{error || 'Receipt not found.'}</div>
        <button
          type="button"
          className="secondary-button"
          onClick={() => navigate('/transactions')}
        >
          <ArrowLeft size={16} />
          Back to Transactions
        </button>
      </div>
    )
  }

  const { transaction, receipt_number: receiptNumber, business_name: businessName, generated_at: generatedAt } = data

  const cashEffect = transactionCashEffect(transaction)
  const timestamp = transactionTimestamp(transaction)
  const isTransfer = transaction.type === 'account_transfer'
  const isSupplierParty = ['purchase', 'supplier_payment'].includes(transaction.type)

  const personLabel = isSupplierParty
    ? 'Supplier'
    : ['cash_sale', 'credit_sale', 'customer_payment'].includes(transaction.type)
      ? 'Customer'
      : 'Person'

  const partyName = isSupplierParty ? transaction.supplier?.name : transaction.person?.name

  const partyBalanceEffect = isSupplierParty
    ? Number(transaction.supplier_balance_effect ?? 0)
    : Number(transaction.person_balance_effect)

  return (
    <div className="page receipt-page">
      <div className="receipt-actions">
        <button
          type="button"
          className="secondary-button"
          onClick={() => navigate('/transactions')}
        >
          <ArrowLeft size={16} />
          Back to Transactions
        </button>
        <button
          type="button"
          className="primary-button"
          onClick={() => window.print()}
        >
          <Printer size={16} />
          Print / Save as PDF
        </button>
      </div>

      <div className="receipt-document panel">
        <div className="receipt-header">
          <div className="receipt-brand">
            <img src={logoIcon} alt="Gedi Finance" className="brand-mark" />
            <div>
              <strong>{businessName || 'Gedi Finance'}</strong>
              <span>Transaction Receipt</span>
            </div>
          </div>

          <div className="receipt-header-meta">
            <span>Receipt No.</span>
            <strong>{receiptNumber}</strong>
            <small>
              Generated {formatDate(generatedAt)} · {formatTime(generatedAt)}
            </small>
          </div>
        </div>

        <div className="receipt-id-grid">
          <div className="receipt-id-item">
            <span>Transaction Number</span>
            <strong>{transaction.transaction_number}</strong>
          </div>
          <div className="receipt-id-item">
            <span>Transaction Type</span>
            <strong>{formatTransactionType(transaction.type)}</strong>
          </div>
          <div className="receipt-id-item">
            <span>Transaction Date</span>
            <strong>
              {formatDate(transaction.transaction_date)} · {formatTime(timestamp)}
            </strong>
          </div>
          <div className="receipt-id-item">
            <span>Status</span>
            <strong className={`receipt-status receipt-status-${transaction.status}`}>
              {formatTransactionType(transaction.status)}
            </strong>
          </div>
        </div>

        <div className="receipt-divider" />

        <div className="receipt-details">
          {isTransfer ? (
            <div className="receipt-transfer-row">
              <div className="receipt-transfer-account">
                <span>From Account</span>
                <strong>{transaction.account?.name || 'Not specified'}</strong>
              </div>
              <ArrowRight size={18} className="receipt-transfer-arrow" />
              <div className="receipt-transfer-account">
                <span>To Account</span>
                <strong>{transaction.destination_account?.name || 'Not specified'}</strong>
              </div>
            </div>
          ) : (
            <>
              <ReceiptField label={personLabel} value={partyName} />
              <ReceiptField label="Account" value={transaction.account?.name} />
            </>
          )}

          <ReceiptField label="Category" value={transaction.category?.name} />
          <ReceiptField label="Description" value={transaction.description} />
          <ReceiptField label="Reference" value={transaction.reference} />
          <ReceiptField label="Currency" value={transaction.currency} />

          <ReceiptField
            label="Amount"
            value={formatMoney(transaction.amount)}
            valueClassName={moneyToneClass(cashEffect)}
          />

          {Math.abs(partyBalanceEffect) > 0.005 && partyName && (
            <ReceiptField
              label={`Balance Effect (${partyName})`}
              value={formatSignedMoney(partyBalanceEffect)}
              valueClassName={moneyToneClass(partyBalanceEffect)}
            />
          )}
        </div>

        {transaction.items.length > 0 && (
          <div className="receipt-items">
            <h3>Line Items</h3>
            <div className="receipt-items-table-wrapper">
              <table className="receipt-items-table">
                <thead>
                  <tr>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {transaction.items.map((item) => (
                    <tr key={item.id}>
                      <td>{item.description}</td>
                      <td>{item.quantity}</td>
                      <td>{formatMoney(item.unit_price)}</td>
                      <td>{formatMoney(item.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        <div className="receipt-divider" />

        <div className="receipt-footer">
          <div className="receipt-footer-meta">
            <div>
              <span>Created By</span>
              <strong>{transaction.creator?.name || 'System'}</strong>
            </div>
            <div>
              <span>Created</span>
              <strong>
                {formatDate(transaction.created_at || transaction.transaction_date)} · {formatTime(timestamp)}
              </strong>
            </div>
            <div>
              <span>Status</span>
              <strong>{formatTransactionType(transaction.status)}</strong>
            </div>
          </div>

          <p className="receipt-thanks">
            Internal record - {businessName || 'Gedi Finance'} wholesale business ledger.
          </p>

          <div className="receipt-signature">
            <span className="receipt-signature-line" />
            <span>Authorized Signature</span>
          </div>
        </div>
      </div>
    </div>
  )
}

/**
 * Mobile-only navigation hub. The bottom nav bar only has room for 4 tabs
 * (Home/Sales/Customers/More), so this is where the rest of the app's
 * business areas live on a phone - every row is an existing route, nothing
 * new. Reachable at /more; on desktop the full sidebar already shows all
 * of this, so this route is simply unused there.
 */
function MorePage() {
  const { isSuperAdmin } = useAuth()

  const items = [
    { to: '/purchases', label: 'Purchases', description: 'Stock received from suppliers.', icon: Truck },
    { to: '/products', label: 'Inventory', description: 'Product catalog, units and pricing.', icon: Package },
    { to: '/suppliers', label: 'Suppliers', description: 'Vendors you purchase stock from.', icon: UserPlus },
    { to: '/loans', label: 'Loans', description: 'Money we gave out, and money we borrowed.', icon: ArrowDownLeft },
    { to: '/accounts', label: 'Accounts', description: 'Cash, bank and mobile money balances.', icon: Wallet },
    { to: '/reports/business', label: 'Reports', description: 'Sales, profit, receivables and payables.', icon: TrendingUp },
    { to: '/transactions', label: 'Transaction History', description: 'The full ledger, for audit.', icon: CreditCard },
    { to: '/settings', label: 'Settings', description: 'Account, password and appearance.', icon: SettingsIcon },
    ...(isSuperAdmin
      ? [{ to: '/admin/users', label: 'User Management', description: 'Add and manage staff accounts.', icon: ShieldCheck }]
      : []),
  ]

  return (
    <div className="page">
      <PageHeader eyebrow="Gedi Finance" title="More" description="The rest of the business, one tap away." />

      <section className="panel more-menu">
        {items.map(({ to, label, description, icon: Icon }) => (
          <Link className="more-menu-row" to={to} key={to}>
            <div className="more-menu-icon">
              <Icon size={20} />
            </div>
            <div className="more-menu-text">
              <strong>{label}</strong>
              <span>{description}</span>
            </div>
            <ArrowRight size={18} className="more-menu-chevron" />
          </Link>
        ))}
      </section>
    </div>
  )
}

function SettingsPage() {
  const { user, refreshUser, logout } = useAuth()
  const { mode, setMode } = useTheme()
  const [appearanceOpen, setAppearanceOpen] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [passwordOpen, setPasswordOpen] = useState(false)

  const appearanceLabel =
    mode === 'system' ? 'System preference' : mode === 'light' ? 'Light' : 'Dark'

  return (
    <div className="page">
      <PageHeader
        eyebrow="System"
        title="Settings"
        description="Manage account preferences, financial defaults, and account details."
      />

      <section className="panel">
        <div className="settings-list">
          <button
            className="settings-row"
            type="button"
            aria-expanded={accountOpen}
            aria-controls="account-information-panel"
            onClick={() => setAccountOpen((open) => !open)}
          >
            <span>Account Information</span>
            <span className="settings-row-value">
              {user?.name || ''}
              <ArrowRight size={16} className={accountOpen ? 'settings-chevron-open' : ''} aria-hidden="true" />
            </span>
          </button>

          {accountOpen && (
            <AccountInformationForm
              id="account-information-panel"
              onSaved={async () => {
                await refreshUser()
                setAccountOpen(false)
              }}
            />
          )}

          <button
            className="settings-row"
            type="button"
            aria-expanded={passwordOpen}
            aria-controls="password-panel"
            onClick={() => setPasswordOpen((open) => !open)}
          >
            <span>Password</span>
            <ArrowRight size={16} className={passwordOpen ? 'settings-chevron-open' : ''} aria-hidden="true" />
          </button>

          {passwordOpen && (
            <SettingsPasswordForm id="password-panel" onSaved={() => setPasswordOpen(false)} />
          )}

          <div className="settings-row settings-row-static" aria-disabled="true">
            <span>Currency</span>
            <span className="settings-row-value">
              USD
              <small className="settings-coming-soon">Coming soon</small>
            </span>
          </div>

          <button
            className="settings-row settings-appearance-row"
            type="button"
            aria-expanded={appearanceOpen}
            aria-controls="appearance-options"
            onClick={() => setAppearanceOpen((open) => !open)}
          >
            <span>Appearance</span>
            <span className="settings-row-value">
              {appearanceLabel}
              <ArrowRight
                size={16}
                className={appearanceOpen ? 'settings-chevron-open' : ''}
                aria-hidden="true"
              />
            </span>
          </button>

          {appearanceOpen && (
            <div id="appearance-options" className="appearance-options" role="radiogroup" aria-label="Choose appearance">
              {THEME_OPTIONS.map(({ value, label, icon: Icon }) => (
                <button
                  key={value}
                  type="button"
                  className={`appearance-option ${mode === value ? 'appearance-option-active' : ''}`}
                  role="radio"
                  aria-checked={mode === value}
                  onClick={() => {
                    setMode(value)
                    setAppearanceOpen(false)
                  }}
                >
                  <span className="appearance-option-icon">
                    <Icon size={18} aria-hidden="true" />
                  </span>
                  <span className="appearance-option-copy">
                    <strong>{value === 'system' ? 'System preference' : value === 'light' ? 'Light' : 'Dark'}</strong>
                    <small>{label}</small>
                  </span>
                  <span className="appearance-radio" aria-hidden="true">
                    <span />
                  </span>
                </button>
              ))}
            </div>
          )}

          <div className="settings-row settings-row-static" aria-disabled="true">
            <span>Receipt Preferences</span>
            <small className="settings-coming-soon">Coming soon</small>
          </div>

          <button className="settings-row danger-row" type="button" onClick={() => void logout()}>
            <span>Sign Out</span>
            <ArrowRight size={16} />
          </button>
        </div>
      </section>
    </div>
  )
}

function AccountInformationForm({ id, onSaved }: { id: string; onSaved: () => Promise<void> }) {
  const { user } = useAuth()
  const [name, setName] = useState(user?.name || '')
  const [email, setEmail] = useState(user?.email || '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      setSaving(true)
      setError('')
      await authApi.updateProfile(name.trim(), email.trim())
      await onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to update account information.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div id={id} className="settings-expanded-panel">
      {error && <div className="error-banner form-error">{error}</div>}

      <form className="form-grid settings-form-grid" onSubmit={handleSubmit}>
        <label>
          <span>Name *</span>
          <input type="text" value={name} onChange={(event) => setName(event.target.value)} required />
        </label>

        <label>
          <span>Email *</span>
          <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
        </label>

        <div className="form-field-full settings-form-actions">
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
        </div>
      </form>
    </div>
  )
}

function SettingsPasswordForm({ id, onSaved }: { id: string; onSaved: () => void }) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setMessage('')

    if (password.length < 8) {
      setError('Your new password must be at least 8 characters.')
      return
    }

    if (password !== confirmation) {
      setError('The new passwords do not match.')
      return
    }

    try {
      setSaving(true)
      await authApi.updatePassword(currentPassword, password, confirmation)
      setMessage('Password changed successfully.')
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
      setTimeout(onSaved, 1200)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to change your password.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div id={id} className="settings-expanded-panel">
      {error && <div className="error-banner form-error">{error}</div>}
      {message && <div className="success-banner form-error">{message}</div>}

      <form className="form-grid settings-form-grid" onSubmit={handleSubmit}>
        <label>
          <span>Current Password *</span>
          <input
            type="password"
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
            autoComplete="current-password"
            required
          />
        </label>

        <div />

        <label>
          <span>New Password *</span>
          <input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="new-password"
            minLength={8}
            required
          />
        </label>

        <label>
          <span>Confirm New Password *</span>
          <input
            type="password"
            value={confirmation}
            onChange={(event) => setConfirmation(event.target.value)}
            autoComplete="new-password"
            minLength={8}
            required
          />
        </label>

        <div className="form-field-full settings-form-actions">
          <button type="submit" className="primary-button" disabled={saving}>
            {saving ? 'Updating...' : 'Update Password'}
          </button>
        </div>
      </form>
    </div>
  )
}


function ForcePasswordChangePage() {
  const { user, refreshUser, logout } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [showCurrent, setShowCurrent] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError('')
    setMessage('')

    if (password.length < 8) {
      setError('Your new password must be at least 8 characters.')
      return
    }

    if (password !== confirmation) {
      setError('The new passwords do not match.')
      return
    }

    if (password === currentPassword) {
      setError('Your new password must be different from your temporary password.')
      return
    }

    try {
      setLoading(true)
      await authApi.updatePassword(currentPassword, password, confirmation)
      await refreshUser()
      setMessage('Password changed successfully. Your account is now ready to use.')
      setCurrentPassword('')
      setPassword('')
      setConfirmation('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to change your password.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="force-password-page">
      <ThemeToggle />
      <main className="force-password-card">
        <img src={logoIconWhite} alt="Gedi Finance" className="force-password-mark" />
        <p className="eyebrow">Account security</p>
        <h1>Change your password</h1>
        <p className="force-password-intro">
          {user?.name ? `Welcome, ${user.name}. ` : ''}
          Your current password is temporary. You must choose a new password before continuing to Gedi Finance.
        </p>

        {error ? <div className="login-error" role="alert">{error}</div> : null}
        {message ? <div className="login-success" role="status">{message}</div> : null}

        <form className="force-password-form" onSubmit={submit}>
          <label className="force-password-field">
            <span>Current password</span>
            <div className="login-input-wrap login-password-wrap">
              <Lock size={16} aria-hidden="true" />
              <input
                type={showCurrent ? 'text' : 'password'}
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
                autoComplete="current-password"
                placeholder="Enter your temporary password"
                required
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowCurrent((visible) => !visible)}
                aria-label={showCurrent ? 'Hide current password' : 'Show current password'}
              >
                {showCurrent ? <EyeOff size={17} /> : <Eye size={17} />}
              </button>
            </div>
          </label>

          <label className="force-password-field">
            <span>New password</span>
            <div className="login-input-wrap login-password-wrap">
              <Lock size={16} aria-hidden="true" />
              <input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="new-password"
                placeholder="Create a new password"
                minLength={8}
                required
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-label={showPassword ? 'Hide new password' : 'Show new password'}
              >
                {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
              </button>
            </div>
          </label>

          <label className="force-password-field">
            <span>Confirm new password</span>
            <div className="login-input-wrap login-password-wrap">
              <Lock size={16} aria-hidden="true" />
              <input
                type={showConfirmation ? 'text' : 'password'}
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                autoComplete="new-password"
                placeholder="Repeat your new password"
                minLength={8}
                required
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowConfirmation((visible) => !visible)}
                aria-label={showConfirmation ? 'Hide password confirmation' : 'Show password confirmation'}
              >
                {showConfirmation ? <EyeOff size={17} /> : <Eye size={17} />}
              </button>
            </div>
          </label>

          <div className="force-password-hint">
            Use at least 8 characters. Do not reuse the temporary password.
          </div>

          <button className="login-button" type="submit" disabled={loading}>
            {loading ? 'Updating password...' : 'Update password'}
          </button>

          <button
            type="button"
            className="text-button force-password-logout"
            onClick={() => void logout()}
          >
            Sign out
          </button>
        </form>
      </main>
    </div>
  )
}


function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [rememberMe, setRememberMe] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [successMessage, setSuccessMessage] = useState('')
  const [error, setError] = useState('')

  function validateEmail(value: string) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const trimmedEmail = email.trim()
    const trimmedPassword = password.trim()

    if (!trimmedEmail || !validateEmail(trimmedEmail)) {
      setError('Please enter a valid email address.')
      setSuccessMessage('')
      return
    }

    if (!trimmedPassword || trimmedPassword.length < 8) {
      setError('Password must be at least 8 characters long.')
      setSuccessMessage('')
      return
    }

    setLoading(true)
    setError('')
    setSuccessMessage('')

    try {
      await login(trimmedEmail, trimmedPassword, rememberMe)
      setSuccessMessage('Authentication successful. Redirecting...')
      navigate('/', { replace: true })
    } catch (err) {
      const status = err instanceof Error && 'status' in err ? (err as { status?: number }).status : undefined
      // 401 (bad credentials) and 403 (account disabled) already carry a
      // specific, user-safe message from the backend - show it as-is.
      // Anything else (network failure, 500, etc.) falls back to a
      // generic message rather than leaking implementation detail.
      setError(
        status === 401 || status === 403
          ? (err as Error).message
          : status === 422
            ? 'Invalid email or password.'
            : 'Unable to sign in right now. Please try again.',
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <ThemeToggle />
      <div className="login-shell">
        <aside className="login-branding" aria-label="Gedi Finance branding">
          <img src={logoOnDark} alt="Gedi Finance" className="login-brand-logo" />
          <div className="login-brand-copy">
            <h1>Manage your finances with confidence.</h1>
            <p className="login-brand-text">
              Track transactions, accounts, people, loans and debts in one place.
            </p>
          </div>
        </aside>

        <div className="mobile-login-branding" aria-label="Gedi Finance branding">
          <img src={logoIcon} alt="Gedi Finance" className="mobile-login-brand-mark" />
          <p className="mobile-login-brand-name">GEDI FINANCE</p>
        </div>

        <main className="login-card-wrap">
          <section className="login-card" aria-labelledby="login-title">
            <div className="login-header">
              <img src={logoIcon} alt="Gedi Finance" className="login-card-mark" />
              <div>
                <p className="login-card-name">GEDI FINANCE</p>
              </div>
            </div>

            <div className="login-intro">
              <h2 id="login-title">Welcome back</h2>
              <p>Sign in to continue to your account.</p>
            </div>

            <form className="login-form" onSubmit={handleSubmit} noValidate>
              <label className="login-field" htmlFor="login-email">
                <span>Email</span>
                <div className="login-input-wrap">
                  <Mail size={16} aria-hidden="true" />
                  <input
                    id="login-email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    placeholder="name@gedi.finance"
                    aria-invalid={Boolean(error && !validateEmail(email.trim()))}
                  />
                </div>
              </label>

              <label className="login-field" htmlFor="login-password">
                <span>Password</span>
                <div className="login-input-wrap login-password-wrap">
                  <Lock size={16} aria-hidden="true" />
                  <input
                    id="login-password"
                    name="password"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    placeholder="Enter your password"
                    aria-invalid={Boolean(error && password.trim().length < 8)}
                  />
                  <button
                    type="button"
                    className="password-toggle"
                    onClick={() => setShowPassword((open) => !open)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                  </button>
                </div>
              </label>

              <div className="login-options">
                <label className="remember-me" htmlFor="remember-me">
                  <input
                    id="remember-me"
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(event) => setRememberMe(event.target.checked)}
                  />
                  <span>Remember me</span>
                </label>
                <button
                  type="button"
                  className="text-button"
                  onClick={() => navigate('/forgot-password')}
                >
                  Forgot password?
                </button>
              </div>

              {error ? <div className="login-error" role="alert">{error}</div> : null}
              {successMessage ? <div className="login-success">{successMessage}</div> : null}

              <button className="login-button" type="submit" disabled={loading}>
                {loading ? 'Signing in...' : 'Sign In'}
              </button>
            </form>

            <p className="login-footer">
              Don&apos;t have an account? <span>Contact your administrator.</span>
            </p>
          </section>
        </main>
      </div>
    </div>
  )
}

function ForgotPasswordPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await authApi.requestPasswordReset(email.trim())
      setMessage(response.message)
    } catch {
      setError('Unable to process that request right now. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <ThemeToggle />
      <section className="login-card" aria-labelledby="forgot-password-title">
        <div className="login-header"><img src={logoIcon} alt="Gedi Finance" className="login-card-mark" /><p className="login-card-name">GEDI FINANCE</p></div>
        <div className="login-intro">
          <h2 id="forgot-password-title">Reset your password</h2>
          <p>Enter your email and we will send a reset link if an account exists.</p>
        </div>
        <form className="login-form" onSubmit={submit}>
          <label className="login-field" htmlFor="forgot-email">
            <span>Email</span>
            <div className="login-input-wrap"><Mail size={16} aria-hidden="true" /><input id="forgot-email" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></div>
          </label>
          {error ? <div className="login-error" role="alert">{error}</div> : null}
          {message ? <div className="login-success" role="status">{message}</div> : null}
          <button className="login-button" type="submit" disabled={loading}>{loading ? 'Sending...' : 'Send reset link'}</button>
          <button className="text-button" type="button" onClick={() => navigate('/login')}>Back to sign in</button>
        </form>
      </section>
    </div>
  )
}

function ResetPasswordPage() {
  const navigate = useNavigate()
  const { token } = useParams()
  const [searchParams] = useSearchParams()
  const [email, setEmail] = useState(searchParams.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoading(true)
    setError('')

    if (password.length < 8) {
      setError('Password must be at least 8 characters long.')
      setLoading(false)
      return
    }

    if (password !== confirmation) {
      setError('Passwords do not match.')
      setLoading(false)
      return
    }

    try {
      const response = await authApi.resetPassword(email.trim(), token ?? '', password, confirmation)
      setMessage(response.message)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to reset your password.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <ThemeToggle />
      <section className="login-card" aria-labelledby="reset-password-title">
        <div className="login-header"><img src={logoIcon} alt="Gedi Finance" className="login-card-mark" /><p className="login-card-name">GEDI FINANCE</p></div>
        <div className="login-intro"><h2 id="reset-password-title">Create a new password</h2><p>Choose a secure password for your account.</p></div>
        {message ? (
          <div className="reset-success-state">
            <div className="login-success" role="status">Your password has been reset successfully.</div>
            <button className="login-button" type="button" onClick={() => navigate('/login')}>Sign In</button>
          </div>
        ) : (
        <form className="login-form" onSubmit={submit}>
          <label className="login-field" htmlFor="reset-email"><span>Email</span><input id="reset-email" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></label>
          <label className="login-field" htmlFor="reset-password"><span>New password</span><div className="login-input-wrap login-password-wrap"><Lock size={16} aria-hidden="true" /><input id="reset-password" type={showPassword ? 'text' : 'password'} minLength={8} required value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter new password" /><button type="button" className="password-toggle" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Hide new password' : 'Show new password'}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></div></label>
          <label className="login-field" htmlFor="reset-confirmation"><span>Confirm password</span><div className="login-input-wrap login-password-wrap"><Lock size={16} aria-hidden="true" /><input id="reset-confirmation" type={showConfirmation ? 'text' : 'password'} minLength={8} required value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Confirm new password" /><button type="button" className="password-toggle" onClick={() => setShowConfirmation((visible) => !visible)} aria-label={showConfirmation ? 'Hide password confirmation' : 'Show password confirmation'}>{showConfirmation ? <EyeOff size={17} /> : <Eye size={17} />}</button></div></label>
          {error ? <div className="login-error" role="alert">{error}</div> : null}
          <button className="login-button" type="submit" disabled={loading}>{loading ? 'Saving...' : 'Set new password'}</button>
        </form>
        )}
      </section>
    </div>
  )
}

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard access can be denied by the browser; the password is
      // still selectable/visible in the <code> element either way.
    }
  }

  return (
    <button type="button" className="icon-button" onClick={() => void handleCopy()} aria-label="Copy password">
      {copied ? <Check size={16} /> : <Copy size={16} />}
      {copied ? 'Copied' : 'Copy'}
    </button>
  )
}

function AdminUsersPage() {
  const { user: currentUser } = useAuth()

  const [users, setUsers] = useState<authApi.AdminUser[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('User')
  const [creating, setCreating] = useState(false)
  const [temporaryPassword, setTemporaryPassword] = useState('')
  const [resetResult, setResetResult] = useState<{ userName: string; password: string } | null>(null)

  async function loadUsers() {
    try {
      setLoading(true)
      setError('')
      const response = await authApi.adminUsers()
      setUsers(response.users)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to load users.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadUsers()
  }, [])

  async function handleCreate(event: FormEvent) {
    event.preventDefault()
    try {
      setCreating(true)
      setError('')
      setTemporaryPassword('')
      const response = await authApi.adminCreateUser(name, email, role)
      setTemporaryPassword(response.temporary_password)
      setName('')
      setEmail('')
      setRole('User')
      await loadUsers()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to create user.')
    } finally {
      setCreating(false)
    }
  }

  async function handleToggle(managedUser: authApi.AdminUser) {
    try {
      setError('')
      await authApi.adminUpdateUser(managedUser.id, { is_active: !managedUser.is_active })
      await loadUsers()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to update user.')
    }
  }

  async function handleRoleChange(managedUser: authApi.AdminUser, newRole: string) {
    try {
      setError('')
      await authApi.adminUpdateUser(managedUser.id, { role: newRole })
      await loadUsers()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to change role.')
    }
  }

  async function handleReset(managedUser: authApi.AdminUser) {
    try {
      setError('')
      const response = await authApi.adminResetPassword(managedUser.id)
      setResetResult({ userName: managedUser.name, password: response.temporary_password })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to reset password.')
    }
  }

  return (
    <section className="page-section">
      <div className="page-heading">
        <div>
          <p className="eyebrow">Administration</p>
          <h1>User Management</h1>
          <p className="muted-text">Manage Gedi Finance users, roles and account access.</p>
        </div>
        <button
          type="button"
          className="primary-button"
          onClick={() => {
            setShowCreate((open) => !open)
            setTemporaryPassword('')
          }}
        >
          <UserPlus size={17} />
          Add user
        </button>
      </div>

      {error && <div className="alert error-alert">{error}</div>}

      {showCreate && (
        <div className="card admin-card">
          <div className="card-header">
            <div>
              <h2>Create user</h2>
              <p className="muted-text">A temporary password will be generated for the new user.</p>
            </div>
          </div>
          <form onSubmit={handleCreate} className="form-grid">
            <label>
              <span>Name</span>
              <input value={name} onChange={(event) => setName(event.target.value)} required />
            </label>
            <label>
              <span>Email</span>
              <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
            </label>
            <label>
              <span>Role</span>
              <select value={role} onChange={(event) => setRole(event.target.value)}>
                <option value="User">User</option>
                <option value="Super Admin">Super Admin</option>
              </select>
            </label>
            <div className="form-actions">
              <button type="submit" className="primary-button" disabled={creating}>
                {creating ? 'Creating...' : 'Create user'}
              </button>
            </div>
          </form>
          {temporaryPassword && (
            <div className="admin-password-box">
              <strong>Temporary password</strong>
              <div className="admin-password-row">
                <code>{temporaryPassword}</code>
                <CopyButton value={temporaryPassword} />
              </div>
              <p>This password is shown once. Share it securely with the user. They will be required to change it after signing in.</p>
            </div>
          )}
        </div>
      )}

      <div className="card admin-card">
        <div className="card-header">
          <div>
            <h2>Users</h2>
            <p className="muted-text">{users.length} account{users.length === 1 ? '' : 's'}</p>
          </div>
          <button type="button" className="icon-button" onClick={() => void loadUsers()} aria-label="Refresh users" title="Refresh users">
            <RefreshCw size={17} />
          </button>
        </div>

        {loading ? (
          <div className="empty-state">Loading users...</div>
        ) : users.length === 0 ? (
          <div className="empty-state">No users found.</div>
        ) : (
          <div className="admin-user-list">
            {users.map((managedUser) => {
              const isCurrentUser = managedUser.id === currentUser?.id
              const currentRole = managedUser.roles[0] ?? 'User'
              return (
                <div className="admin-user-row" key={managedUser.id}>
                  <div className="admin-user-main">
                    <div className="admin-user-avatar">{managedUser.name.charAt(0).toUpperCase()}</div>
                    <div>
                      <strong>{managedUser.name}</strong>
                      <span>{managedUser.email}</span>
                    </div>
                  </div>
                  <div className="admin-user-controls">
                    <select
                      value={currentRole}
                      disabled={isCurrentUser}
                      onChange={(event) => void handleRoleChange(managedUser, event.target.value)}
                      aria-label={`Role for ${managedUser.name}`}
                    >
                      <option value="User">User</option>
                      <option value="Super Admin">Super Admin</option>
                    </select>
                    <span className={`status-badge ${managedUser.is_active ? 'status-active' : 'status-inactive'}`}>
                      {managedUser.is_active ? 'Active' : 'Inactive'}
                    </span>
                    <button
                      type="button"
                      className="secondary-button"
                      onClick={() => void handleToggle(managedUser)}
                      disabled={isCurrentUser}
                      title={isCurrentUser ? 'You cannot deactivate your own account' : managedUser.is_active ? 'Deactivate user' : 'Activate user'}
                    >
                      <Power size={16} />
                      {managedUser.is_active ? 'Disable' : 'Enable'}
                    </button>
                    <button type="button" className="secondary-button" onClick={() => void handleReset(managedUser)}>
                      <KeyRound size={16} />
                      Reset password
                    </button>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {resetResult && (
        <div className="modal-backdrop">
          <div className="modal-card">
            <div className="modal-header">
              <div>
                <h2>Password reset</h2>
                <p className="muted-text">Temporary password for {resetResult.userName}</p>
              </div>
              <button type="button" className="icon-button" onClick={() => setResetResult(null)} aria-label="Close">
                <X size={18} />
              </button>
            </div>
            <div className="admin-password-box">
              <strong>Temporary password</strong>
              <div className="admin-password-row">
                <code>{resetResult.password}</code>
                <CopyButton value={resetResult.password} />
              </div>
              <p>This password is shown once. Share it securely with the user. They will be required to change it after signing in.</p>
            </div>
            <div className="modal-actions">
              <button type="button" className="primary-button" onClick={() => setResetResult(null)}>Done</button>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}

function AuthenticatedApp({ onLogout }: { onLogout: () => void }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const { user, isSuperAdmin } = useAuth()

  if (user?.must_change_password) {
    return <ForcePasswordChangePage />
  }

  // Grouped around the actual business cycle (supplier -> purchase ->
  // inventory -> sale -> customer -> payment) rather than a flat list of
  // pages, so the sidebar itself communicates the business instead of just
  // being a menu. "Reports" now points at the fuller tabbed business-reports
  // hub (Sales/Purchases/Profit/Receivables/Payables/Inventory); the older
  // flat /reports page is superseded by that hub plus Transaction History's
  // own filters, so it's no longer duplicated in primary nav (route still
  // works if linked directly).
  const navigationGroups = [
    {
      section: null as string | null,
      items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true }],
    },
    {
      section: 'Sales & Purchases',
      items: [
        { to: '/sales', label: 'Sales', icon: ReceiptText, end: false },
        { to: '/purchases', label: 'Purchases', icon: Truck, end: false },
      ],
    },
    {
      section: 'Inventory',
      items: [{ to: '/products', label: 'Inventory', icon: Package, end: false }],
    },
    {
      section: 'People',
      items: [
        { to: '/people', label: 'Customers', icon: Users, end: false },
        { to: '/suppliers', label: 'Suppliers', icon: UserPlus, end: false },
      ],
    },
    {
      section: 'Money',
      items: [
        { to: '/loans', label: 'Loans', icon: ArrowDownLeft, end: false },
        { to: '/accounts', label: 'Accounts', icon: Wallet, end: false },
      ],
    },
    {
      section: 'Insights',
      items: [
        { to: '/reports/business', label: 'Reports', icon: TrendingUp, end: false },
        { to: '/transactions', label: 'Transaction History', icon: CreditCard, end: false },
      ],
    },
    {
      section: 'System',
      items: [
        { to: '/settings', label: 'Settings', icon: SettingsIcon, end: false },
        ...(isSuperAdmin ? [{ to: '/admin/users', label: 'User Management', icon: ShieldCheck, end: false }] : []),
      ],
    },
  ]

  const mobileNavigation = [
    { to: '/', label: 'Home', icon: LayoutDashboard, end: true },
    { to: '/sales', label: 'Sales', icon: ReceiptText },
    { to: '/people', label: 'Customers', icon: Users },
    { to: '/more', label: 'More', icon: MoreHorizontal },
  ]

  return (
    <div className="app-shell">
      <aside className={`sidebar ${mobileOpen ? 'sidebar-open' : ''}`}>
        <div className="brand">
          <img src={logoHorizontal} alt="Gedi Finance" className="brand-logo" />
          <span className="brand-tagline">Business Ledger</span>
        </div>

        <nav className="navigation">
          {navigationGroups.map((group, groupIndex) => (
            <Fragment key={group.section ?? `group-${groupIndex}`}>
              {group.section && <div className="nav-section-label">{group.section}</div>}
              {group.items.map(({ to, label, icon: Icon, end }) => (
                <NavLink
                  key={to}
                  to={to}
                  end={end}
                  className={({ isActive }) => `nav-item ${isActive ? 'nav-item-active' : ''}`}
                  onClick={() => setMobileOpen(false)}
                >
                  <Icon size={19} />
                  <span>{label}</span>
                </NavLink>
              ))}
            </Fragment>
          ))}
        </nav>

        <div className="sidebar-footer">
          <span>Gedi Finance</span>
          <small>v1.0</small>
        </div>
      </aside>

      {mobileOpen && (
        <button
          className="mobile-overlay"
          aria-label="Close menu"
          onClick={() => setMobileOpen(false)}
        />
      )}

      <main className="main-content">
        <header className="topbar">
          <button
            className="mobile-menu-button"
            onClick={() => setMobileOpen((open) => !open)}
            aria-label="Toggle menu"
          >
            {mobileOpen ? <X size={21} /> : <Menu size={21} />}
          </button>

          <div className="topbar-right">
            <div className="user-avatar">{(user?.name ?? 'G').charAt(0).toUpperCase()}</div>
            <div className="user-info">
              <strong>{user?.name ?? 'Gedi'}</strong>
              <span>{isSuperAdmin ? 'Super Admin' : 'User'}</span>
            </div>
            <button type="button" className="logout-button" onClick={onLogout}>
              Logout
            </button>
          </div>
        </header>

        <nav className="bottom-nav" aria-label="Mobile navigation">
          {mobileNavigation.map(({ to, label, icon: Icon, end }) => (
            <NavLink key={to} to={to} end={end} className={({ isActive }) => (isActive ? 'active' : '')}>
              <Icon size={18} />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <Routes>
          <Route path="/login" element={<Navigate to="/" replace />} />
          <Route path="/" element={<Dashboard />} />
          <Route path="/people" element={<People />} />
          <Route path="/people/:personId" element={<PersonDetail />} />
          <Route path="/sales" element={<Sales />} />
          <Route path="/purchases" element={<Purchases />} />
          <Route path="/suppliers" element={<Suppliers />} />
          <Route path="/products" element={<Products />} />
          <Route path="/record" element={<RecordTransaction />} />
          <Route path="/receive-payment" element={<ReceiveCustomerPaymentPage />} />
          <Route path="/pay-supplier" element={<PaySupplierPage />} />
          <Route path="/transactions" element={<Transactions />} />
          <Route path="/transactions/:transactionId/receipt" element={<TransactionReceipt />} />
          <Route path="/accounts" element={<Accounts />} />
          <Route path="/loans" element={<LoansDebts />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/reports/business" element={<BusinessReportsHub />}>
            <Route index element={<BusinessDashboardPage />} />
            <Route path="sales" element={<SalesReportPage />} />
            <Route path="purchases" element={<PurchasesReportPage />} />
            <Route path="profit" element={<ProfitLossReportPage />} />
            <Route path="receivables" element={<CustomerReceivablesPage />} />
            <Route path="payables" element={<SupplierPayablesPage />} />
            <Route path="inventory" element={<InventoryReportPage />} />
          </Route>
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/more" element={<MorePage />} />
          <Route path="/admin/users" element={isSuperAdmin ? <AdminUsersPage /> : <Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AuthProviderContent />
      </AuthProvider>
    </BrowserRouter>
  )
}

function AuthProviderContent() {
  const { loading, user, logout } = useAuth()

  if (loading) {
    return <div className="auth-loading">Checking your secure session...</div>
  }

  if (user) {
    return <AuthenticatedApp onLogout={() => void logout()} />
  }

  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password/:token" element={<ResetPasswordPage />} />
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  )
}

export default App
