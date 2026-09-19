import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { ThemeContext, type ThemeMode } from './context'

const STORAGE_KEY = 'gedi-theme'

function getSystemPrefersDark() {
  if (typeof window === 'undefined') return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function readStoredMode(): ThemeMode {
  if (typeof window === 'undefined') return 'system'
  const stored = window.localStorage.getItem(STORAGE_KEY)
  return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system'
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [mode, setModeState] = useState<ThemeMode>(readStoredMode)
  const [systemPrefersDark, setSystemPrefersDark] = useState(getSystemPrefersDark)

  // Keep resolved theme in sync if the OS-level preference changes while
  // the app is open (only matters while mode === 'system').
  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const handleChange = (event: MediaQueryListEvent) => setSystemPrefersDark(event.matches)
    media.addEventListener('change', handleChange)
    return () => media.removeEventListener('change', handleChange)
  }, [])

  const resolvedTheme: 'light' | 'dark' = mode === 'system' ? (systemPrefersDark ? 'dark' : 'light') : mode

  // The CSS reads data-theme, not resolvedTheme, so that
  // `[data-theme="system"]` combined with `@media (prefers-color-scheme)`
  // can react to OS changes without any JS re-render being required.
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', mode)
  }, [mode])

  const setMode = useCallback((next: ThemeMode) => {
    setModeState(next)
    try {
      window.localStorage.setItem(STORAGE_KEY, next)
    } catch {
      // localStorage can be unavailable (private browsing, storage full) —
      // theme still applies for this session, it just won't persist.
    }
  }, [])

  const value = useMemo(() => ({ mode, resolvedTheme, setMode }), [mode, resolvedTheme, setMode])

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}
