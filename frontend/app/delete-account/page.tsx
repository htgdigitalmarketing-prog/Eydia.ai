export const metadata = {
  title: "Delete your account — Eydia.ai",
  description: "How to request deletion of your Eydia account and data.",
};

export default function DeleteAccount() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>Delete your account</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>
        App: <strong>Eydia</strong> (Android package <code>com.heltog.review_flow</code>) · Developer: <strong>Pizi India Pvt Ltd</strong>
      </p>

      <p>You can permanently delete your Eydia account and its data at any time. There are two ways:</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Option 1 — From the app</h2>
      <ul>
        <li>Open the Eydia app and sign in.</li>
        <li>Go to <strong>Profile → Delete account</strong>.</li>
        <li>Confirm with your password (or your Google sign-in) to permanently delete your account.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Option 2 — By email</h2>
      <p>
        Send a deletion request from your registered email address to{" "}
        <a href="mailto:support@eydia.in">support@eydia.in</a> with the subject{" "}
        <strong>&quot;Delete my account&quot;</strong>. We verify the request and process it within <strong>7 business days</strong>.
      </p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>What is deleted</h2>
      <ul>
        <li>Your user profile (name, email, phone, avatar) and login credentials.</li>
        <li>Your connected Google Business Profile tokens and synced locations, reviews, posts and photos.</li>
        <li>Your WhatsApp connection tokens, chatbot settings and automation configuration.</li>
        <li>If you are the only member of your workspace, the workspace and its data are deleted too.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>What is kept, and for how long</h2>
      <ul>
        <li><strong>Invoices and payment records</strong> are retained for up to <strong>8 years</strong> as required by Indian tax and accounting law. These are kept in a restricted, non-active form and are not used for any other purpose.</li>
        <li>Backups containing residual data are rotated and fully purged within <strong>30 days</strong>.</li>
      </ul>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Response time</h2>
      <p>In-app deletions are immediate. Email requests are completed within 7 business days; we email you a confirmation once done.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact</h2>
      <p>Questions about account or data deletion: <a href="mailto:support@eydia.in">support@eydia.in</a>.</p>

      <p style={{ marginTop: 40 }}><a href="/">← Back to Eydia.ai</a></p>
    </div>
  );
}
