"use client";

import { useEffect } from "react";

/**
 * Global error boundary — catches exceptions thrown in the root layout itself
 * (which the per-segment error.tsx cannot). Must render its own <html>/<body>.
 */
export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error("Global error boundary caught:", error);
  }, [error]);

  return (
    <html lang="en">
      <body style={{ fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, sans-serif", margin: 0, background: "#f6f7f8" }}>
        <div style={{ maxWidth: 520, margin: "60px auto", padding: 24, textAlign: "center" }}>
          <div style={{ fontSize: 44 }}>⚠️</div>
          <h2 style={{ margin: "12px 0 6px" }}>Something went wrong</h2>
          <p style={{ color: "#667781", marginBottom: 16 }}>
            The app hit an unexpected error while loading.
          </p>
          {process.env.NODE_ENV === "development" && (
            <pre style={{
              textAlign: "left", background: "#fdecec", color: "#8a1f1f", padding: "10px 12px",
              borderRadius: 8, fontSize: 12, whiteSpace: "pre-wrap", wordBreak: "break-word", marginBottom: 16,
            }}>
              {error?.message || "Unknown error"}{error?.digest ? `\n\nDigest: ${error.digest}` : ""}
            </pre>
          )}
          <div style={{ display: "flex", gap: 8, justifyContent: "center" }}>
            <button onClick={() => reset()} style={{ padding: "9px 16px", borderRadius: 8, border: "none", background: "#25806f", color: "#fff", cursor: "pointer" }}>Try again</button>
            <button onClick={() => location.reload()} style={{ padding: "9px 16px", borderRadius: 8, border: "1px solid #ccc", background: "#fff", cursor: "pointer" }}>Reload page</button>
          </div>
        </div>
      </body>
    </html>
  );
}
