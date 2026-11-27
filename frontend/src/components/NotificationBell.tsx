"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import { formatDateTime } from "./ui";

export type AppNotification = {
  id: string; kind: string; title: string; body: string | null; link: string | null;
  action_required: boolean; read: boolean; resolved: boolean; created_at: string;
};

/** Refresh the unread count this often while the app is open. */
const POLL_MS = 60_000;

/** The bell in the top bar: unread count, the latest notifications, and a link to all of them. */
export function NotificationBell() {
  const router = useRouter();
  const [count, setCount] = useState(0);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<AppNotification[] | null>(null);
  const ref = useRef<HTMLDivElement>(null);

  const refreshCount = useCallback(async () => {
    try {
      setCount((await api<{ unread_count: number }>("/notifications/count")).unread_count);
    } catch {
      // A failed refresh keeps the last count; the next poll tries again.
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch, then polling
    void refreshCount();
    const timer = window.setInterval(() => void refreshCount(), POLL_MS);
    return () => window.clearInterval(timer);
  }, [refreshCount]);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);

  async function toggle() {
    const next = !open;
    setOpen(next);
    if (next) {
      const result = await api<{ data: AppNotification[]; unread_count: number }>("/notifications");
      setItems(result.data.slice(0, 8));
      setCount(result.unread_count);
    }
  }

  async function openItem(item: AppNotification) {
    setOpen(false);
    if (!item.read) {
      setCount((await api<{ unread_count: number }>(`/notifications/${item.id}/read`, { method: "POST" })).unread_count);
    }
    if (item.link) router.push(item.link);
  }

  async function readAll() {
    await api("/notifications/read-all", { method: "POST" });
    setCount(0);
    setItems((current) => current?.map((item) => ({ ...item, read: true })) ?? null);
  }

  return (
    <div ref={ref} style={{ position: "relative" }}>
      <button className="userbtn" onClick={() => void toggle()} aria-label={`Notifications, ${count} unread`} aria-haspopup="menu" aria-expanded={open} style={{ position: "relative", fontSize: 18 }}>
        <span aria-hidden>🔔</span>
        {count > 0 && (
          <span style={{ position: "absolute", top: -4, right: -6, minWidth: 18, height: 18, padding: "0 5px", borderRadius: 9, background: "var(--red, #d64545)", color: "#fff", fontSize: 11, fontWeight: 700, display: "grid", placeItems: "center" }}>
            {count > 99 ? "99+" : count}
          </span>
        )}
      </button>
      {open && (
        <div className="menu" role="menu" style={{ width: 360, padding: 0 }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "10px 12px", borderBottom: "1px solid var(--line)" }}>
            <strong style={{ color: "var(--ink)" }}>Notifications</strong>
            {count > 0 && <button style={{ width: "auto", padding: "2px 6px" }} className="tiny" onClick={() => void readAll()}>Mark all read</button>}
          </div>
          {items === null ? <div className="tiny" style={{ padding: 12 }}>Loading…</div> : items.length === 0 ? <div className="tiny" style={{ padding: 12 }}>Nothing new. You are all caught up.</div> : (
            <div style={{ maxHeight: 380, overflowY: "auto" }}>
              {items.map((item) => (
                <button key={item.id} onClick={() => void openItem(item)} style={{ borderRadius: 0, borderBottom: "1px solid var(--line)", background: item.read ? "transparent" : "#f5f9ff" }}>
                  <div style={{ display: "flex", gap: 8, alignItems: "flex-start" }}>
                    {!item.read && <span aria-label="Unread" style={{ width: 8, height: 8, borderRadius: 4, background: "var(--blue)", marginTop: 6, flex: "none" }} />}
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontWeight: item.read ? 400 : 600 }}>{item.title}</div>
                      {item.body && <div className="tiny">{item.body}</div>}
                      <div className="tiny">{formatDateTime(item.created_at)}{item.resolved && " · done"}</div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          )}
          <Link href="/notifications" onClick={() => setOpen(false)} style={{ textAlign: "center", padding: 10, borderRadius: 0 }}>See all notifications</Link>
        </div>
      )}
    </div>
  );
}
