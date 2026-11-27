"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/lib/auth";
import { APP_URL } from "@/lib/portal";
import { initials } from "./ui";

export const platformNav = [
  { href: "/overview", label: "Overview", icon: "◉" },
  { href: "/companies", label: "Customer companies", icon: "▣" },
  { href: "/users", label: "Customer users", icon: "◎" },
  { href: "/support", label: "Support access", icon: "⚑" },
  { href: "/activity", label: "Platform activity", icon: "≡" },
  { href: "/health", label: "System health", icon: "♥" },
];

/** Layout for M.Y HRMS operations staff. Not tied to any one company. */
export function PlatformShell({ children }: { children: React.ReactNode }) {
  const { status, user, signOut } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (status === "guest") router.replace("/login");
  }, [status, router]);

  if (status !== "authenticated" || !user) return <div className="staff"><div className="loadingpage">Loading…</div></div>;

  if (!user.platform_role) {
    return (
      <div className="staff">
        <div className="loadingpage">
          <div className="empty">
            <div className="emptyicon">⌑</div>
            <h3>This area is only for M.Y HRMS operations staff</h3>
            <a className="btn" href={`${APP_URL}/login`}>Go to your company sign-in</a>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="staff">
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
    </div>
  );
}
