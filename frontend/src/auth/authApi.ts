export type AuthUser = {
  id: number
  name: string
  email: string
  is_active: boolean
  must_change_password: boolean
  roles: string[]
}

type ApiError = Error & { status?: number }

function xsrfToken() {
  const cookie = document.cookie
    .split('; ')
    .find((value) => value.startsWith('XSRF-TOKEN='))

  return cookie ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)) : ''
}

async function csrfCookie() {
  const response = await fetch('/api/csrf-token', { credentials: 'include' })

  if (!response.ok) {
    throw new Error('Unable to establish a secure session.')
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, {
    ...options,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(xsrfToken() ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
      ...options.headers,
    },
  })

  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as {
      message?: string
      errors?: Record<string, string[]>
    } | null

    // Laravel's default validation-failure message ("The given data was
    // invalid.") is not useful on its own - prefer the specific field
    // error when the backend sent one (e.g. "You cannot disable your own
    // account.", "Your new password must be different from your current
    // password.").
    const firstFieldError = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined

    const error: ApiError = new Error(firstFieldError ?? body?.message ?? 'Request failed.')
    error.status = response.status
    throw error
  }

  return response.json() as Promise<T>
}

export async function currentUser() {
  return request<{ user: AuthUser; roles: string[]; must_change_password: boolean }>('/api/me')
}

export async function login(email: string, password: string, remember: boolean) {
  await csrfCookie()
  return request<{ user: AuthUser; must_change_password: boolean }>('/api/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, remember }),
  })
}

export async function logout() {
  await csrfCookie()
  return request<{ message: string }>('/api/logout', { method: 'POST' })
}

export async function updateProfile(name: string, email: string) {
  return request<{ user: AuthUser }>('/api/profile', {
    method: 'PUT',
    body: JSON.stringify({ name, email }),
  })
}

export async function updatePassword(currentPassword: string, password: string, passwordConfirmation: string) {
  return request<{ message: string; must_change_password: boolean }>('/api/password', {
    method: 'PUT',
    body: JSON.stringify({
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
}

export async function requestPasswordReset(email: string) {
  await csrfCookie()
  return request<{ message: string }>('/api/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  })
}

export async function resetPassword(email: string, token: string, password: string, passwordConfirmation: string) {
  await csrfCookie()
  return request<{ message: string }>('/api/reset-password', {
    method: 'POST',
    body: JSON.stringify({
      email,
      token,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
}

export type AdminUser = {
  id: number
  name: string
  email: string
  is_active: boolean
  roles: string[]
  created_at: string
}

export async function adminUsers() {
  return request<{ users: AdminUser[] }>('/api/admin/users')
}

export async function adminCreateUser(name: string, email: string, role: string) {
  return request<{
    message: string
    user: AdminUser
    temporary_password: string
  }>('/api/admin/users', {
    method: 'POST',
    body: JSON.stringify({ name, email, role }),
  })
}

export async function adminUpdateUser(
  id: number,
  data: {
    name?: string
    email?: string
    is_active?: boolean
    role?: string
  },
) {
  return request<{ message: string }>(`/api/admin/users/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  })
}

export async function adminResetPassword(id: number) {
  return request<{
    message: string
    temporary_password: string
  }>(`/api/admin/users/${id}/reset-password`, {
    method: 'POST',
  })
}
