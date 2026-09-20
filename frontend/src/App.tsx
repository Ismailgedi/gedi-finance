import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import {
  BrowserRouter,
  Link,
  Navigate,
  NavLink,
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
  CreditCard,
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
  Power,
  Printer,
  ReceiptText,
  RefreshCw,
  Search,
  Settings as SettingsIcon,
  ShieldCheck,
  Sun,
  UserPlus,
  Users,
  Wallet,
  X,
} from 'lucide-react'
import './App.css'
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
    credit_sales: string
  }
  receivables: {
    increases: string
    decreases: string
    outstanding: string
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
  transaction_date: string
  created_at?: string | null
  status: string
  account_balance_effect?: string
  destination_account_effect?: string
  person?: Person | null
  account?: Account | null
  destination_account?: Account | null
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

type Loan = {
  id: number
  person_id: number
  type: 'given' | 'received' | string
  principal_amount: string
  currency: string
  start_date: string
  due_date: string | null
  description: string | null
  status: string
  person?: Person | null
}

type LoansResponse = {
  current_page: number
  data: Loan[]
  last_page: number
  total: number
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
  if (!date) return 'â€”'
  const parsed = new Date(date)
  if (Number.isNaN(parsed.getTime())) return 'â€”'
  return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function transactionTimestamp(transaction: Pick<Transaction, 'transaction_date' | 'created_at'>) {
  return transaction.created_at || transaction.transaction_date
}

/**
 * One source of truth for a person's signed ledger position.
 * Positive = the person owes Gedi.
 * Negative = Gedi owes the person.
 *
 * Generic income/expense/cash sales do not change a person's receivable/payable
 * unless the transaction type explicitly represents a person-to-business
 * balance movement.
 */
function calculatePersonLedgerBalance(
  transactions: Array<Pick<PersonTransaction, 'type' | 'amount' | 'status'>>,
): number {
  return transactions
    .filter((transaction) => transaction.status === 'posted')
    .reduce((balance, transaction) => {
      const amount = Number(transaction.amount)

      if (['credit_sale', 'loan_given', 'debt_created'].includes(transaction.type)) {
        return balance + amount
      }

      if (['customer_payment', 'loan_repayment', 'loan_received', 'debt_payment'].includes(transaction.type)) {
        return balance - amount
      }

      if (transaction.type === 'loan_payment') {
        return balance + amount
      }

      return balance
    }, 0)
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
  const [people, setPeople] = useState<Person[]>([])
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadDashboard() {
      try {
        setLoading(true)
        setError('')

        const [dashboardResponse, accountsResponse, peopleResponse, transactionsResponse] =
          await Promise.all([
            apiFetch('/api/dashboard'),
            apiFetch('/api/accounts'),
            apiFetch('/api/people'),
            apiFetch('/api/transactions'),
          ])

        if (!dashboardResponse.ok || !accountsResponse.ok) {
          throw new Error('Unable to load dashboard data.')
        }

        const dashboardData: DashboardData = await dashboardResponse.json()
        const accountsData: Account[] = await accountsResponse.json()
        const peopleData: PeopleResponse = peopleResponse.ok
          ? await peopleResponse.json()
          : { current_page: 1, data: [], last_page: 1, total: 0 }
        const transactionsData: TransactionsResponse = transactionsResponse.ok
          ? await transactionsResponse.json()
          : { current_page: 1, data: [], last_page: 1, total: 0 }

        setDashboard(dashboardData)
        setAccounts(accountsData)
        setPeople(peopleData.data)
        setTransactions(transactionsData.data)
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

  const received = Number(dashboard?.today.income ?? 0) + Number(dashboard?.today.customer_payments ?? 0)
  const expenses = Number(dashboard?.today.expense ?? 0)
  const netToday = received - expenses

  const payableBalances = new Map<number, number>()
  for (const transaction of transactions.filter((item) => item.status === 'posted')) {
    if (!transaction.person?.id) continue
    const amount = Number(transaction.amount)
    const effect = transaction.type === 'loan_received'
      ? amount
      : transaction.type === 'loan_payment'
        ? -amount
        : 0
    payableBalances.set(
      transaction.person.id,
      (payableBalances.get(transaction.person.id) ?? 0) + effect,
    )
  }

  const moneyGediOwes = people.reduce((sum, person) => {
    const balance = payableBalances.get(person.id) ?? 0
    return sum + (balance > 0.005 ? balance : 0)
  }, 0)

  return (
    <div className="page">
      <PageHeader
        eyebrow="My Money"
        title="Dashboard"
        description="Record it once. Know where your money stands."
        actions={
          <button
            className="primary-button"
            type="button"
            onClick={() => navigate('/sales?record=1')}
          >
            + Record Transaction
          </button>
        }
      />

      {error && <div className="error-banner">{error}</div>}

      <section className="hero-balance">
        <span>Total Balance</span>
        <strong className={moneyToneClass(totalBalance)}>
          {loading ? 'Loading...' : formatMoney(totalBalance)}
        </strong>
      </section>

      <section className="panel" style={{ marginBottom: 20 }}>
        <div className="panel-header">
          <div>
            <h2>Accounts</h2>
            <p>Cash, bank and mobile money available to the business.</p>
          </div>
        </div>
        <div className="account-list">
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

      <nav className="quick-actions" aria-label="Quick actions">
        <Link className="quick-action" to="/record?kind=income">Receive</Link>
        <Link className="quick-action" to="/record?kind=expense">Expense</Link>
        <Link className="quick-action" to="/record?kind=loan_given">Give</Link>
        <Link className="quick-action" to="/record?kind=loan_received">Loan</Link>
        <Link className="quick-action" to="/record?kind=loan_repayment">Repay</Link>
        <Link className="quick-action" to="/record?kind=account_transfer">Transfer</Link>
      </nav>

      <div className="today-activity">
        <div className="stat-card">
          <span>Received</span>
          <strong className={moneyToneClass(received)}>
            {loading ? '...' : `+${formatMoney(received).slice(1)}`}
          </strong>
          <small>Today</small>
        </div>
        <div className="stat-card">
          <span>Expenses</span>
          <strong className={moneyToneClass(-expenses)}>
            {loading ? '...' : `-${formatMoney(expenses).slice(1)}`}
          </strong>
          <small>Today</small>
        </div>
        <div className="stat-card">
          <span>Net</span>
          <strong className={moneyToneClass(netToday)}>
            {loading
              ? '...'
              : `${netToday >= 0 ? '+' : '-'}${formatMoney(Math.abs(netToday)).slice(1)}`}
          </strong>
          <small>Today</small>
        </div>
      </div>

      <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }}>
        <div className="stat-card">
          <span>Money Owed to Gedi</span>
          <strong className={moneyToneClass(Number(dashboard?.receivables.outstanding ?? 0))}>
            {loading ? '...' : formatMoney(dashboard?.receivables.outstanding ?? 0)}
          </strong>
          <small>Receivables</small>
        </div>
        <div className="stat-card">
          <span>Money Gedi Owes</span>
          <strong className={moneyToneClass(-moneyGediOwes)}>
            {loading ? '...' : formatMoney(moneyGediOwes)}
          </strong>
          <small>Payables</small>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2>Recent Transactions</h2>
            <p>Latest activity across the ledger.</p>
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
              {formatDate(transaction.transaction_date)} Â· {formatTime(transactionTimestamp(transaction))}
            </span>
          </div>
        </Link>
      ))}
    </div>
  )
}

