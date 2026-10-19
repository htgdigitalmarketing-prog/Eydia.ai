export default function PricingPolicy() {
  return (
    <div style={{ maxWidth: 800, margin: "0 auto", padding: "40px 20px", fontFamily: "system-ui, sans-serif", lineHeight: 1.7, color: "#333" }}>
      <h1 style={{ fontSize: 28, fontWeight: 600, marginBottom: 8 }}>Pricing Policy</h1>
      <p style={{ color: "#666", marginBottom: 32 }}>Last updated: September 12, 2026</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Subscription plans</h2>
      <p>Pizi offers multiple subscription plans designed for businesses of different sizes. Each plan includes a defined set of features and usage limits (contacts, campaigns, agents, templates, chatbots). Plan details and pricing are displayed on the Billing page within the application after login.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Billing cycle</h2>
      <p>Subscriptions are billed on a monthly basis from the date of purchase. Payments are charged at the beginning of each billing cycle.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Payment methods</h2>
      <p>We accept payments through Razorpay (UPI, net banking, debit/credit cards, wallets) and Stripe (international cards). All transactions are processed securely through these payment gateways.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Taxes</h2>
      <p>All prices are exclusive of applicable taxes. GST (Goods and Services Tax) at 18% will be added to the plan price for customers in India. Tax invoices with GSTIN will be generated for each payment.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Price changes</h2>
      <p>We reserve the right to modify pricing at any time. Existing subscribers will be notified at least 30 days before any price change takes effect. Price changes will apply from the next billing cycle after notification.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>WhatsApp messaging costs</h2>
      <p>Meta charges separately for WhatsApp template messages based on recipient country and template category. These charges are in addition to the platform subscription fee and are managed through Meta&apos;s own billing (payment method added in Meta Business Suite). Pizi does not charge any additional markup on Meta&apos;s messaging fees.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Free trial</h2>
      <p>New accounts may receive a free trial or starter plan with limited features. No payment is required during the trial period. After the trial, you must subscribe to a paid plan to continue using premium features.</p>

      <h2 style={{ fontSize: 20, fontWeight: 600, marginTop: 32 }}>Contact</h2>
      <p>For billing inquiries: <strong>heltogtechnologies@gmail.com</strong></p>
    </div>
  );
}
