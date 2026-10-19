"use client";

import { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import Link from "next/link";
import { api, ApiError, User } from "@/lib/api";
import { getToken, clearToken } from "@/lib/auth";
import { navFor } from "@/lib/nav";
import { UserContext } from "@/lib/user-context";
import { Preloader } from "@/components/Preloader";
import { NoPlanGate, ExpiredBanner, ExpiredBlock, isAllowedWhenExpired } from "@/components/PlanGate";
import { AssistantWidget } from "@/components/AssistantWidget";

const ROLE_LABELS: Record<string, string> = {
  "super-admin": "Super Admin",
  "tenant-owner": "Admin",
  "agent": "Agent",
  "manager": "Admin",
  "user": "Agent",
};
function roleLabel(role: string): string {
  return ROLE_LABELS[role] ?? role;
}

function CreditsChip() {
  const [balance, setBalance] = useState<number | null>(null);
  useEffect(() => {
    const t = getToken();
    if (!t) return;
    api.credits.get(t).then((c) => setBalance(c.balance)).catch(() => {});
  }, []);
  if (balance === null) return null;
  const low = balance < 20;
  return (
    <Link href="/billing" title="AI credits — click to top up" style={{
      display: "inline-flex", alignItems: "center", gap: 6, textDecoration: "none",
      padding: "7px 12px", borderRadius: 10, fontSize: 13, fontWeight: 700,
      color: low ? "#c53030" : "var(--accent-dark)", background: low ? "#fdecec" : "#eef0ff",
      border: `1px solid ${low ? "#f6cccc" : "#dde1ff"}`,
    }}>
      <span>⚡</span>{balance.toLocaleString("en-IN")}<span style={{ fontWeight: 500, opacity: .7 }}>credits</span>
    </Link>
  );
}

function TopBar({ user, onMenuToggle }: { user: User; onMenuToggle: () => void }) {
  const [dark, setDark] = useState(false);
  const [fs, setFs] = useState(false);
  const [notifOpen, setNotifOpen] = useState(false);

  useEffect(() => {
    let saved: string | null = null;
    try { saved = localStorage.getItem("theme"); } catch {}
    const isDark = saved === "dark";
    setDark(isDark);
    document.documentElement.setAttribute("data-theme", isDark ? "dark" : "light");
    const onFs = () => setFs(!!document.fullscreenElement);
    document.addEventListener("fullscreenchange", onFs);
    return () => document.removeEventListener("fullscreenchange", onFs);
  }, []);

  function toggleTheme() {
    const next = !dark;
    setDark(next);
    document.documentElement.setAttribute("data-theme", next ? "dark" : "light");
    try { localStorage.setItem("theme", next ? "dark" : "light"); } catch {}
  }

  function toggleFullscreen() {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    else document.documentElement.requestFullscreen().catch(() => {});
  }

  const initials = user.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();

  return (
    <div className="appbar">
      <button className="menu-toggle" onClick={onMenuToggle} aria-label="Toggle menu">☰</button>
      <div className="appbar-title">{user.tenant?.company_name ?? user.tenant?.name ?? "Eydia.ai"}</div>
      <div className="appbar-actions">
        <CreditsChip />
        <AssistantWidget />
        <div style={{ position: "relative" }}>
          <button className="appbar-btn" title="Notifications" onClick={() => setNotifOpen((v) => !v)}>🔔</button>
          {notifOpen && (
            <div className="appbar-pop" onMouseLeave={() => setNotifOpen(false)}>
              <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 6 }}>Notifications</div>
              <div className="muted" style={{ fontSize: 13 }}>You&apos;re all caught up — no new notifications.</div>
            </div>
          )}
        </div>
        <button className="appbar-btn" title={dark ? "Light mode" : "Dark mode"} onClick={toggleTheme}>{dark ? "☀️" : "🌙"}</button>
        <button className="appbar-btn" title={fs ? "Exit full screen" : "Full screen"} onClick={toggleFullscreen}>{fs ? "🡼" : "⛶"}</button>
        <Link href="/profile" className="appbar-user" title="View &amp; edit your profile" style={{ textDecoration: "none", color: "inherit" }}>
          <div className="appbar-avatar" style={{ overflow: "hidden", padding: 0 }}>
            {user.avatar_url
              ? <img src={user.avatar_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
              : initials}
          </div>
          <div style={{ lineHeight: 1.1 }}>
            <div style={{ fontSize: 13, fontWeight: 600 }}>{user.name}</div>
            <div className="muted" style={{ fontSize: 11 }}>{user.roles?.[0] ? roleLabel(user.roles[0]) : ""}</div>
          </div>
        </Link>
      </div>
    </div>
  );
}

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => {
    const token = getToken();
    if (!token) {
      router.replace("/login");
      return;
    }
    api
      .me(token)
      .then((data) => setUser(data.user))
      .catch((err) => {
        const e = err as ApiError;
        if (e.status === 401) {
          clearToken();
          router.replace("/login");
        } else {
          setError(e.message);
        }
      });
  }, [router]);

  function refreshUser() {
    const token = getToken();
    if (!token) return;
    api.me(token).then((data) => setUser(data.user)).catch(() => {});
  }

  function logout() {
    clearToken();
    router.replace("/login");
  }

  if (error) return <div className="center-screen">Error: {error}</div>;
  if (!user) return <Preloader label="Loading your workspace…" />;

  const hasActivePlan = user.is_super_admin ||
    user.subscription?.status === "active" ||
    user.subscription?.status === "trialing";

  const isExpired = !hasActivePlan && user.subscription?.status === "expired";

  if (!hasActivePlan && !isExpired) {
    return <NoPlanGate user={user} onActivated={refreshUser} />;
  }

  return (
    <UserContext.Provider value={user}>
      <div className="shell">
        {sidebarOpen && <div className="sidebar-overlay" onClick={() => setSidebarOpen(false)} />}
        <aside className={`sidebar ${sidebarOpen ? "sidebar-open" : ""}`}>
          <div className="logo" style={{ margin: "-20px -14px 14px", padding: "20px 14px 16px", display: "flex", justifyContent: "center", borderBottom: "1px solid rgba(255,255,255,.07)" }}>
            <img src="/eydia-logo-white.png" alt="Eydia.ai Automate · Engage · Grow" style={{ width: "100%", maxWidth: 150, height: "auto", display: "block", filter: "drop-shadow(0 6px 16px rgba(91,107,245,.35))" }} />
          </div>
          <nav>
            {navFor(user).map((item) => {
              const active = pathname === item.href || pathname.startsWith(item.href + "/");
              return (
                <Link key={item.href} href={item.href} className={active ? "active" : ""} onClick={() => setSidebarOpen(false)}>
                  <span className="nav-ic">{item.icon}</span>
                  {item.label}
                </Link>
              );
            })}
          </nav>
          <div style={{ marginTop: 24, paddingTop: 16, borderTop: "1px solid rgba(255,255,255,.12)" }}>
            <div className="side-user">
              <div className="side-avatar">
                {(user.name || "?").split(" ").map((s) => s[0]).slice(0, 2).join("").toUpperCase()}
              </div>
              <div className="side-user-meta">
                <div className="side-user-name">
                  <span style={{ overflow: "hidden", textOverflow: "ellipsis" }}>{user.name}</span>
                  {user.roles?.[0] && <span className="side-role">{roleLabel(user.roles[0])}</span>}
                </div>
                <div className="side-user-email">{user.email}</div>
              </div>
            </div>
            <button className="side-logout" onClick={logout}>Log out</button>
            <div style={{ marginTop: 14, fontSize: 11, color: "#6f80a4", lineHeight: 1.5 }}>
              © {new Date().getFullYear()} Eydia.ai · Automate · Engage · Grow
            </div>
          </div>
        </aside>
        <main className="main">
          <TopBar user={user} onMenuToggle={() => setSidebarOpen((v) => !v)} />
          {isExpired && <ExpiredBanner planName={user.subscription?.plan_name ?? null} />}
          <div className="main-content">
            {isExpired && !isAllowedWhenExpired(pathname) ? <ExpiredBlock /> : children}
          </div>
        </main>
      </div>
    </UserContext.Provider>
  );
}
