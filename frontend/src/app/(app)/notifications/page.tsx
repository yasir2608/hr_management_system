"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import type { AppNotification } from "@/components/NotificationBell";
import { Badge, EmptyState, PageHead, Panel, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";

type Preference = { key: string; label: string; description: string; company_email: boolean; email: boolean };

/** The person's own email choices per category. Categories the company does not email are shown as off. */
function EmailPreferences() {
  const [categories, setCategories] = useState<Preference[] | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    void api<{ categories: Preference[] }>("/me/notification-preferences").then((result) => setCategories(result.categories));
  }, []);

  async function toggle(key: string, email: boolean) {
    if (!categories) return;
    const next = categories.map((category) => (category.key === key ? { ...category, email } : category));
    const result = await api<{ categories: Preference[] }>("/me/notification-preferences", {
      method: "PUT", body: { email_off: next.filter((category) => category.company_email && !category.email).map((category) => category.key) },
    });
    setCategories(result.categories);
    setSaved(true);
  }

  if (!categories) return null;

  return (
    <Panel title="Email preferences" subtitle={saved ? "Saved." : "Notifications always appear here. Choose which ones are also emailed to you."}>
      {categories.map((category) => (
        <label key={category.key} className="checkrow" style={{ alignItems: "flex-start", padding: "6px 0" }}>
          <input type="checkbox" disabled={!category.company_email} checked={category.email} onChange={(event) => void toggle(category.key, event.target.checked)} style={{ marginTop: 3 }} />
          <span><strong>{category.label}</strong><span className="tiny" style={{ display: "block" }}>{category.company_email ? category.description : "Your company does not send emails for this."}</span></span>
        </label>
      ))}
    </Panel>
  );
}

export default function NotificationsPage() {
  const router = useRouter();
  const [filter, setFilter] = useState<"all" | "unread">("all");
  const [data, setData] = useState<{ data: AppNotification[]; unread_count: number } | null>(null);

  const load = useCallback(async () => {
    setData(await api<{ data: AppNotification[]; unread_count: number }>("/notifications", { query: { filter } }));
  }, [filter]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on filter change
    void load();
  }, [load]);

  async function open(item: AppNotification) {
    if (!item.read) await api(`/notifications/${item.id}/read`, { method: "POST" });
    if (item.link) router.push(item.link);
    else void load();
  }

  async function readAll() {
    await api("/notifications/read-all", { method: "POST" });
    void load();
  }

  return (
    <>
      <PageHead
        eyebrow="Workspace"
        title="Notifications"
        subtitle="Things that need your attention. Each one opens the page where you can act."
        actions={data && data.unread_count > 0 && <button className="btn" onClick={() => void readAll()}>Mark all read</button>}
      />
      <div className="tabs" role="tablist">
        {(["all", "unread"] as const).map((key) => (
          <button key={key} role="tab" aria-selected={filter === key} className={`tab${filter === key ? " on" : ""}`} onClick={() => setFilter(key)}>
            {key === "all" ? "All" : "Unread"}{key === "unread" && data && <span className="tiny"> {data.unread_count}</span>}
          </button>
        ))}
      </div>
      <Panel bodyless={!!data && data.data.length > 0}>
        {!data ? <div className="empty">Loading…</div> : data.data.length === 0 ? <EmptyState icon="🔔" title={filter === "unread" ? "No unread notifications" : "No notifications yet"}>Approvals, decisions, payslips and tasks for you will appear here.</EmptyState> : (
          <div>
            {data.data.map((item) => (
              <div key={item.id} style={{ display: "flex", gap: 12, alignItems: "center", padding: "13px 16px", borderTop: "1px solid var(--line)", background: item.read ? undefined : "#f5f9ff" }}>
                <span aria-label={item.read ? "Read" : "Unread"} style={{ width: 8, height: 8, borderRadius: 4, background: item.read ? "transparent" : "var(--blue)", flex: "none" }} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: item.read ? 500 : 650 }}>{item.title} {item.action_required && (item.resolved ? <Badge tone="green" label="Done" /> : <Badge tone="amber" label="Action needed" />)}</div>
                  {item.body && <div className="tiny">{item.body}</div>}
                  <div className="tiny">{formatDateTime(item.created_at)}</div>
                </div>
                {item.link ? <button className="btn" onClick={() => void open(item)}>Open</button> : !item.read && <button className="btn soft" onClick={() => void open(item)}>Mark read</button>}
              </div>
            ))}
          </div>
        )}
      </Panel>
      <EmailPreferences />
    </>
  );
}
