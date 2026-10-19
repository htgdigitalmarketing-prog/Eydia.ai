export type NavItem = {
  label: string;
  href: string;
  icon: string;
  /** Permission required to see this item. Omit = everyone (any signed-in user). */
  perm?: string;
  /** Plan feature key required. Omit = core feature, always available. */
  feature?: string;
};

// Company/agent navigation. Filtered by the signed-in user's permissions AND by
// the three dashboard feature flags (dash_whatsapp / dash_gbp / dash_leads), so
// a plan only shows the dashboards it unlocks. The /dashboard hub always shows
// all three cards; locked ones route to an Access Denied screen.
export const NAV: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: "▤" },

  // — WhatsApp dashboard —
  { label: "Inbox", href: "/inbox", icon: "✉", perm: "conversations.view", feature: "dash_whatsapp" },
  { label: "Contacts", href: "/contacts", icon: "☰", perm: "contacts.view", feature: "dash_whatsapp" },
  { label: "Hot List", href: "/hot-list", icon: "🔥", perm: "contacts.view", feature: "dash_whatsapp" },
  { label: "Black List", href: "/blacklist", icon: "🚫", perm: "contacts.view", feature: "dash_whatsapp" },
  { label: "Campaigns", href: "/campaigns", icon: "📣", perm: "campaigns.view", feature: "dash_whatsapp" },
  { label: "Social", href: "/social", icon: "📸", perm: "campaigns.view", feature: "social" },
  { label: "Automations", href: "/automations", icon: "⚙", perm: "workflows.view", feature: "automations" },
  { label: "Chatbot", href: "/chatbot", icon: "🤖", perm: "bots.view", feature: "chatbot" },
  { label: "Agents", href: "/agents", icon: "🎧", perm: "agents.view", feature: "dash_whatsapp" },
  { label: "Templates", href: "/templates", icon: "▧", perm: "templates.view", feature: "dash_whatsapp" },
  { label: "Analytics", href: "/analytics", icon: "▚", perm: "analytics.view", feature: "reports" },
  { label: "WhatsApp", href: "/whatsapp", icon: "✆", perm: "whatsapp.view", feature: "dash_whatsapp" },

  // — Google Reviews / GBP dashboard —
  { label: "Reviews", href: "/gbp", icon: "⭐", feature: "dash_gbp" },

  // — Lead Management dashboard —
  { label: "Leads", href: "/leads", icon: "🎯", feature: "dash_leads" },

  // — ReviewFlow extras (per-feature gated) —
  { label: "SEO", href: "/seo", icon: "🔎", feature: "seo" },
  { label: "Ads Reports", href: "/ads", icon: "📊", feature: "ads" },
  { label: "Invoicing", href: "/invoicing", icon: "🧾", feature: "invoicing" },

  // — Account —
  { label: "Team", href: "/team", icon: "👥", perm: "team.view" },
  { label: "Billing", href: "/billing", icon: "₹", perm: "billing.view" },
  { label: "Support", href: "/support", icon: "🎫" },
  { label: "Profile", href: "/profile", icon: "👤" },
];

// Platform super-admin navigation.
export const SUPER_ADMIN_NAV: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: "▤" },
  { label: "Companies", href: "/companies", icon: "🏢" },
  { label: "Plans", href: "/plans", icon: "💳" },
  { label: "Settings", href: "/settings", icon: "⚙" },
  { label: "Support", href: "/support", icon: "🎫" },
  { label: "Profile", href: "/profile", icon: "👤" },
];

type NavUser = { is_super_admin?: boolean; permissions?: string[]; plan_features?: string[] | null };

/**
 * The nav appropriate for this user. Super admins get the platform nav
 * (Companies, Plans) PLUS every workspace feature — they can do everything an
 * Admin or Agent can. Everyone else gets a permission-filtered feature nav.
 */
export function navFor(user: NavUser): NavItem[] {
  // Super admin is a platform account (no tenant), so it only gets the platform
  // tools — Dashboard, Companies, Plans. Tenant feature pages would be empty for
  // it, so they're intentionally left out.
  if (user.is_super_admin) {
    return SUPER_ADMIN_NAV;
  }
  const perms = new Set(user.permissions ?? []);
  // plan_features null/undefined = no plan → no feature gating (all available).
  const planFeatures = user.plan_features == null ? null : new Set(user.plan_features);
  return NAV.filter((item) => {
    if (item.perm && !perms.has(item.perm)) return false;
    if (item.feature && planFeatures && !planFeatures.has(item.feature)) return false;
    return true;
  });
}
