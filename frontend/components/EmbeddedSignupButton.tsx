"use client";

import { useEffect, useRef, useState } from "react";

// Minimal typings for the Facebook JS SDK we rely on.
declare global {
  interface Window {
    FB?: any;
    fbAsyncInit?: () => void;
  }
}

type SignupResult = { code: string; waba_id: string; phone_number_id: string | null };

/**
 * Real Meta "Connect WhatsApp" (Embedded Signup via Facebook Login for Business).
 * Loads the FB JS SDK, launches the signup dialog with the tenant-agnostic
 * config_id, captures the returned auth `code` plus the selected WABA / phone
 * (delivered via a window `message` event), and hands them to onComplete.
 *
 * Requires the Meta app to allow this origin and be configured for Embedded
 * Signup; otherwise the popup shows Meta's own error.
 */
export function EmbeddedSignupButton({
  appId,
  configId,
  apiVersion,
  onComplete,
  onError,
}: {
  appId: string;
  configId: string;
  apiVersion: string;
  onComplete: (r: SignupResult) => void;
  onError: (message: string) => void;
}) {
  const [ready, setReady] = useState(false);
  const [isHttps, setIsHttps] = useState(true);
  const sessionInfo = useRef<{ waba_id?: string; phone_number_id?: string }>({});

  useEffect(() => {
    // Facebook Login for Business refuses to run on plain http.
    setIsHttps(window.location.protocol === "https:");
  }, []);

  useEffect(() => {
    // Capture the WABA / phone id that Meta posts back during the flow.
    function onMessage(event: MessageEvent) {
      if (typeof event.origin !== "string" || !event.origin.endsWith("facebook.com")) return;
      try {
        const data = typeof event.data === "string" ? JSON.parse(event.data) : event.data;
        if (data?.type === "WA_EMBEDDED_SIGNUP" && data?.data) {
          sessionInfo.current = {
            waba_id: data.data.waba_id,
            phone_number_id: data.data.phone_number_id,
          };
        }
      } catch {
        /* not our message */
      }
    }
    window.addEventListener("message", onMessage);
    return () => window.removeEventListener("message", onMessage);
  }, []);

  useEffect(() => {
    if (window.FB) {
      setReady(true);
      return;
    }
    window.fbAsyncInit = function () {
      window.FB.init({ appId, autoLogAppEvents: true, xfbml: true, version: apiVersion || "v23.0" });
      setReady(true);
    };
    const id = "facebook-jssdk";
    if (!document.getElementById(id)) {
      const js = document.createElement("script");
      js.id = id;
      js.src = "https://connect.facebook.net/en_US/sdk.js";
      js.async = true;
      js.defer = true;
      document.body.appendChild(js);
    }
  }, [appId, apiVersion]);

  function launch() {
    if (!isHttps) {
      onError(
        "Meta Embedded Signup requires HTTPS — it can't run on http://localhost. " +
          "Use manual connect below, or run the frontend over HTTPS (npm run dev -- --experimental-https)."
      );
      return;
    }
    if (!window.FB) {
      onError("Facebook SDK is still loading. Please try again in a moment.");
      return;
    }
    sessionInfo.current = {};
    try {
    window.FB.login(
      (response: any) => {
        const code = response?.authResponse?.code;
        if (!code) {
          // Surface Meta's own status so the cause is clear (often a domain /
          // app-mode config issue rather than a user cancel).
          const status = response?.status ? ` (status: ${response.status})` : "";
          onError(
            "WhatsApp sign-up did not complete" + status + ". " +
            "If a Facebook error popup appeared, this domain may not be allowed in your Meta app " +
            "(add it under Facebook Login → Settings → Allowed Domains for the JavaScript SDK, and App Domains), " +
            "or the app isn't Live. You can also use manual connect below."
          );
          return;
        }
        const waba = sessionInfo.current.waba_id;
        if (!waba) {
          onError("Could not read the WhatsApp Business Account from Meta. Please try again.");
          return;
        }
        onComplete({ code, waba_id: waba, phone_number_id: sessionInfo.current.phone_number_id ?? null });
      },
      {
        config_id: configId,
        response_type: "code",
        override_default_response_type: true,
        extras: { setup: {}, featureType: "", sessionInfoVersion: "3" },
      }
    );
    } catch {
      onError("Could not open the Facebook sign-up dialog.");
    }
  }

  return (
    <div>
      <button
        type="button"
        className="btn"
        style={{ width: "auto", padding: "11px 18px", background: isHttps ? "#1877f2" : "#9aa0a6" }}
        onClick={launch}
        disabled={!ready || !isHttps}
        title={isHttps ? "" : "Requires HTTPS"}
      >
        {!isHttps ? "Continue with Facebook (needs HTTPS)" : ready ? "Continue with Facebook" : "Loading Facebook…"}
      </button>
      {!isHttps && (
        <div className="muted" style={{ fontSize: 12, marginTop: 6 }}>
          One-click Meta sign-up needs HTTPS. On http://localhost, use manual connect below.
        </div>
      )}
    </div>
  );
}
