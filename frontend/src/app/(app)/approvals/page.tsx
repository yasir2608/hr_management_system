"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ApprovalDetail, approvalStatus } from "@/components/approvals/ApprovalDetail";
import { Badge, EmptyState, Modal, PageHead, Panel, Person, formatDate, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";

type Item = {
  key: string; source: "approval" | "payroll_run" | "contractor_invoice"; id: string; type: string; type_label: string; title: string;
  requester: string | null; facts: { label: string; value: string }[]; submitted_at: string | null; decided_at: string | null;
  due_date: string | null; status: string; approver: string | null; step: string | null; action: string | null; link: string | null;
  waiting_days: number | null; overdue: boolean;
};
type Inbox = {
  view: string; data: Item[]; types: { key: string; label: string }[];
  counts: { waiting: number; all: number | null }; can_manage: boolean;
};

const VIEWS = [
  { key: "waiting", label: "Waiting for you" },
  { key: "mine", label: "My requests" },
  { key: "decided", label: "Decided by you" },
  { key: "all", label: "All open requests", manage: true },
];

const EMPTY: Record<string, { title: string; text: string }> = {
  waiting: { title: "Nothing waiting for you", text: "Requests assigned to you, or to a role you hold, appear here." },
  mine: { title: "No requests yet", text: "Leave and attendance correction requests you make appear here with their status." },
  decided: { title: "No decisions yet", text: "Requests you approve, decline or review appear here." },
  all: { title: "No open requests", text: "Every open request in the company appears here so it can be reassigned if an approver is away." },
};

function ApprovalsPage() {
  const [view, setView] = useState("waiting");
  const [type, setType] = useState("");
  const [inbox, setInbox] = useState<Inbox | null>(null);
  const [open, setOpen] = useState<Item | null>(null);
  const [members, setMembers] = useState<{ id: string; name: string }[]>([]);

  const load = useCallback(async () => {
    setInbox(await api<Inbox>("/approvals", { query: { view, ...(type ? { type } : {}) } }));
  }, [view, type]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on view or filter change
    void load();
  }, [load]);

  useEffect(() => {
    void api<{ data: { id: string; name: string }[] }>("/approvals/assignees").then((result) => setMembers(result.data));
  }, []);

  const choose = (key: string) => { setView(key); setType(""); setInbox(null); };
  const views = VIEWS.filter((option) => !option.manage || inbox?.can_manage);

  return (
    <>
      <PageHead eyebrow="Workspace" title="Approvals" subtitle="One queue for leave, attendance corrections, contractor invoices and payroll review." />
      <div className="tabs" role="tablist">
        {views.map((option) => {
          const count = option.key === "waiting" ? inbox?.counts.waiting : option.key === "all" ? inbox?.counts.all : undefined;
          return (
            <button key={option.key} role="tab" aria-selected={view === option.key} className={`tab${view === option.key ? " on" : ""}`} onClick={() => choose(option.key)}>
              {option.label}{count !== undefined && count !== null && <span className="tiny"> {count}</span>}
            </button>
          );
        })}
      </div>
      <Panel
        title={VIEWS.find((option) => option.key === view)?.label}
        right={inbox && inbox.types.length > 1 && (
          <select value={type} onChange={(event) => setType(event.target.value)} aria-label="Request type">
            <option value="">All types</option>
            {inbox.types.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}
          </select>
        )}
        bodyless={!!inbox && inbox.data.length > 0}
      >
        {!inbox ? <div className="empty">Loading…</div> : inbox.data.length === 0 ? <EmptyState icon="✓" title={EMPTY[view].title}>{EMPTY[view].text}</EmptyState> : (
          <div>
            {inbox.data.map((item) => (
              <div key={item.key} style={{ display: "flex", alignItems: "center", gap: 14, padding: "14px 16px", borderTop: "1px solid var(--line, #eef1f5)", flexWrap: "wrap" }}>
                <div style={{ flex: "1 1 320px", minWidth: 0 }}>
                  <Person
                    name={item.requester ?? "—"}
                    detail={<>
                      <strong style={{ color: "var(--ink)" }}>{item.title}</strong>{" "}
                      <Badge tone="blue" label={item.type_label} />{" "}
                      {view !== "waiting" && (approvalStatus[item.status] ?? <Badge status={item.status} />)}
                    </>}
                  />
                  <div className="tiny" style={{ marginTop: 6, marginLeft: 47 }}>
                    {item.facts.map((fact) => <span key={fact.label} style={{ marginRight: 12 }}>{fact.label}: <span style={{ color: "var(--ink)" }}>{fact.value}</span></span>)}
                  </div>
                  <div className="tiny" style={{ marginTop: 4, marginLeft: 47 }}>
                    {item.submitted_at && <>Sent {formatDateTime(item.submitted_at)}</>}
                    {item.waiting_days !== null && item.waiting_days > 0 && <> · waiting {item.waiting_days} day{item.waiting_days === 1 ? "" : "s"}</>}
                    {item.due_date && <> · due {formatDate(item.due_date)}{item.overdue && <> <Badge tone="red" label="Overdue" /></>}</>}
                    {item.approver && <> · {item.step ? `${item.step}, ` : ""}approver: {item.approver}</>}
                    {item.decided_at && view !== "waiting" && <> · decided {formatDateTime(item.decided_at)}</>}
                  </div>
                </div>
                <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  {item.action && <span className="tiny" style={{ maxWidth: 200, textAlign: "right" }}>{item.action}</span>}
                  {item.link
                    ? <Link className={`btn${item.action ? " primary" : ""}`} href={item.link}>{item.action ? "Review" : "Open"}</Link>
                    : <button className={`btn${item.action ? " primary" : ""}`} onClick={() => setOpen(item)}>{item.action ? "Review" : "Open"}</button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </Panel>
      {open && (
        <Modal title={open.type_label} onClose={() => { setOpen(null); void load(); }}>
          <ApprovalDetail id={open.id} members={members} onChanged={() => void load()} />
        </Modal>
      )}
    </>
  );
}

export default function Page() {
  return <ApprovalsPage />;
}
