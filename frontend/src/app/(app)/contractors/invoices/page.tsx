"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import type { Invoice } from "@/components/contractors/InvoicesPanel";
import { Guard } from "@/components/Guard";
import { Badge, EmptyState, PageHead, Panel, Table, formatDate, humanize, money } from "@/components/ui";
import { api, download } from "@/lib/api";

type Row = Invoice & { contractor: { id: string; name: string } };

const FILTERS: { key: string; label: string }[] = [
  { key: "outstanding", label: "Outstanding" },
  { key: "overdue", label: "Overdue" },
  { key: "draft", label: "Draft" },
  { key: "received", label: "Received" },
  { key: "pending_review", label: "Pending review" },
  { key: "approved", label: "Approved" },
  { key: "rejected", label: "Rejected" },
  { key: "paid", label: "Paid" },
  { key: "", label: "All" },
];

function InvoicesPage() {
  const [status, setStatus] = useState("outstanding");
  const [rows, setRows] = useState<Row[] | null>(null);
  const [counts, setCounts] = useState<Record<string, number>>({});

  useEffect(() => {
    api<{ data: Row[]; counts: Record<string, number> }>("/contractor-invoices", { query: { status } }).then((result) => {
      setRows(result.data);
      setCounts(result.counts);
    });
  }, [status]);

  const outstanding = (counts.received ?? 0) + (counts.pending_review ?? 0) + (counts.approved ?? 0);
  const countFor = (key: string) => (key === "outstanding" ? outstanding : key === "" ? undefined : counts[key]);

  return (
    <>
      <PageHead eyebrow="Contractors" title="Contractor invoices" subtitle="Every contractor invoice in one place. Contractor invoices are paid outside payroll." actions={<><button className="btn" onClick={() => void download("/contractor-invoices/export", { status })}>Export CSV</button><Link className="btn" href="/contractors">Contractors</Link></>} />
      <div className="callout">Contractors are not employees and are not paid through payroll. Approved invoices are paid outside the system and the payment is recorded here.</div>
      <div className="tabs" role="tablist">
        {FILTERS.map((filter) => (
          <button key={filter.key} role="tab" aria-selected={status === filter.key} className={`tab${status === filter.key ? " on" : ""}`} onClick={() => setStatus(filter.key)}>
            {filter.label}{countFor(filter.key) !== undefined && <span className="tiny"> {countFor(filter.key)}</span>}
          </button>
        ))}
      </div>
      <Panel bodyless>
        {rows === null ? <div className="empty">Loading…</div> : rows.length === 0 ? <EmptyState title="No invoices here">Invoices are entered on each contractor’s page.</EmptyState> : (
          <Table headers={["Contractor", "Reference", "Invoice date", "Due", "Amount", "Status"]}>
            {rows.map((row) => (
              <tr key={row.id}>
                <td><Link href={`/contractors/${row.contractor.id}`}>{row.contractor.name}</Link></td>
                <td><strong>{row.reference}</strong>{row.contract && <div className="tiny">{row.contract.title}</div>}</td>
                <td>{formatDate(row.issue_date)}</td>
                <td>{row.due_date ? formatDate(row.due_date) : "—"}</td>
                <td>{money(row.amount, row.currency)}</td>
                <td><Badge status={row.display_status} label={humanize(row.display_status)} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["contractors.view", "contractors.manage"]}>
      <InvoicesPage />
    </Guard>
  );
}
