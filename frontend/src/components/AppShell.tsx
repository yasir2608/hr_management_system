"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { inItem, navGroups, type NavItem } from "./nav";
import { NotificationBell } from "./NotificationBell";
import { SearchBox } from "./SearchBox";
import { humanize, initials } from "./ui";

const ROLE_NAMES: Record<string, string> = {
  owner: "Owner", hr_admin: "HR Administrator", payroll_admin: "Payroll Administrator", manager: "Manager", employee: "Employee",
};

export function AppShell({ children }: { children: React.ReactNode }) {
  const { status, user, company, signOut, can, access } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  // Phone layout: the sidebar becomes a slide-in menu. It remembers the page it was opened on,
  // so moving to another page closes it.
  const [navOpenOn, setNavOpenOn] = useState<string | null>(null);
  const navOpen = navOpenOn === pathname;
  const setNavOpen = (open: boolean) => setNavOpenOn(open ? pathname : null);
  const [notice, setNotice] = useState<string | null>(null);

  // While the menu is open: Esc closes it and the page behind it does not scroll.
  useEffect(() => {
    if (!navOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setNavOpenOn(null);
    };
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    document.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKey);
    };
  }, [navOpen]);

  async function signOutOthers() {
    try {
      const result = await api<{ message: string }>("/auth/logout-others", { method: "POST", noCompany: true });
      setNotice(result.message);
    } catch (caught) {
      setNotice(caught instanceof ApiError ? caught.message : "We could not sign out your other devices.");
    }
    window.setTimeout(() => setNotice(null), 4000);
  }

  useEffect(() => {
    if (status === "guest") router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [status, router, pathname]);

  const mustChoose = status === "authenticated" && !company && (user?.companies.some((choice) => choice.can_enter) ?? false);

  useEffect(() => {
    if (mustChoose) router.replace("/select-company");
  }, [mustChoose, router]);

  if (status !== "authenticated" || !user || mustChoose) return <div className="loadingpage">Loading your workspace…</div>;

  if (!company) {
    return (
      <div className="authwrap">
        <div className="panel authcard">
          <div className="authhead">
            <div className="logo" style={{ margin: "auto" }}>✦</div>
            <h2>No company to open</h2>
            <div className="tiny">Your account is not linked to an active company workspace. Contact your company administrator.</div>
          </div>
          <div className="stack">
            {user.platform_role && <Link className="btn primary block" style={{ textAlign: "center" }} href="/overview">Go to the staff console</Link>}
            <button className="btn block" onClick={() => void signOut()}>Sign out</button>
          </div>
        </div>
      </div>
    );
  }

  const allowed = (item: NavItem) => !item.permissions || can(...item.permissions);
  const groups = navGroups
    .map((group) => ({ ...group, items: group.items.filter(allowed).map((item) => ({ ...item, children: (item.children ?? []).filter(allowed) })) }))
    .filter((group) => group.items.length > 0);
  const items = groups.flatMap((group) => group.items.flatMap((item) => [item, ...(item.children ?? [])]));
  const matchesExactly = (href: string) => pathname === href || pathname.startsWith(`${href}/`);
  const currentChild = items.filter((item) => matchesExactly(item.href)).sort((a, b) => b.href.length - a.href.length)[0];
  const roleName = (access?.roles ?? []).map((key) => ROLE_NAMES[key] ?? humanize(key))[0] ?? "Member";

  return (
    <div className={`shell${navOpen ? " navopen" : ""}`}>
      <aside className="side" id="app-nav" aria-label="Main menu">
        <div className="sidehead">
          <Link href="/dashboard" className="brand" style={{ textDecoration: "none" }}>
            <span className="logo">✦</span>
            <span>M.Y HRMS</span>
          </Link>
          <button type="button" className="navclose" onClick={() => setNavOpen(false)} aria-label="Close menu">×</button>
        </div>
        {groups.map((group, index) => (
          <nav className={`navgroup${index === groups.length - 1 && groups.length > 1 ? " navbottom" : ""}`} key={group.label} aria-label={group.label}>
            <div className="navlabel">{group.label}</div>
            {group.items.map((item) => {
              const open = inItem(item, pathname);
              const childActive = (item.children ?? []).some((child) => currentChild?.href === child.href);
              return (
                <div key={item.href} className="navnode">
                  <Link href={item.href} className={`navitem${open && !childActive ? " active" : ""}${open ? " open" : ""}`}>
                    <span className="ico">{item.icon}</span>
                    <span className="label">{item.label}</span>
                  </Link>
                  {open && (item.children ?? []).map((child) => (
                    <Link key={child.href} href={child.href} className={`navitem sub${currentChild?.href === child.href ? " active" : ""}`}>
                      <span className="label">{child.label}</span>
                    </Link>
                  ))}
                </div>
              );
            })}
          </nav>
        ))}
        <div className="sidefoot">
          <span className="avatar">{initials(user.name)}</span>
          <div className="usercopy">
            <b>{user.name}</b>
            <small>{roleName}</small>
          </div>
        </div>
      </aside>
      {navOpen && <div className="navbackdrop" onClick={() => setNavOpen(false)} aria-hidden="true" />}
      <main className="main">
        <header className="top">
          <button
            type="button"
            className="navtoggle"
            onClick={() => setNavOpen(true)}
            aria-label="Open menu"
            aria-controls="app-nav"
            aria-expanded={navOpen}
          >
            <span /><span /><span />
          </button>
          <div className="crumb">
            {company.name} <span className="crumbsep">/</span> <b>{currentChild?.label ?? "Workspace"}</b>
          </div>
          <div className="topright" style={{ position: "relative" }}>
            <SearchBox />
            <span className="env">Demo environment</span>
            <NotificationBell />
            <button className="userbtn" onClick={() => setMenuOpen((open) => !open)} aria-haspopup="menu" aria-expanded={menuOpen}>
              <span className="avatar small">{initials(user.name)}</span>
              <span className="topname">{user.name}</span>
            </button>
            {menuOpen && (
              <div className="menu" role="menu" onClick={() => setMenuOpen(false)}>
                <div className="tiny" style={{ padding: "8px 10px" }}>
                  <strong style={{ color: "var(--ink)" }}>{user.name}</strong>
                  <br />
                  {user.email}
                </div>
                <div className="divider" />
                {user.companies.length > 1 && (
                  <Link role="menuitem" href="/select-company">Switch company</Link>
                )}
                <Link role="menuitem" href="/account/security">Sign-in &amp; security</Link>
                <button role="menuitem" onClick={() => void signOutOthers()}>Sign out other devices</button>
                <button role="menuitem" onClick={() => void signOut()}>Sign out</button>
              </div>
            )}
          </div>
        </header>
        <div className="content">
          {company.membership_status === "support" && (
            <div className="banner info" role="status">
              M.Y HRMS support access · read-only until {new Date(company.support_access_until ?? "").toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}. Every page you open is recorded for {company.name}.{" "}
              <Link className="link" href="/support">Back to operations</Link>
            </div>
          )}
          {children}
        </div>
        {notice && <div className="toast show" role="status">{notice}</div>}
      </main>
    </div>
  );
}
