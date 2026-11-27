// Shared marketing content for the public landing pages (home, /features,
// /our-work, WhatsApp & Google product pages). One source of truth so every
// page — and every ad destination — stays consistent.

// Feature keys → friendly labels for the pricing cards.
export const LP_FEATURE_LABELS: Record<string, string> = {
  dash_whatsapp: "WhatsApp inbox, broadcasts & templates",
  dash_gbp: "Google Reviews / GBP management",
  dash_leads: "Leads CRM & automations",
  chatbot: "AI chatbot auto-replies",
  automations: "Workflow automations",
  social: "Social posting",
  seo: "SEO tools",
  ads: "Ads reporting",
  invoicing: "Invoicing",
  advanced_reports: "Advanced reports",
  export: "Export",
  api_access: "API access",
};

export const STATS = [
  { v: "5,000+", l: "Businesses managed" },
  { v: "2M+", l: "Reviews & messages handled" },
  { v: "10K+", l: "AI replies sent" },
  { v: "99.9%", l: "Uptime" },
];

export const STEPS = [
  {
    n: 1, t: "Connect your channels",
    d: "Link your Google Business Profile and WhatsApp in one click. Eydia imports every location, review and chat automatically.",
  },
  {
    n: 2, t: "Automate with AI",
    d: "AI drafts on-brand review replies, Google posts, WhatsApp messages and lead follow-ups. Sentiment is analysed as each review lands.",
  },
  {
    n: 3, t: "Track & grow",
    d: "Watch reviews, rankings and leads climb on the live dashboard — reply rate, local rank, pipeline and competitor gaps in real time.",
  },
];

export const PILLARS = [
  {
    icon: "⭐", bg: "#eef1ff", fg: "#2160ef", tag: "Google",
    title: "Google Reviews & GBP",
    lead: "Own your local reputation on Google.",
    points: [
      "Reply to every review in seconds with on-brand AI",
      "Publish Google posts & photos across all locations",
      "Live rating, sentiment & profile health score",
      "Local rank tracking on a real geo-grid",
    ],
  },
  {
    icon: "💬", bg: "#e7f7ef", fg: "#0a7d47", tag: "WhatsApp",
    title: "WhatsApp Engagement",
    lead: "Talk to customers where they already are.",
    points: [
      "Official WhatsApp Business API & shared inbox",
      "Broadcasts & automated drip campaigns",
      "24/7 AI chatbot that answers & books",
      "Approved templates with delivery reports",
    ],
  },
  {
    icon: "🎯", bg: "#f3eeff", fg: "#8b3df0", tag: "Leads",
    title: "Leads CRM",
    lead: "Turn conversations into paying customers.",
    points: [
      "Capture leads from Google, WhatsApp & your site",
      "Visual pipeline with AI intent scoring",
      "Automatic follow-ups so nothing slips",
      "Track conversions & revenue by source",
    ],
  },
];

export const FEATURES = [
  {
    icon: "⭐", bg: "#eef1ff", fg: "#4c57d8", title: "Google Reviews & GBP",
    d: "Monitor, reply to and analyse reviews across every location. AI responses in seconds with live sentiment — plus posts, photos and a health score.",
  },
  {
    icon: "💬", bg: "#e7f7ef", fg: "#0a7d47", title: "WhatsApp Engagement",
    d: "Official WhatsApp API: broadcasts, drip campaigns, a 24/7 AI chatbot and approved templates — so every enquiry gets an instant reply.",
  },
  {
    icon: "🎯", bg: "#f3eeff", fg: "#8b5cf6", title: "Leads CRM",
    d: "Capture leads from Google, WhatsApp and your website into one pipeline, scored by intent, with automatic follow-ups so nothing slips.",
  },
  {
    icon: "✨", bg: "#eaf6ff", fg: "#2b6fe8", title: "AI Content Studio",
    d: "Generate Google posts, captions, review replies and images with built-in Gemini AI. Simple, transparent credit-based billing.",
  },
  {
    icon: "📈", bg: "#fff4e6", fg: "#d97706", title: "Local Rank & Insights",
    d: "Track keyword rankings on a real geo-grid, performance trends and competitor velocity so you always stay a step ahead locally.",
  },
  {
    icon: "🧾", bg: "#fdeef4", fg: "#db2777", title: "Invoicing & Billing",
    d: "Professional invoices, GST support, Tally export and expense tracking — the whole back office, built right into one dashboard.",
  },
];

export const MODULES = [
  { icon: "⭐", label: "Reviews & Replies" },
  { icon: "🖼️", label: "Posts & Photos" },
  { icon: "💬", label: "WhatsApp Broadcasts" },
  { icon: "🤖", label: "WhatsApp Chatbot" },
  { icon: "🎯", label: "Leads CRM" },
  { icon: "✨", label: "AI Content Studio" },
  { icon: "🎨", label: "AI Generated Media" },
  { icon: "🩺", label: "Google Audit" },
  { icon: "📊", label: "Local Rank Checker" },
  { icon: "🏁", label: "Competitor Analysis" },
  { icon: "💡", label: "AI Marketing Chat" },
  { icon: "🧾", label: "Invoicing & Tally" },
];

// Static fallback plans, used only until the live plans load.
export const FALLBACK_PLANS = [
  {
    name: "Starter", price: "Free", per: "forever", credits: "100 AI credits / month", popular: false,
    cta: "Get Started",
    feats: ["WhatsApp inbox & templates", "1,000 contacts", "Reviews dashboard", "Basic reports"],
  },
  {
    name: "Growth", price: "₹2,999", per: "/mo", credits: "1,000 AI credits / month", popular: true,
    cta: "Get Started Free",
    feats: ["Google Reviews / GBP (5 locations)", "WhatsApp broadcasts + AI chatbot", "Leads CRM + automations", "Social posting & SEO"],
  },
  {
    name: "Enterprise", price: "₹9,999", per: "/mo", credits: "50,000 AI credits / month", popular: false,
    cta: "Contact Sales",
    feats: ["Unlimited clients & locations", "White-label workspace", "Ads reporting & invoicing", "API access & priority support"],
  },
];
