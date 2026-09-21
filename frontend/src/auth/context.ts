import { createContext } from 'react'
import type { AuthUser } from './authApi'

export interface AuthContextValue {
  user: AuthUser | null
  loading: boolean
  isAuthenticated: boolean
  isSuperAdmin: boolean
  login: (email: string, password: string, remember: boolean) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
  updateUser: (name: string, email: string) => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
