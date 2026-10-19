"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { saveToken } from "@/lib/auth";

export default function GoogleCallbackPage() {
  const router = useRouter();
  const [error, setError] = useState(false);

  useEffect(() => {
    const token = new URLSearchParams(window.location.search).get("token");
    if (token) {
      saveToken(token);
      router.replace("/dashboard");
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
