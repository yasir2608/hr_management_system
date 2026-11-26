"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/lib/auth";

export default function Home() {
  const { status } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (status === "guest") router.replace("/login");
    if (status === "authenticated") router.replace("/dashboard");
  }, [status, router]);

  return <div className="loadingpage">Loading M.Y HRMS…</div>;
}
