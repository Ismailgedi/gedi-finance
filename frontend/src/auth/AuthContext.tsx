import { useEffect, useState, type ReactNode } from 'react'
import * as authApi from './authApi'
import { AuthContext } from './context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<authApi.AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  const isSuperAdmin = user?.roles?.includes('Super Admin') ?? false

  async function refreshUser() {
    try {
      const response = await authApi.currentUser()
      setUser({
        ...response.user,
        roles: response.roles ?? response.user.roles ?? [],
        must_change_password: Boolean(response.must_change_password),
      })
    } catch {
      setUser(null)
    }
  }

  useEffect(() => {
    let mounted = true

    void authApi.currentUser()
      .then((response) => {
        if (mounted) {
          setUser({
            ...response.user,
            roles: response.roles ?? response.user.roles ?? [],
            must_change_password: Boolean(response.must_change_password),
          })
        }
      })
      .catch(() => {
        if (mounted) setUser(null)
      })
      .finally(() => {
        if (mounted) setLoading(false)
      })

    return () => {
      mounted = false
    }
  }, [])

  async function login(email: string, password: string, remember: boolean) {
    const response = await authApi.login(email, password, remember)

    setUser({
      ...response.user,
      roles: response.user.roles ?? [],
      must_change_password: Boolean(response.must_change_password),
    })

    await refreshUser()
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      setUser(null)
    }
  }

  async function updateUser(name: string, email: string) {
    const response = await authApi.updateProfile(name, email)

    setUser({
      ...response.user,
      roles: response.user.roles ?? user?.roles ?? [],
      must_change_password: Boolean(response.user.must_change_password ?? user?.must_change_password),
    })

    await refreshUser()
  }

  return (
    <AuthContext.Provider value={{
      user,
      loading,
      isAuthenticated: Boolean(user),
      isSuperAdmin,
      login,
      logout,
      refreshUser,
      updateUser,
    }}>
      {children}
    </AuthContext.Provider>
  )
}