function People() {
  const [people, setPeople] = useState<Person[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(false)

  async function loadPeople() {
    try {
      setLoading(true)
      setError('')

      const response = await apiFetch('/api/people')

      if (!response.ok) {
        throw new Error('Unable to load people.')
      }

      const data: PeopleResponse = await response.json()

      setPeople(data.data)
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
        title="People"
        description="Customers, suppliers, borrowers and other business contacts."
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
                    <th>Address</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {people.map((person) => (
                    <PersonRow
                      key={person.id}
                      person={person}
                    />
                  ))}
                </tbody>
              </table>
            </div>
            <div className="people-mobile">
              {people.map((person) => (
                <PersonCard key={person.id} person={person} />
              ))}
            </div>
          </>
        )}
      </section>
    </div>
  )
}

function PersonCard({ person }: { person: Person }) {
  const navigate = useNavigate()

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

function PersonRow({ person }: { person: Person }) {
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

      <td>
        {person.address || 'Not provided'}
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

  useEffect(() => {
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

        // Recalculate the balance from the person's posted ledger entries
        // instead of trusting a separate, potentially stale balance formula.
        // Fetch a larger transaction page so the profile has enough history
        // to calculate the complete position. If the endpoint ignores the
        // person_id filter, we still filter by the returned person ID below.
        let ledgerTransactions: Array<Pick<PersonTransaction, 'type' | 'amount' | 'status'>> =
          personData.transactions.data

        try {
          const ledgerResponse = await apiFetch(
            `/api/transactions?person_id=${personId}&per_page=100`,
          )

          if (ledgerResponse.ok) {
            const ledgerData: TransactionsResponse = await ledgerResponse.json()
            ledgerTransactions = ledgerData.data.filter(
              (transaction) => transaction.person?.id === Number(personId),
            )
          }
        } catch {
          // Keep the transactions returned by the person endpoint as fallback.
        }

        const calculatedBalance = calculatePersonLedgerBalance(ledgerTransactions)
        setData({
          ...personData,
          balance: calculatedBalance.toFixed(2),
        })
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

    void loadPerson()
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
      <button
        className="back-button"
        onClick={() => navigate('/people')}
      >
        <ArrowLeft size={17} />
        Back to People
      </button>

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
  const [accounts, setAccounts] =
    useState<Account[]>([])
  const [transactions, setTransactions] =
    useState<Transaction[]>([])

  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] =
    useState(searchParams.get('record') === '1')
  const [error, setError] = useState('')

  async function loadSalesData() {
    try {
      setLoading(true)
      setError('')

      const [
        peopleResponse,
        accountsResponse,
        transactionsResponse,
      ] = await Promise.all([
        apiFetch('/api/people'),
        apiFetch('/api/accounts'),
        apiFetch('/api/transactions'),
      ])

      if (
        !peopleResponse.ok ||
        !accountsResponse.ok ||
        !transactionsResponse.ok
      ) {
        throw new Error(
          'Unable to load sales data.',
        )
      }

      const peopleData: PeopleResponse =
        await peopleResponse.json()

      const accountsData: Account[] =
        await accountsResponse.json()

      const transactionsData:
        TransactionsResponse =
        await transactionsResponse.json()

      setPeople(peopleData.data)
      setAccounts(accountsData)

      setTransactions(
        transactionsData.data.filter(
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

  useEffect(() => {
    setShowForm(searchParams.get('record') === '1')
  }, [searchParams])

  useEffect(() => {
    void loadSalesData()
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
          accounts={accounts}
          onClose={() =>
            setShowForm(false)
          }
          onCreated={async () => {
            setShowForm(false)
            await loadSalesData()
          }}
        />
      )}

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
  )
}

function SaleForm({
  people,
  accounts,
  onClose,
  onCreated,
}: {
  people: Person[]
  accounts: Account[]
  onClose: () => void
  onCreated: () => Promise<void>
}) {
  const navigate = useNavigate()

  const [saleType, setSaleType] =
    useState<
      'cash_sale' | 'credit_sale'
    >('credit_sale')

  const [personId, setPersonId] =
    useState('')

  const [accountId, setAccountId] =
    useState('')

  const [amount, setAmount] =
    useState('')

  const [description, setDescription] =
    useState('')

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

      const payload: Record<
        string,
        string | number
      > = {
        type: saleType,
        person_id: Number(personId),
        amount: Number(amount),
        currency: 'USD',
        description,
      }

      if (saleType === 'cash_sale') {
        payload.account_id =
          Number(accountId)
      }

      const response = await apiFetch(
        '/api/transactions',
        {
          method: 'POST',
          headers: {
            'Content-Type':
              'application/json',
            Accept:
              'application/json',
          },
          body: JSON.stringify(payload),
        },
      )

      const data =
        await response.json()

      if (!response.ok) {
        const message =
          data?.errors
            ?.person_id?.[0] ||
          data?.errors
            ?.account_id?.[0] ||
          data?.errors
            ?.amount?.[0] ||
          data?.errors
            ?.description?.[0] ||
          data?.message ||
          'Unable to record sale.'

        throw new Error(message)
      }

      const createdId = data?.transaction?.id
      await onCreated()

      if (createdId) {
        navigate(`/transactions/${createdId}/receipt`)
      }
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to record sale.',
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel form-panel">
      <div className="panel-header">
        <div>
          <h2>Record Sale</h2>

          <p>
            Choose how this wholesale
            transaction was settled.
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
            <span>Sale Type *</span>

            <select
              value={saleType}
              onChange={(event) => {
                const value =
                  event.target.value as
                    | 'cash_sale'
                    | 'credit_sale'

                setSaleType(value)

                if (
                  value ===
                  'credit_sale'
                ) {
                  setAccountId('')
                }
              }}
            >
              <option value="credit_sale">
                Credit Sale
              </option>

              <option value="cash_sale">
                Cash Sale
              </option>
            </select>
          </label>

          <label>
            <span>
              Customer *
            </span>

            <select
              value={personId}
              onChange={(event) =>
                setPersonId(
                  event.target.value,
                )
              }
              required
            >
              <option value="">
                Select customer
              </option>

              {people.map((person) => (
                <option
                  value={person.id}
                  key={person.id}
                >
                  {person.name}
                </option>
              ))}
            </select>
          </label>

          {saleType ===
            'cash_sale' && (
            <label>
              <span>
                Payment Account *
              </span>

              <select
                value={accountId}
                onChange={(event) =>
                  setAccountId(
                    event.target.value,
                  )
                }
                required
              >
                <option value="">
                  Select account
                </option>

                {accounts
                  .filter(
                    (account) =>
                      account.is_active,
                  )
                  .map((account) => (
                    <option
                      value={account.id}
                      key={account.id}
                    >
                      {account.name}
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
              onChange={(event) =>
                setAmount(
                  event.target.value,
                )
              }
              placeholder="0.00"
              required
            />
          </label>

          <label className="form-field-full">
            <span>
              Description *
            </span>

            <textarea
              value={description}
              onChange={(event) =>
                setDescription(
                  event.target.value,
                )
              }
              placeholder={
                saleType ===
                'credit_sale'
                  ? 'e.g. Wholesale supplies issued to Ahmed'
                  : 'e.g. Cash wholesale sale to Ahmed'
              }
              rows={3}
              required
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
              : 'Save Sale'}
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

function Transactions() {
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('all')
  const [personFilter, setPersonFilter] = useState('all')
  const [accountFilter, setAccountFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const searchInputRef = useRef<HTMLInputElement>(null)

  async function loadTransactions() {
    try {
      setLoading(true)
      setError('')

      const [transactionsResponse, peopleResponse, accountsResponse] =
        await Promise.all([
          apiFetch('/api/transactions?per_page=100'),
          apiFetch('/api/people'),
          apiFetch('/api/accounts'),
        ])

      if (
        !transactionsResponse.ok ||
        !peopleResponse.ok ||
        !accountsResponse.ok
      ) {
        throw new Error('Unable to load transaction data.')
      }

      const transactionsData: TransactionsResponse =
        await transactionsResponse.json()
      const peopleData: PeopleResponse = await peopleResponse.json()
      const accountsData: Account[] = await accountsResponse.json()

      let allTransactions = transactionsData.data
      if (transactionsData.last_page > transactionsData.current_page) {
        const remainingPages = Array.from(
          { length: transactionsData.last_page - transactionsData.current_page },
          (_, index) => transactionsData.current_page + index + 1,
        )
        const pageResponses = await Promise.all(
          remainingPages.map((page) =>
            apiFetch(`/api/transactions?per_page=100&page=${page}`),
          ),
        )
        const pageData = await Promise.all(
          pageResponses.map(async (response) => {
            if (!response.ok) throw new Error('Unable to load transaction data.')
            return (await response.json()) as TransactionsResponse
          }),
        )
        allTransactions = allTransactions.concat(
          ...pageData.map((page) => page.data),
        )
      }

      setTransactions(allTransactions)
      setPeople(peopleData.data)
      setAccounts(accountsData)
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

  const filteredTransactions = transactions.filter((transaction) => {
    const customer = transaction.person?.name ?? ''
    const account = transaction.account?.name ?? ''
    const type = formatTransactionType(transaction.type)
    const reference = transaction.transaction_number ?? ''
    const haystack = `${reference} ${transaction.description} ${customer} ${account} ${transaction.type} ${type}`.toLowerCase()
    const transactionDate = transaction.transaction_date.slice(0, 10)

    if (search.trim() && !haystack.includes(search.trim().toLowerCase())) {
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

  function clearFilters() {
    setSearch('')
    setTypeFilter('all')
    setPersonFilter('all')
    setAccountFilter('all')
    setStatusFilter('all')
    setFromDate('')
    setToDate('')
  }

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
          <p className="eyebrow">Financial Ledger</p>
          <h1>Transactions</h1>
          <p className="muted">
            View the complete financial history recorded in Gedi Finance.
          </p>
        </div>

        <div className="page-header-actions transaction-page-actions">
          <button
            type="button"
            className="secondary-button"
            onClick={() => {
              searchInputRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' })
              searchInputRef.current?.focus()
            }}
          >
            <Search size={16} />
            Search
          </button>
          <button
            type="button"
            className="secondary-button"
            onClick={() => void loadTransactions()}
            disabled={loading}
          >
            <RefreshCw size={16} />
            {loading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>
      </div>

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
          <span>People</span>
          <strong>{people.length}</strong>
          <small>People in the ledger</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <Wallet size={20} />
          </div>
          <span>Accounts</span>
          <strong>{accounts.length}</strong>
          <small>Configured money accounts</small>
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
          <label>
            <span>Search</span>
            <div className="input-with-icon">
              <Search size={17} />
              <input
                ref={searchInputRef}
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Reference, description, customer..."
              />
            </div>
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


function LoansDebts() {
  const navigate = useNavigate()
  const [people, setPeople] = useState<Person[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loans, setLoans] = useState<Loan[]>([])
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const [transactionType, setTransactionType] = useState('loan_given')
  const [personId, setPersonId] = useState('')
  const [accountId, setAccountId] = useState('')
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState('')

  async function loadData() {
    try {
      setLoading(true)
      setError('')

      const [peopleResponse, accountsResponse, loansResponse, transactionsResponse] =
        await Promise.all([
          apiFetch('/api/people'),
          apiFetch('/api/accounts'),
          apiFetch('/api/loans'),
          apiFetch('/api/transactions'),
        ])

      if (
        !peopleResponse.ok ||
        !accountsResponse.ok ||
        !loansResponse.ok ||
        !transactionsResponse.ok
      ) {
        throw new Error('Unable to load loans and debts data.')
      }

      const peopleData: PeopleResponse = await peopleResponse.json()
      const accountsData: Account[] = await accountsResponse.json()
      const loansData: LoansResponse = await loansResponse.json()
      const transactionsData: TransactionsResponse = await transactionsResponse.json()

      setPeople(peopleData.data)
      setAccounts(accountsData)
      setLoans(loansData.data)
      setTransactions(transactionsData.data)
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

  const balances = new Map<number, number>()
  for (const transaction of transactions) {
    if (!transaction.person?.id) continue

    const amountValue = Number(transaction.amount)
    const effect =
      transaction.type === 'credit_sale' ||
      transaction.type === 'loan_given' ||
      transaction.type === 'debt_created' ||
      transaction.type === 'loan_payment'
        ? amountValue
        : transaction.type === 'customer_payment' ||
            transaction.type === 'loan_repayment' ||
            transaction.type === 'loan_received' ||
            transaction.type === 'debt_payment'
          ? -amountValue
          : 0

    balances.set(
      transaction.person.id,
      (balances.get(transaction.person.id) ?? 0) + effect,
    )
  }

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

  const activeLoans = loans.filter((loan) => loan.status === 'active')
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
    setDescription('')
    setFormError('')
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
          transaction_date: new Date().toISOString().slice(0, 10),
          status: 'posted',
        }),
      })

      const result = await response.json()
      if (!response.ok) {
        throw new Error(result.message || 'Unable to save transaction.')
      }

      const createdId = result?.transaction?.id

      setShowForm(false)
      resetForm()
      await loadData()

      if (createdId) {
        navigate(`/transactions/${createdId}/receipt`)
      }
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
          <strong>{loading ? 'Loading...' : activeLoans.length}</strong>
          <small>Loan records currently active</small>
        </div>
      </div>

      {showForm && (
        <section className="panel loan-form-panel">
          <div className="panel-header">
            <div>
              <h2>Record Loan or Debt Transaction</h2>
              <p>Create a posted ledger entry and update the person's balance.</p>
            </div>
            <button
              type="button"
              className="secondary-button"
              onClick={() => setShowForm(false)}
              disabled={saving}
            >
              <X size={16} />
              Close
            </button>
          </div>

          {formError && <div className="error-banner">{formError}</div>}

          <form className="loan-form" onSubmit={submitTransaction}>
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
              <span>Current Balance</span>
              <input
                className={selectedPerson ? moneyToneClass(balances.get(selectedPerson.id) ?? 0) : ''}
                value={selectedPerson ? formatMoney(balances.get(selectedPerson.id) ?? 0) : 'Select a person'}
                readOnly
              />
            </label>

            <label className="form-field-full">
              <span>Description *</span>
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

            <div className="form-actions">
              <button type="button" className="secondary-button" onClick={() => setShowForm(false)} disabled={saving}>Cancel</button>
              <button type="submit" className="primary-button" disabled={saving}>
                {saving ? 'Saving...' : 'Save Transaction'}
              </button>
            </div>
          </form>
        </section>
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

function Accounts() {
  const [accounts, setAccounts] = useState<Account[]>([])
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('active')
  const [selectedAccountId, setSelectedAccountId] = useState<number | null>(null)

  async function loadAccounts() {
    try {
      setLoading(true)
      setError('')

      const [accountsResponse, transactionsResponse] = await Promise.all([
        apiFetch('/api/accounts'),
        apiFetch('/api/transactions'),
      ])

      if (!accountsResponse.ok || !transactionsResponse.ok) {
        throw new Error('Unable to load account data.')
      }

      const accountsData: Account[] = await accountsResponse.json()
      const transactionsData: TransactionsResponse =
        await transactionsResponse.json()

      setAccounts(accountsData)
      setTransactions(transactionsData.data)

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
  const totalOpeningBalance = activeAccounts.reduce(
    (total, account) => total + Number(account.opening_balance),
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
          <p className="eyebrow">Money Accounts</p>
          <h1>Accounts</h1>
          <p className="muted">
            Manage the money accounts used by Gedi Finance.
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

      <div className="stats-grid account-stats-grid">
        <div className="stat-card">
          <div className="stat-icon">
            <Wallet size={20} />
          </div>
          <span>Accounts</span>
          <strong>{loading ? '...' : accounts.length}</strong>
          <small>Configured money accounts</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <CreditCard size={20} />
          </div>
          <span>Active Accounts</span>
          <strong>{loading ? '...' : activeAccounts.length}</strong>
          <small>Currently available</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <ArrowDownLeft size={20} />
          </div>
          <span>Current Balance</span>
          <strong className={moneyToneClass(totalBalance)}>{loading ? '...' : formatMoney(totalBalance)}</strong>
          <small>Across active accounts</small>
        </div>

        <div className="stat-card">
          <div className="stat-icon">
            <ArrowUpRight size={20} />
          </div>
          <span>Opening Balance</span>
          <strong className={moneyToneClass(totalOpeningBalance)}>
            {loading ? '...' : formatMoney(totalOpeningBalance)}
          </strong>
          <small>Original configured balance</small>
        </div>
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
                <button
                  type="button"
                  className={`account-card ${
                    isSelected ? 'account-card-selected' : ''
                  }`}
                  key={account.id}
                  onClick={() =>
                    setSelectedAccountId(
                      isSelected ? null : account.id,
                    )
                  }
                >
                  <div className="account-card-top">
                    <div>
                      <span className="account-type-label">
                        {formatTransactionType(account.type)}
                      </span>
                      <h3>{account.name}</h3>
                    </div>

                    <span
                      className={`status-badge ${
                        account.is_active
                          ? 'status-badge-active'
                          : 'status-badge-inactive'
                      }`}
                    >
                      {account.is_active ? 'Active' : 'Inactive'}
                    </span>
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
                </button>
              )
            })}
          </div>
        )}
      </section>

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

      const [transactionsResponse, accountsResponse, peopleResponse] =
        await Promise.all([
          apiFetch('/api/transactions?per_page=100'),
          apiFetch('/api/accounts'),
          apiFetch('/api/people'),
        ])

      if (
        !transactionsResponse.ok ||
        !accountsResponse.ok ||
        !peopleResponse.ok
      ) {
        throw new Error('Unable to load report data.')
      }

      const transactionsData: TransactionsResponse =
        await transactionsResponse.json()
      const accountsData: Account[] = await accountsResponse.json()
      const peopleData: PeopleResponse = await peopleResponse.json()

      setTransactions(transactionsData.data)
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
                    <span>{formatDate(transaction.transaction_date)} Â· {formatTime(transactionTimestamp(transaction))}</span>
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
            <div className="brand-mark">G</div>
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
            Thank you for banking with {businessName || 'Gedi Finance'}.
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

function SettingsPage() {
  const { mode, setMode } = useTheme()
  const [appearanceOpen, setAppearanceOpen] = useState(false)

  const appearanceLabel =
    mode === 'system' ? 'System preference' : mode === 'light' ? 'Light' : 'Dark'

  return (
    <div className="page">
      <PageHeader
        eyebrow="System"
        title="Settings"
        description="Manage account preferences, financial defaults, and account details."
        actions={
          <button className="primary-button" type="button">
            Save Changes
          </button>
        }
      />

      <section className="panel">
        <div className="settings-list">
          <button className="settings-row" type="button">
            <span>Account Information</span>
            <ArrowRight size={16} />
          </button>
          <button className="settings-row" type="button">
            <span>Password</span>
            <ArrowRight size={16} />
          </button>
          <button className="settings-row" type="button">
            <span>Currency</span>
            <strong>USD</strong>
          </button>

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

          <button className="settings-row" type="button">
            <span>Receipt Preferences</span>
            <ArrowRight size={16} />
          </button>
          <button className="settings-row danger-row" type="button">
            <span>Sign Out</span>
            <ArrowRight size={16} />
          </button>
        </div>
      </section>
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
        <div className="force-password-mark">G</div>
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
      setError(err instanceof Error && 'status' in err && err.status === 422
        ? 'Invalid email or password.'
        : 'Unable to sign in right now. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <ThemeToggle />
      <div className="login-shell">
        <aside className="login-branding" aria-label="Gedi Finance branding">
          <div className="login-brand-mark">G</div>
          <div className="login-brand-copy">
            <p className="login-brand-label">GEDI FINANCE</p>
            <h1>Manage your finances with confidence.</h1>
            <p className="login-brand-text">
              Track transactions, accounts, people, loans and debts in one place.
            </p>
          </div>
        </aside>

        <div className="mobile-login-branding" aria-label="Gedi Finance branding">
          <div className="mobile-login-brand-mark">G</div>
          <p className="mobile-login-brand-name">GEDI FINANCE</p>
        </div>

        <main className="login-card-wrap">
          <section className="login-card" aria-labelledby="login-title">
            <div className="login-header">
              <div className="login-card-mark">G</div>
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
        <div className="login-header"><div className="login-card-mark">G</div><p className="login-card-name">GEDI FINANCE</p></div>
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
        <div className="login-header"><div className="login-card-mark">G</div><p className="login-card-name">GEDI FINANCE</p></div>
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
              <code>{temporaryPassword}</code>
              <p>Share this password securely with the user. They should change it after signing in.</p>
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
              <code>{resetResult.password}</code>
              <p>Share this securely with the user. They should change it after signing in.</p>
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

  const navigation = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/transactions', label: 'Transactions', icon: CreditCard },
    { to: '/people', label: 'People', icon: Users },
    { to: '/loans', label: 'Debts & Loans', icon: ArrowDownLeft },
    { to: '/accounts', label: 'Accounts', icon: Wallet },
    { to: '/reports', label: 'Reports', icon: BarChart3 },
    { to: '/settings', label: 'Settings', icon: SettingsIcon },
    ...(isSuperAdmin ? [{ to: '/admin/users', label: 'User Management', icon: ShieldCheck }] : []),
  ]

  const mobileNavigation = [
    { to: '/', label: 'Home', icon: LayoutDashboard, end: true },
    { to: '/transactions', label: 'Transactions', icon: CreditCard },
    { to: '/people', label: 'People', icon: Users },
    { to: '/settings', label: 'More', icon: MoreHorizontal },
  ]

  return (
    <div className="app-shell">
      <aside className={`sidebar ${mobileOpen ? 'sidebar-open' : ''}`}>
        <div className="brand">
          <div className="brand-mark">G</div>
          <div>
            <strong>Gedi Finance</strong>
            <span>Business Ledger</span>
          </div>
        </div>

        <nav className="navigation">
          {navigation.map(({ to, label, icon: Icon, end }) => (
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
          <Route path="/transactions" element={<Transactions />} />
          <Route path="/transactions/:transactionId/receipt" element={<TransactionReceipt />} />
          <Route path="/accounts" element={<Accounts />} />
          <Route path="/loans" element={<LoansDebts />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/settings" element={<SettingsPage />} />
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
