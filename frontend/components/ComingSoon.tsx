export function ComingSoon({ feature, phase }: { feature: string; phase: string }) {
  return (
    <div className="panel" style={{ textAlign: "center", padding: "48px 22px" }}>
      <div style={{ fontSize: 42, marginBottom: 10 }}>🚧</div>
      <h2 style={{ marginBottom: 6 }}>{feature} is coming soon</h2>
      <p className="muted" style={{ maxWidth: 460, margin: "0 auto" }}>
        This module ships in {phase}. The backend foundation, multi-tenant data model
        and API layer are already in place — the interface is being built next.
      </p>
    </div>
  );
}
