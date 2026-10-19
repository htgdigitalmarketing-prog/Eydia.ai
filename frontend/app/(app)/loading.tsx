import { LoadingBlock } from "@/components/Preloader";

// Suspense fallback for route transitions inside the app shell. The sidebar
// (from layout.tsx) stays put; only the content area shows the spinner.
export default function AppLoading() {
  return (
    <div style={{ minHeight: "60vh", display: "grid", placeItems: "center" }}>
      <LoadingBlock label="Loading…" />
    </div>
  );
}
