export default function PrivacyPolicy() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>Privacy Policy</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>Last updated: September 12, 2026</p>

      <p>Eydia.ai (&quot;we&quot;, &quot;us&quot;, or &quot;our&quot;) operates the Pizi platform (the &quot;Service&quot;). This page informs you of our policies regarding the collection, use, and disclosure of personal data when you use our Service.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Information we collect</h2>
      <ul>
        <li><strong>Account information</strong> — name, email address, phone number, and company name when you register.</li>
        <li><strong>WhatsApp Business data</strong> — WhatsApp Business Account ID, phone number ID, and access tokens (encrypted at rest) when you connect your WhatsApp number.</li>
        <li><strong>Message data</strong> — messages sent and received through the platform are stored to provide inbox, campaign, and analytics features.</li>
        <li><strong>Usage data</strong> — pages visited, features used, and device/browser information for improving the Service.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>How we use your information</h2>
      <ul>
        <li>To provide and maintain the Service.</li>
        <li>To send WhatsApp messages on your behalf via the Meta WhatsApp Cloud API.</li>
        <li>To process payments and manage your subscription.</li>
        <li>To communicate with you about your account or the Service.</li>
        <li>To comply with legal obligations.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Data security</h2>
      <p>We use encryption for sensitive data (WhatsApp access tokens, passwords), HTTPS for all communications, and access controls to protect your data. However, no method of transmission over the Internet is 100% secure.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Third-party services</h2>
      <p>We integrate with Meta (Facebook/WhatsApp) APIs, Razorpay/Stripe for payments, and cloud hosting providers. These services have their own privacy policies.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Data retention</h2>
      <p>We retain your data for as long as your account is active. You may request deletion of your account and associated data by contacting us.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Your rights</h2>
      <p>You have the right to access, update, or delete your personal information. Contact us to exercise these rights.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact us</h2>
      <p>If you have questions about this Privacy Policy, contact us at:</p>
      <p><strong>Eydia.ai</strong><br />Email: heltogtechnologies@gmail.com</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Other policies</h2>
      <ul>
        <li><a href="/terms" style={{ color: "#2563eb" }}>Terms and Conditions</a></li>
        <li><a href="/pricing-policy" style={{ color: "#2563eb" }}>Pricing Policy</a></li>
        <li><a href="/shipping-policy" style={{ color: "#2563eb" }}>Shipping Policy</a></li>
        <li><a href="/cancellation-policy" style={{ color: "#2563eb" }}>Cancellation and Refund Policy</a></li>
      </ul>
    </div>
  );
}
