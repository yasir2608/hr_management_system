"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/lib/auth";
import { initials } from "./ui";

export const platformNav = [
  { href: "/platform/overview", label: "Overview", icon: "◉" },
  { href: "/platform/companies", label: "Customer companies", icon: "▣" },
  { href: "/platform/support", label: "Support access", icon: "⚑" },
];

/** Layout for M.Y HRMS operations staff. Not tied to any one company. */
export function PlatformShell({ children }: { children: React.ReactNode }) {
  const { status, user, signOut } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (status === "guest") router.replace("/login");
  }, [status, router]);

  if (status !== "authenticated" || !user) return <div className="loadingpage">Loading…</div>;

  if (!user.platform_role) {
    return (
      <div className="loadingpage">
        <div className="empty">
          <div className="emptyicon">⌑</div>
          <h3>This area is only for M.Y HRMS operations staff</h3>
          <Link className="btn" href="/dashboard">Back to your workspace</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="shell">
      <aside className="side">
        <div className="brand">
          <div className="logo">MY</div>
          <div>
            <b>M.Y HRMS</b>
            <small>Staff console</small>
          </div>
        </div>
        <nav className="navgroup" aria-label="Operations">
          <div className="navlabel">Operations</div>
          {platformNav.map((item) => (
            <Link key={item.href} href={item.href} className={`navitem${pathname.startsWith(item.href) ? " active" : ""}`}>
              <span className="ico">{item.icon}</span>
              {item.label}
            </Link>
          ))}
        </nav>
        <div className="sidefoot">Signed in as {user.email}</div>
      </aside>
      <main className="main">
        <header className="top">
          <div className="crumb">Staff console</div>
          <div className="topright">
            <span className="env">{user.platform_role === "ops" ? "Operations" : "Support"}</span>
            <span className="avatar">{initials(user.name)}</span>
            <button className="btn" onClick={() => void signOut()}>Sign out</button>
          </div>
        </header>
        <div className="content">{children}</div>
      </main>
    </div>
  );
}
