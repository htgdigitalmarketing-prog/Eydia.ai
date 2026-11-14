// Minimal browser-side auth state. For a demo we keep the JWT in localStorage;
// a production build would move to httpOnly cookies + refresh handling.

const TOKEN_KEY = "wa_token";

export function saveToken(token: string) {
  if (typeof window !== "undefined") localStorage.setItem(TOKEN_KEY, token);
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function clearToken() {
  if (typeof window !== "undefined") localStorage.removeItem(TOKEN_KEY);
}

// ── Super-admin "view as user" impersonation ────────────────────────────────
// While impersonating we keep the admin's own token under a backup key and run
// the app on the target user's token, so "Exit" restores the admin session.
const ADMIN_TOKEN_KEY = "wa_admin_token";

export function startImpersonation(adminToken: string, userToken: string) {
  if (typeof window === "undefined") return;
  localStorage.setItem(ADMIN_TOKEN_KEY, adminToken);
  localStorage.setItem(TOKEN_KEY, userToken);
}

export function isImpersonating(): boolean {
  if (typeof window === "undefined") return false;
  return !!localStorage.getItem(ADMIN_TOKEN_KEY);
}

/** Restore the admin token and clear impersonation. Returns the admin token, or null. */
export function stopImpersonation(): string | null {
  if (typeof window === "undefined") return null;
  const admin = localStorage.getItem(ADMIN_TOKEN_KEY);
  if (admin) {
    localStorage.setItem(TOKEN_KEY, admin);
    localStorage.removeItem(ADMIN_TOKEN_KEY);
  }
  return admin;
}
