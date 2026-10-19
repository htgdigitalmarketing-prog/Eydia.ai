import Link from "next/link";

export const metadata = { title: "About · Eydia.ai" };

const DOES = [
  { t: "Get found on Google", d: "The GBP AI Agent rewrites your profile with top keywords, replies to every review, posts daily and schedules photos — so more customers discover you." },
  { t: "Turn enquiries into bookings", d: "The WhatsApp Chat Agent answers questions, nurtures leads, confirms slots and follows up when chats go quiet — multilingual, 24/7." },
  { t: "Bring customers back", d: "The WhatsApp Marketing Agent runs campaigns, offers, reminders and broadcasts with full delivery, read and reply tracking." },
  { t: "Manage every lead in one CRM", d: "Leads from Google, Meta and manual sources land in one pipeline, sorted by intent, with automatic follow-ups." },
  { t: "Work as a team", d: "A shared inbox with role-based access so owners, managers and agents see exactly what they need." },
  { t: "Track performance", d: "Dashboards for messages, campaigns, reviews, agents and delivery rates — the whole picture in one place." },
];

const WHY = [
  { t: "Official Meta & Google partner", d: "Built on the WhatsApp Cloud API and Google Business Profile APIs, fully compliant with platform policies." },
  { t: "Multi-tenant SaaS", d: "Each business gets its own secure, isolated workspace." },
  { t: "No code required", d: "Agents, automations and campaigns set up without any technical knowledge." },
  { t: "Secure by design", d: "Credentials encrypted at rest, HTTPS everywhere, role-based access and audit logging." },
  { t: "Flexible pricing", d: "Plans for businesses of every size, with pay-as-you-go messaging costs." },
];

export default function AboutUs() {
  return (
    <div className="lp">
      {/* Top bar */}
      <header className="lp-topbar">
        <div className="lp-topbar-in">
          <Link href="/" className="brand-lockup" style={{ textDecoration: "none" }}>
            <span className="brand-mark">e</span>
            <span className="brand-word" style={{ fontSize: 20 }}>Eydia.ai</span>
          </Link>
          <nav className="lp-topnav">
            <Link href="/#how">How it works</Link>
            <Link href="/#pricing">Pricing</Link>
            <Link href="/contact">Contact</Link>
            <Link href="/login" className="lp-btn lp-btn-primary">Login →</Link>
          </nav>
        </div>
      </header>

      {/* Hero band */}
      <section className="lp-final" style={{ padding: "56px 24px" }}>
        <span className="lp-eyebrow" style={{ color: "rgba(255,255,255,.85)" }}>About us</span>
        <h2>AI marketing agents for local businesses.</h2>
        <p>Eydia.ai unifies WhatsApp, Google reviews and leads into one intelligent workspace.</p>
      </section>

      {/* Content */}
      <section className="lp-section">
        <div className="lp-wrap" style={{ maxWidth: 860 }}>
          <h2 className="lp-h2" style={{ textAlign: "left" }}>Who we are</h2>
          <p style={{ color: "var(--muted)", fontSize: 16, lineHeight: 1.7, marginTop: 8 }}>
            Eydia.ai is a technology company that builds AI-powered business communication and marketing
            tools. Our platform brings <strong>WhatsApp marketing &amp; inbox</strong>, <strong>Google
            Business Profile &amp; reviews</strong>, and a full <strong>Lead Management CRM</strong> together —
            so a local business runs every customer conversation, every review and every lead from a single
            login, powered by AI agents that handle the daily work.
          </p>

          <h2 className="lp-h2" style={{ textAlign: "left", marginTop: 44 }}>What we do</h2>
          <div className="lp-story-grid" style={{ marginTop: 20 }}>
            {DOES.map((x) => (
              <div key={x.t} className="lp-story">
                <b style={{ fontSize: 16 }}>{x.t}</b>
                <p style={{ margin: "8px 0 0" }}>{x.d}</p>
              </div>
            ))}
          </div>

          <div className="lp-quote" style={{ maxWidth: "none", marginTop: 44 }}>
            <p style={{ fontStyle: "normal" }}><strong>Our mission:</strong> to make professional marketing on
            WhatsApp and Google accessible, affordable and easy for every business — from a local shop to a
            growing enterprise — using official Meta and Google APIs with full compliance and security.</p>
          </div>

          <h2 className="lp-h2" style={{ textAlign: "left", marginTop: 44 }}>Why choose Eydia.ai</h2>
          <div className="lp-story-grid" style={{ marginTop: 20 }}>
            {WHY.map((x) => (
              <div key={x.t} className="lp-story">
                <b style={{ fontSize: 16 }}>{x.t}</b>
                <p style={{ margin: "8px 0 0" }}>{x.d}</p>
              </div>
            ))}
          </div>

          <div style={{ marginTop: 44, textAlign: "center" }}>
            <Link href="/contact" className="lp-btn lp-btn-primary lp-btn-lg">Get in touch →</Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="lp-foot">
        <div className="lp-foot-bottom" style={{ borderTop: "none", marginTop: 0, justifyContent: "center", gap: 18 }}>
          <span>© {new Date().getFullYear()} Eydia.ai — Automate · Engage · Grow</span>
          <span><Link href="/privacy">Privacy</Link> · <Link href="/terms">Terms</Link> · <Link href="/pricing-policy">Pricing</Link> · <Link href="/cancellation-policy">Refund</Link></span>
        </div>
      </footer>
    </div>
  );
}
