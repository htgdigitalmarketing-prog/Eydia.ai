"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useUser } from "@/lib/user-context";
import { hasFeature } from "@/components/PlanGate";
import { api } from "@/lib/api";
import { getToken } from "@/lib/auth";

const ROLE_LABELS: Record<string, string> = {
  "super-admin": "Super Admin", "tenant-owner": "Admin", "agent": "Agent", "manager": "Admin", "user": "Agent",
};

type DashCard = {
  key: string; title: string; tag: string; icon: string; href: string; gradient: string; desc: string; jelly: string;
};

const CARDS: DashCard[] = [
  {
    key: "dash_whatsapp", title: "WhatsApp Marketing", tag: "Messaging", icon: "💬",
    href: "/inbox", gradient: "linear-gradient(135deg,#22c7f5,#3b82f6)", jelly: "/jellyfish/blue.png",
    desc: "Team inbox, bulk campaigns, templates, AI chatbot and social publishing.",
  },
  {
    key: "dash_gbp", title: "Google Reviews / GBP", tag: "Reputation", icon: "⭐",
    href: "/gbp", gradient: "linear-gradient(135deg,#f6a609,#f26f21)", jelly: "/jellyfish/pink.png",
    desc: "Monitor locations, read & reply to Google reviews, rating analytics.",
  },
  {
    key: "dash_leads", title: "Lead Management", tag: "Sales CRM", icon: "🎯",
    href: "/leads", gradient: "linear-gradient(135deg,#8b5cf6,#a855f7)", jelly: "/jellyfish/purple.png",
    desc: "Capture leads from Google / Meta / manual, work them through a Kanban pipeline.",
  },
];

const ADMIN_LINKS = [
  { href: "/companies", icon: "🏢", title: "Companies", desc: "Create, suspend & assign plans to tenants.", gradient: "linear-gradient(135deg,#22c7f5,#3b82f6)" },
  { href: "/plans", icon: "📦", title: "Plans", desc: "Build plans & control dashboard access.", gradient: "linear-gradient(135deg,#8b5cf6,#a855f7)" },
  { href: "/settings", icon: "⚙️", title: "Settings", desc: "Platform configuration & integrations.", gradient: "linear-gradient(135deg,#3b82f6,#6366f1)" },
  { href: "/support", icon: "🎧", title: "Support", desc: "Tenant support tickets & requests.", gradient: "linear-gradient(135deg,#f6a609,#f26f21)" },
];

type Kpi = { numbers?: number; reviews?: number; rating?: number; leads?: number; won?: number };
type AdminStats = { companies: number; active: number; plans: number; users: number };

function statTile(grad: string, icon: string, val: string, lab: string) {
  return (
    <div className="hub-stat" style={{ ["--st-grad" as string]: grad } as React.CSSProperties}>
      <span className="st-glow" />
      <div className="st-ic">{icon}</div>
      <div className="st-val">{val}</div>
      <div className="st-lab">{lab}</div>
    </div>
  );
}

