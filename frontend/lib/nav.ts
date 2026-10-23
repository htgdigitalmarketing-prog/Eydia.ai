export type NavItem = {
  label: string;
  href: string;
  icon: string;
  /** Permission required to see this item. Omit = everyone (any signed-in user). */
  perm?: string;
  /** Plan feature key required. Omit = core feature, always available. */
  feature?: string;
};

// ── New information architecture ──────────────────────────────────────────────
// The sidebar shows only COMMON items. Each of the three products (WhatsApp,
// Google Reviews/GBP, Leads) is a "dashboard group" whose feature items appear
// in the sidebar ONLY while you are inside that dashboard — you open it from the
// /dashboard hub cards. This keeps the sidebar minimal and groups features by
// the dashboard they belong to.

// Always visible, at the top.
export const COMMON_NAV: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: "▤" },
];

// Always visible, at the bottom (account/business tools, cross-dashboard).
export const ACCOUNT_NAV: NavItem[] = [
  { label: "Team", href: "/team", icon: "👥", perm: "team.view" },
  { label: "Invoicing", href: "/invoicing", icon: "🧾", feature: "invoicing" },
  { label: "Buy Credits", href: "/buy-credits", icon: "⚡" },
  { label: "Billing", href: "/billing", icon: "₹", perm: "billing.view" },
  { label: "Support", href: "/support", icon: "🎫" },
  { label: "Profile", href: "/profile", icon: "👤" },
];

export type DashboardGroup = {
  key: string;        // the plan feature flag that unlocks the dashboard
  label: string;
  icon: string;
  home: string;       // the route the "Open dashboard" card leads to
  items: NavItem[];   // the dashboard's own feature nav (each still perm/feature-gated)
};

export const DASHBOARD_GROUPS: DashboardGroup[] = [
  {
    key: "dash_whatsapp", label: "WhatsApp Marketing", icon: "💬", home: "/inbox",
    items: [
      { label: "Inbox", href: "/inbox", icon: "✉", perm: "conversations.view" },
      { label: "Contacts", href: "/contacts", icon: "☰", perm: "contacts.view" },
      { label: "Hot List", href: "/hot-list", icon: "🔥", perm: "contacts.view" },
      { label: "Black List", href: "/blacklist", icon: "🚫", perm: "contacts.view" },
      { label: "Campaigns", href: "/campaigns", icon: "📣", perm: "campaigns.view" },
      { label: "Templates", href: "/templates", icon: "▧", perm: "templates.view" },
      { label: "Chatbot", href: "/chatbot", icon: "🤖", perm: "bots.view", feature: "chatbot" },
      { label: "Automations", href: "/automations", icon: "⚙", perm: "workflows.view", feature: "automations" },
      { label: "Social", href: "/social", icon: "📸", perm: "campaigns.view", feature: "social" },
      { label: "Agents", href: "/agents", icon: "🎧", perm: "agents.view" },
      { label: "Analytics", href: "/analytics", icon: "▚", perm: "analytics.view", feature: "reports" },
      { label: "WhatsApp Setup", href: "/whatsapp", icon: "✆", perm: "whatsapp.view" },
    ],
  },
  {
    key: "dash_gbp", label: "Google Reviews / GBP", icon: "⭐", home: "/gbp",
    items: [
      { label: "Reviews", href: "/gbp", icon: "⭐" },
      { label: "SEO", href: "/seo", icon: "🔎", feature: "seo" },
      { label: "AI Studio", href: "/ai-studio", icon: "✨" },
    ],
  },
  {
    key: "dash_leads", label: "Lead Management", icon: "🎯", home: "/leads",
    items: [
      { label: "Leads", href: "/leads", icon: "🎯" },
      { label: "Ads Reports", href: "/ads", icon: "📊", feature: "ads" },
    ],
  },
];

// Platform super-admin navigation (unchanged — flat list).
export const SUPER_ADMIN_NAV: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: "▤" },
  { label: "Companies", href: "/companies", icon: "🏢" },
  { label: "Plans", href: "/plans", icon: "💳" },
  { label: "Credit Packs", href: "/credit-packs", icon: "⚡" },
  { label: "Payments", href: "/payments", icon: "₹" },
  { label: "Settings", href: "/settings", icon: "⚙" },
  { label: "Support", href: "/support", icon: "🎫" },
  { label: "Profile", href: "/profile", icon: "👤" },
];

export type NavUser = { is_super_admin?: boolean; permissions?: string[]; plan_features?: string[] | null };

function allowed(item: NavItem, user: NavUser): boolean {
  const perms = new Set(user.permissions ?? []);
  // plan_features null/undefined = no plan → no feature gating (all available).
  const planFeatures = user.plan_features == null ? null : new Set(user.plan_features);
  if (item.perm && !perms.has(item.perm)) return false;
  if (item.feature && planFeatures && !planFeatures.has(item.feature)) return false;
  return true;
}

/** Filter a list of nav items by the user's permissions + plan features. */
export function visibleItems(items: NavItem[], user: NavUser): NavItem[] {
  return items.filter((i) => allowed(i, user));
}

/** The dashboard group the current route belongs to, or null (hub/account pages). */
export function groupForPath(pathname: string): DashboardGroup | null {
  return (
    DASHBOARD_GROUPS.find((g) =>
      g.items.some((i) => pathname === i.href || pathname.startsWith(i.href + "/"))
    ) ?? null
  );
}
