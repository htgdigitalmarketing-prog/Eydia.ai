export default function AboutUs() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>About Us</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>Eydia.ai</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Who we are</h2>
      <p>Eydia.ai is a technology company that builds business communication and automation tools. Our flagship product, <strong>Pizi</strong>, is a WhatsApp automation platform built on the official Meta WhatsApp Cloud API, designed to help businesses of all sizes connect with their customers at scale.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>What we do</h2>
      <p>Pizi brings WhatsApp, Facebook, and Instagram into a single powerful dashboard. We help businesses:</p>
      <ul>
        <li><strong>Manage conversations</strong> — a shared team inbox where multiple agents can handle customer chats, assign conversations, and collaborate in real time.</li>
        <li><strong>Automate messaging</strong> — build keyword-based chatbots and visual automation workflows that reply to customers 24/7 without writing a single line of code.</li>
        <li><strong>Run campaigns</strong> — send approved WhatsApp templates to thousands of contacts with delivery, read, and reply tracking.</li>
        <li><strong>Manage templates</strong> — design WhatsApp message templates with a live phone preview and submit them to Meta for approval in one click.</li>
        <li><strong>Track performance</strong> — analytics dashboards for messages, campaigns, agent performance, and delivery rates.</li>
        <li><strong>Manage teams</strong> — role-based access control so owners, managers, and agents see only what they need.</li>
        <li><strong>Social media</strong> — compose, schedule, and publish posts and reels to Facebook and Instagram from the same workspace.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Our mission</h2>
      <p>To make professional business communication on WhatsApp accessible, affordable, and easy for every business — from a local shop to a growing enterprise — using the official Meta APIs with full compliance and security.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Why choose Pizi</h2>
      <ul>
        <li><strong>Official Meta partner</strong> — built on the WhatsApp Cloud API, fully compliant with Meta&apos;s policies.</li>
        <li><strong>Multi-tenant SaaS</strong> — each business gets its own secure, isolated workspace.</li>
        <li><strong>No code required</strong> — chatbots, automations, and campaigns can be set up without any technical knowledge.</li>
        <li><strong>Secure by design</strong> — all credentials encrypted at rest, HTTPS everywhere, role-based access, and audit logging.</li>
        <li><strong>Flexible pricing</strong> — plans for businesses of every size, with pay-as-you-go Meta messaging costs.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact</h2>
      <p>Have questions? Reach out to us at <strong>heltogtechnologies@gmail.com</strong> or visit our <a href="/contact" style={{ color: "#2563eb" }}>Contact page</a>.</p>

      <div style={{ marginTop: 40, paddingTop: 24, borderTop: "1px solid #e5e7eb", fontSize: 14, color: "#666" }}>
        <p><a href="/privacy" style={{ color: "#2563eb", marginRight: 16 }}>Privacy Policy</a> <a href="/terms" style={{ color: "#2563eb", marginRight: 16 }}>Terms</a> <a href="/pricing-policy" style={{ color: "#2563eb", marginRight: 16 }}>Pricing</a> <a href="/cancellation-policy" style={{ color: "#2563eb" }}>Refund Policy</a></p>
      </div>
    </div>
  );
}
