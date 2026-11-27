"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { EmptyState, PageHead, Panel, Table, formatDateTime, humanize } from "@/components/ui";
import { api, download } from "@/lib/api";

type Entry = {
  id: string;
  action: string;
  description: string;
  properties: { reason?: string; ticket_reference?: string } | null;
  ip_address: string | null;
  created_at: string;
  actor: { id: string; name: string; email: string } | null;
  company: { id: string; name: string } | null;
  target_user: { id: string; name: string; email: string } | null;
};

type Listing = { data: Entry[]; total: number; current_page: number; last_page: number; actions: string[]; staff: { id: string; name: string }[] };

const blank = { action: "", actor_user_id: "", from: "", to: "", search: "" };

export default function PlatformActivityPage() {
  const [filters, setFilters] = useState(blank);
  const [page, setPage] = useState(1);
  const [listing, setListing] = useState<Listing | null>(null);

  useEffect(() => {
    api<Listing>("/platform/activity", { query: { ...filters, page }, noCompany: true }).then(setListing);
  }, [filters, page]);

  const set = (patch: Partial<typeof blank>) => { setPage(1); setFilters({ ...filters, ...patch }); };

  return (
    <>
      <PageHead
        eyebrow="Staff console"
        title="Platform activity"
        subtitle="Everything M.Y HRMS staff have done across customer companies. Entries cannot be edited or removed."
        actions={<button className="btn" onClick={() => void download("/platform/activity/export", filters)}>Export CSV</button>}
      />
      <Panel
        title={listing ? `${listing.total} entr${listing.total === 1 ? "y" : "ies"}` : "Activity"}
        right={
          <div className="row" style={{ flexWrap: "wrap" }}>
            <input className="input" style={{ width: 180 }} placeholder="Search descriptions" value={filters.search} onChange={(event) => set({ search: event.target.value })} />
            <select style={{ width: 170 }} value={filters.action} onChange={(event) => set({ action: event.target.value })} aria-label="Action">
              <option value="">All actions</option>
              {listing?.actions.map((action) => <option key={action} value={action}>{humanize(action.replace(".", " "))}</option>)}
            </select>
            <select style={{ width: 150 }} value={filters.actor_user_id} onChange={(event) => set({ actor_user_id: event.target.value })} aria-label="Staff member">
              <option value="">All staff</option>
              {listing?.staff.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
            </select>
            <input className="input" type="date" style={{ width: 145 }} value={filters.from} onChange={(event) => set({ from: event.target.value })} aria-label="From" />
            <input className="input" type="date" style={{ width: 145 }} value={filters.to} onChange={(event) => set({ to: event.target.value })} aria-label="To" />
          </div>
        }
        bodyless
      >
        {listing && listing.data.length === 0 ? <EmptyState title="No activity matches these filters" /> : (
          <Table headers={["When", "Staff", "What", "Company or user", "Reason"]}>
            {listing?.data.map((entry) => (
              <tr key={entry.id}>
                <td style={{ whiteSpace: "nowrap" }}>{formatDateTime(entry.created_at)}<div className="tiny">{entry.ip_address}</div></td>
                <td>{entry.actor?.name ?? "—"}</td>
                <td>{entry.description}<div className="tiny">{entry.action}</div></td>
                <td>
                  {entry.company && <Link href={`/platform/companies/${entry.company.id}`}>{entry.company.name}</Link>}
                  {entry.target_user && <div><Link href={`/platform/users/${entry.target_user.id}`}>{entry.target_user.email}</Link></div>}
                  {!entry.company && !entry.target_user && "—"}
                </td>
                <td>{entry.properties?.reason ?? "—"}{entry.properties?.ticket_reference && <div className="tiny">Ticket {entry.properties.ticket_reference}</div>}</td>
              </tr>
            ))}
          </Table>
        )}
        {listing && listing.last_page > 1 && (
          <div className="row" style={{ justifyContent: "flex-end", padding: 12 }}>
            <button className="btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
            <span className="tiny">Page {listing.current_page} of {listing.last_page}</span>
            <button className="btn" disabled={page >= listing.last_page} onClick={() => setPage(page + 1)}>Next</button>
          </div>
        )}
      </Panel>
    </>
  );
}
