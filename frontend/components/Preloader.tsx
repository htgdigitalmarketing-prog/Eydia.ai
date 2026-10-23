/**
 * Branded full-screen preloader — shown while the app shell boots (session
 * fetch) and on route transitions. Uses the Eydia.ai logo so slow loads still
 * feel on-brand instead of a blank/plain "Loading…" screen.
 */
export function Preloader({ label = "Loading…" }: { label?: string }) {
  return (
    <div className="preloader">
      <div className="preloader-glow" />
      {/* Full-colour logo on the light theme; white logo in dark mode — the
          correct one always reads clearly against the background. */}
      <img src="/eydia-logo.png" alt="Eydia.ai Automate · Engage · Grow" className="preloader-logo preloader-logo-light" />
      <img src="/eydia-logo-white.png" alt="" aria-hidden className="preloader-logo preloader-logo-dark" />
      <div className="preloader-spin" />
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
