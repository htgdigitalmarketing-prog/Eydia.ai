export default function CancellationPolicy() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>Cancellation and Refund Policy</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>Last updated: September 12, 2026</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Cancellation</h2>
      <ul>
        <li>You may cancel your subscription at any time from the Billing section of your dashboard.</li>
        <li>Upon cancellation, your current plan remains active until the end of the current billing period.</li>
        <li>After the billing period ends, your account will be downgraded to the free/starter plan with limited features.</li>
        <li>Your data (contacts, conversations, campaigns) will be retained for 30 days after cancellation. After 30 days, data may be permanently deleted.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Refund policy</h2>
      <ul>
        <li><strong>Within 7 days of purchase:</strong> If you are not satisfied with the Service, you may request a full refund within 7 days of your first payment. This applies to first-time purchases only.</li>
        <li><strong>After 7 days:</strong> No refunds will be issued for the current billing period. You may cancel to prevent future charges.</li>
        <li><strong>Renewal payments:</strong> Refund requests for renewal payments must be made within 48 hours of the charge. After 48 hours, no refund will be issued.</li>
        <li><strong>Account suspension:</strong> If your account is suspended due to violation of our Terms and Conditions or WhatsApp&apos;s policies, no refund will be issued.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>How to request a refund</h2>
      <p>Send an email to <strong>heltogtechnologies@gmail.com</strong> with:</p>
      <ul>
        <li>Your registered email address</li>
        <li>Company name</li>
        <li>Reason for refund</li>
        <li>Payment reference / transaction ID</li>
      </ul>
      <p>Refunds will be processed within 5–7 business days to the original payment method.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Plan changes</h2>
      <p>You may upgrade or downgrade your plan at any time. Upgrades take effect immediately (with prorated charges). Downgrades take effect at the start of the next billing cycle.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact</h2>
      <p>For cancellation or refund requests: <strong>heltogtechnologies@gmail.com</strong></p>
    </div>
  );
}
