/**
 * Branded full-screen preloader — shown while the app shell boots (session
 * fetch) and on route transitions. Uses the Eydia.ai logo so slow loads still
 * feel on-brand instead of a blank/plain "Loading…" screen.
 */
export function Preloader({ label = "Loading…" }: { label?: string }) {
  return (
    <div className="preloader">
      <img src="/eydia-logo-white.png" alt="Eydia.ai Automate · Engage · Grow" className="preloader-logo" />
      <div className="spinner spinner-lg" />
      <div className="preloader-text">{label}</div>
    </div>
  );
}

/** Small inline loading block for sections/lists inside a page. */
export function LoadingBlock({ label = "Loading…" }: { label?: string }) {
  return (
    <div className="loading-block">
      <div className="spinner" />
      <span>{label}</span>
    </div>
  );
}

/** Bare spinner for buttons / inline use. */
export function Spinner({ size = "sm" }: { size?: "sm" | "md" | "lg" }) {
  const cls = size === "lg" ? "spinner spinner-lg" : size === "md" ? "spinner" : "spinner spinner-sm";
  return <span className={cls} />;
}
