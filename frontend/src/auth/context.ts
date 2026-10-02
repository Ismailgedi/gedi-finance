import { createContext } from 'react'
import type { AuthUser } from './authApi'

export interface AuthContextValue {
  user: AuthUser | null
  loading: boolean
  isAuthenticated: boolean
  isSuperAdmin: boolean
  isManager: boolean
  isFinance: boolean
  isSalesInventory: boolean
  // The generic mechanism - prefer this for a specific permission over
  // inventing a new role-name boolean, so UI gating stays driven by what
  // the backend actually enforces on the matching route, not by a role
  // name the backend never checks itself.
  can: (permission: string) => boolean
  login: (email: string, password: string, remember: boolean) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
  updateUser: (name: string, email: string) => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
