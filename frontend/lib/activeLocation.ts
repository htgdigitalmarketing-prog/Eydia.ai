// Shared "active Google Business Profile location" for every GBP module
// (Home/Reviews, Business Info, Automation, SEO, AI Studio, Brand Kit). All
// pages read/write the SAME browser key so the active location — and all the
// data scoped to it — stays consistent as the client switches locations.

export type ActiveLoc = { id: string; name: string } | null;

const ID_KEY = "eydia:gbp:active-location"; // id only (kept for back-compat)
const INFO_KEY = "eydia:gbp:active-location-info"; // { id, name }

export function getActiveLocation(): ActiveLoc {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(INFO_KEY);
    if (raw) return JSON.parse(raw) as ActiveLoc;
    const id = localStorage.getItem(ID_KEY);
    return id ? { id, name: "" } : null;
  } catch {
    return null;
  }
}

export function getActiveLocationId(): string | null {
  return getActiveLocation()?.id ?? null;
}

export function setActiveLocation(loc: { id: string; name: string }): void {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(INFO_KEY, JSON.stringify(loc));
    localStorage.setItem(ID_KEY, loc.id);
    // Let other open tabs/pages react if they listen.
    window.dispatchEvent(new CustomEvent("eydia:active-location", { detail: loc }));
  } catch {
    /* storage blocked — ignore */
  }
}
