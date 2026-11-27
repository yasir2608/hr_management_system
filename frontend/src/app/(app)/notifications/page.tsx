"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import type { AppNotification } from "@/components/NotificationBell";
import { Badge, EmptyState, PageHead, Panel, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";

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
    </>
  );
}