export default function DashboardHub() {
  const user = useUser();
  const roleName = user.roles?.[0] ? (ROLE_LABELS[user.roles[0]] ?? user.roles[0]) : null;
  const isAdmin = !!user.is_super_admin;

  const [kpi, setKpi] = useState<Kpi>({});
  const [admin, setAdmin] = useState<AdminStats | null>(null);

  useEffect(() => {
    const token = getToken();
    if (!token) return;

    if (isAdmin) {
      api.admin.companies(token).then((d) => {
        const companies = d.companies ?? [];
        setAdmin({
          companies: companies.length,
          active: companies.filter((c) => c.status === "active").length,
          plans: d.plans?.length ?? 0,
          users: companies.reduce((s, c) => s + (c.users_count ?? 0), 0),
        });
      }).catch(() => {});
      return;
    }

    if (hasFeature(user, "dash_whatsapp")) {
      api.whatsapp.numbers(token).then((d) => setKpi((k) => ({ ...k, numbers: d.numbers.length }))).catch(() => {});
    }
    if (hasFeature(user, "dash_gbp")) {
      api.gbp.overview(token).then((d) => setKpi((k) => ({ ...k, reviews: d.stats.total_reviews, rating: d.stats.average_rating }))).catch(() => {});
    }
    if (hasFeature(user, "dash_leads")) {
      api.leads.list(token).then((d) => setKpi((k) => ({ ...k, leads: d.stats.total, won: d.stats.converted }))).catch(() => {});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const workspace = isAdmin
    ? "Platform account"
    : `${user.tenant?.company_name ?? user.tenant?.name ?? "Your workspace"} · ${user.subscription?.plan_name ?? "No plan"}`;

  return (
    <div className="hub">
      <img className="hub-bg-jelly" src="/jellyfish/white.png" alt="" aria-hidden />
      <div className="hub-top">
        <div className="brand-lockup">
          <span className="brand-mark brand-mark-lg">e</span>
          <span className="brand-word" style={{ fontSize: 26 }}>Eydia.ai</span>
        </div>
        <div className="hub-greet" style={{ textAlign: "right" }}>
          <h1>
            Welcome, {user.name}
            {roleName && <span className="hub-chip">{roleName}</span>}
          </h1>
          <p>{workspace} · {isAdmin ? "Manage your platform." : "Choose a dashboard to get started."}</p>
        </div>
      </div>

      {isAdmin ? (
        <>
          <div className="hub-stats">
            {statTile("linear-gradient(135deg,#22c7f5,#3b82f6)", "🏢", admin ? String(admin.companies) : "…", "Total companies")}
            {statTile("linear-gradient(135deg,#34d399,#059669)", "✅", admin ? String(admin.active) : "…", "Active companies")}
            {statTile("linear-gradient(135deg,#8b5cf6,#a855f7)", "📦", admin ? String(admin.plans) : "…", "Plans")}
            {statTile("linear-gradient(135deg,#f6a609,#f26f21)", "👥", admin ? String(admin.users) : "…", "Total users")}
          </div>

          <p className="hub-section-label">Manage platform</p>
          <div className="hub-grid">
            {ADMIN_LINKS.map((l) => (
              <Link key={l.href} href={l.href} className="hub-card hub-link" style={{ ["--card-grad" as string]: l.gradient } as React.CSSProperties}>
                <span className="hub-card-glow" />
                <div className="hub-ic">{l.icon}</div>
                <div className="hub-link-body">
                  <h3>{l.title}</h3>
                  <p>{l.desc}</p>
                </div>
              </Link>
            ))}
          </div>

          <p className="hub-foot">
            You&apos;re signed in as the platform super admin. Customer dashboards (WhatsApp, Reviews, Leads) belong to tenant workspaces and are managed per plan from <Link href="/plans">Plans</Link>.
          </p>
        </>
      ) : (
        <>
          <div className="hub-stats">
            {statTile("linear-gradient(135deg,#22c7f5,#3b82f6)", "💬", kpi.numbers != null ? String(kpi.numbers) : "—", "WhatsApp numbers")}
            {statTile("linear-gradient(135deg,#f6a609,#f26f21)", "⭐", kpi.rating != null ? `${kpi.rating}★` : "—", "Avg rating")}
            {statTile("linear-gradient(135deg,#fbbf24,#f59e0b)", "📝", kpi.reviews != null ? String(kpi.reviews) : "—", "Google reviews")}
            {statTile("linear-gradient(135deg,#8b5cf6,#a855f7)", "🎯", kpi.leads != null ? String(kpi.leads) : "—", kpi.won != null ? `Leads · ${kpi.won} won` : "Leads")}
          </div>

          <p className="hub-section-label">Your dashboards</p>
          <div className="hub-grid">
            {CARDS.map((c) => {
              const allowed = hasFeature(user, c.key);
              const metric = c.key === "dash_whatsapp" ? (kpi.numbers != null ? `${kpi.numbers} number${kpi.numbers === 1 ? "" : "s"} connected` : "Loading…")
                : c.key === "dash_gbp" ? (kpi.reviews != null ? `${kpi.rating ?? 0}★ · ${kpi.reviews} reviews` : "Loading…")
                : (kpi.leads != null ? `${kpi.leads} leads · ${kpi.won ?? 0} won` : "Loading…");
              return (
                <div key={c.key} className={`hub-card ${allowed ? "" : "locked"}`} style={{ ["--card-grad" as string]: c.gradient } as React.CSSProperties}>
                  <span className="hub-card-glow" />
                  <img className="hub-jelly" src={c.jelly} alt="" aria-hidden />
                  {!allowed && <span className="hub-lock-pill">🔒 Locked</span>}
                  <div className="hub-ic">{c.icon}</div>
                  <div className="hub-tag">{c.tag}</div>
                  <h3 className="hub-title">{c.title}</h3>
                  <p className="hub-desc">{c.desc}</p>
                  {allowed && (
                    <div className="hub-metric"><span className="dot" />{metric}</div>
                  )}
                  {allowed ? (
                    <Link href={c.href} className="hub-open">Open dashboard →</Link>
                  ) : (
                    <Link href={c.href} className="hub-locked-btn">View access (Access Denied)</Link>
                  )}
                </div>
              );
            })}
          </div>

          <p className="hub-foot">
            Dashboards marked <strong>Locked</strong> aren&apos;t in your plan — <Link href="/billing">view plans</Link> to unlock them.
          </p>
        </>
      )}
    </div>
  );
}
