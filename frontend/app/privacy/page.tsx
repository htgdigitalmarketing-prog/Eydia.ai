export default function PrivacyPolicy() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>Privacy Policy</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>Last updated: November 5, 2026</p>

      <p>This policy applies to the <strong>Eydia</strong> mobile app (Android package <code>com.heltog.review_flow</code>) and the Eydia web platform at eydia.in, published by <strong>Pizi India Pvt Ltd</strong> (&quot;we&quot;, &quot;us&quot;, or &quot;our&quot;). It explains what personal data we collect, how we use and share it, and your choices, when you use the app or the website (together, the &quot;Service&quot;).</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Information we collect</h2>
      <ul>
        <li><strong>Account information</strong> — your name, email address, phone number, postal address, company name and user/account identifiers when you register or sign in (including sign-in with Google).</li>
        <li><strong>Business information</strong> — the business details you add or manage, and your Google Business Profile data: locations, reviews, posts, photos, insights and your profile content.</li>
        <li><strong>Content you provide</strong> — photos and images you upload, and text or captions you create or generate in the app.</li>
        <li><strong>WhatsApp Business data</strong> — WhatsApp Business Account ID, phone number ID and access tokens (encrypted at rest) if you connect a WhatsApp number, and messages sent and received through the platform.</li>
        <li><strong>Payment &amp; billing</strong> — invoices and purchase history, and billing details (name, email, phone, address, GSTIN). Card/UPI details are handled by our payment processor; we do not store them.</li>
        <li><strong>Usage &amp; device data</strong> — features used, app/device information and diagnostics to operate and improve the Service, and a push-notification token if you enable notifications.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>How we use your information</h2>
      <ul>
        <li>To provide and maintain the Service and the features you use.</li>
        <li>To manage your Google Business Profile (reviews, posts, photos, insights) and, if connected, to send WhatsApp messages on your behalf via the Meta WhatsApp Cloud API.</li>
        <li>To generate text and images with AI at your request.</li>
        <li>To process payments, manage your subscription and issue invoices.</li>
        <li>To send you account and service communications, including push notifications.</li>
        <li>To comply with legal, tax and accounting obligations.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>How we share information</h2>
      <p>We do not sell your personal data. We share it only with service providers who help us run the Service, and only as needed:</p>
      <ul>
        <li><strong>Google</strong> — Business Profile APIs, to read and update your Google Business Profile on your behalf.</li>
        <li><strong>Meta (Facebook / WhatsApp)</strong> — the WhatsApp Cloud API, if you connect WhatsApp.</li>
        <li><strong>Razorpay</strong> — to process payments and subscriptions.</li>
        <li><strong>AI provider</strong> — the AI model configured for your workspace (e.g. Google Gemini, OpenAI or Anthropic), to generate the text and images you request.</li>
        <li><strong>Cloud hosting</strong> — to store and serve your data securely.</li>
      </ul>
      <p>These providers process data under their own privacy policies and our instructions.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Age limit</h2>
      <p>The Service is intended for business use by adults. You must be at least <strong>18 years old</strong> to use it. We do not knowingly collect data from anyone under 18.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Data security</h2>
      <p>We use encryption for sensitive data (access tokens, passwords), HTTPS for all communications, and access controls to protect your data. However, no method of transmission over the Internet is 100% secure.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Data retention</h2>
      <p>We keep your data while your account is active. When you delete your account, your personal data is deleted on request, except <strong>invoices and payment records</strong>, which we retain for up to <strong>8 years</strong> as required by Indian tax and accounting law. Residual data in backups is purged within 30 days.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Deleting your account</h2>
      <p>You can delete your account and data at any time: in the app go to <strong>Profile → Delete account</strong>, or follow the steps on our <a href="/delete-account" style={{ color: "#2563eb" }}>account deletion page</a>. See that page for exactly what is deleted and what is retained.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Your rights</h2>
      <p>You have the right to access, update, or delete your personal information. Contact us to exercise these rights.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact us</h2>
      <p>If you have questions about this Privacy Policy or your data, contact:</p>
      <p><strong>Pizi India Pvt Ltd</strong> (Eydia)<br />Email: <a href="mailto:support@eydia.in" style={{ color: "#2563eb" }}>support@eydia.in</a></p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Other policies</h2>
      <ul>
        <li><a href="/delete-account" style={{ color: "#2563eb" }}>Account &amp; Data Deletion</a></li>
        <li><a href="/terms" style={{ color: "#2563eb" }}>Terms and Conditions</a></li>
        <li><a href="/pricing-policy" style={{ color: "#2563eb" }}>Pricing Policy</a></li>
        <li><a href="/shipping-policy" style={{ color: "#2563eb" }}>Shipping Policy</a></li>
        <li><a href="/cancellation-policy" style={{ color: "#2563eb" }}>Cancellation and Refund Policy</a></li>
      </ul>
    </div>
  );
}
