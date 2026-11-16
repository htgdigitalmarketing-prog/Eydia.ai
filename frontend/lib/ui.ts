// In-app toast + confirm, replacing native alert()/confirm(). Backed by a single
// <DialogHost/> mounted in the app shell, driven by window CustomEvents.

export type ToastType = "info" | "success" | "error";

export function toast(message: string, type: ToastType = "info") {
  if (typeof window === "undefined" || !message) return;
  window.dispatchEvent(new CustomEvent("eydia:toast", { detail: { message, type } }));
}

/** Toast an API error, but stay silent for plan/feature gates (the UpgradeModal handles those). */
export function toastError(e: { message?: string; code?: string } | unknown) {
  const err = (e ?? {}) as { message?: string; code?: string };
  if (err.code === "plan_required" || err.code === "feature_not_included") return;
  toast(err.message || "Something went wrong. Please try again.", "error");
}

export type ConfirmOptions = {
  message: string;
  title?: string;
  confirmText?: string;
  cancelText?: string;
  danger?: boolean;
};

/** Promise-based confirm — await it in place of window.confirm(). */
export function confirmDialog(opts: ConfirmOptions | string): Promise<boolean> {
  if (typeof window === "undefined") return Promise.resolve(false);
  const detail = typeof opts === "string" ? { message: opts } : opts;
  return new Promise<boolean>((resolve) => {
    window.dispatchEvent(new CustomEvent("eydia:confirm", { detail: { ...detail, resolve } }));
  });
}
