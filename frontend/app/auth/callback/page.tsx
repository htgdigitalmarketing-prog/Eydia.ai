"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { saveToken } from "@/lib/auth";

export default function GoogleCallbackPage() {
  const router = useRouter();
  const [error, setError] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const token = params.get("token");
    if (token) {
      saveToken(token);
      // The mobile "Continue on web" hand-off passes a whitelisted `next`
      // (billing pages). Normal web Google login has no `next` → /dashboard.
      const next = params.get("next");
      const allowed = ["/billing", "/plans", "/buy-credits"];
      router.replace(next && allowed.includes(next) ? next : "/dashboard");
    } else {
      setError(true);
      const t = setTimeout(() => router.replace("/login"), 1800);
      return () => clearTimeout(t);
    }
  }, [router]);

  return (
    <div className="center-screen">
      {error ? "Sign-in failed — redirecting to login…" : "Signing you in…"}
    </div>
  );
}
