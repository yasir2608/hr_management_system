"use client";

import Link from "next/link";
import { Fragment, useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, PageHead, Panel, Table, formatDateTime } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

type Entry = {
  id: string; action: string; area: string; description: string; actor: string | null; created_at: string; ip_address: string | null;
  subject: { type: string; id: string | null; label: string; link: string | null } | null;
  details: Record<string, unknown> | null; details_restricted: boolean;
};
type History = {
  data: Entry[]; current_page: number; last_page: number; total: number;
  areas: { key: string; label: string }[]; actors: { id: string; name: string }[];
};
type Integrity = { intact: boolean; checked: number; broken_entry_id: string | null; checked_at: string };

const EMPTY_FILTERS = { search: "", area: "", actor_user_id: "", from: "", to: "" };

/** Readable change lines from an entry's details, e.g. "job_title: Analyst → Lead". */
function changeLines(details: Record<string, unknown>): string[] {
  const changes = details.changes as Record<string, { old: unknown; new: unknown }> | undefined;
  if (changes && typeof changes === "object") {
    return Object.entries(changes).map(([field, change]) => `${field.replace(/_/g, " ")}: ${String(change?.old ?? "—")} → ${String(change?.new ?? "—")}`);
  }
  return [];
}

function ActivityPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [page, setPage] = useState(1);
  const [history, setHistory] = useState<History | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [integrity, setIntegrity] = useState<Integrity | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setHistory(await api<History>("/activity", { query: { ...filters, page } }));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not load the activity history.");
    }
  }, [filters, page]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch on filter or page change
    void load();
  }, [load]);

  const set = (patch: Partial<typeof filters>) => { setFilters({ ...filters, ...patch }); setPage(1); };

  async function checkIntegrity() {
    setError(null);
    try {
      setIntegrity(await api<Integrity>("/activity/integrity"));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not check the history.");
    }
  }

  return (
    <>
      <PageHead
        eyebrow="Administration"
        title="Activity history"
        subtitle="Who changed what, and when. Entries cannot be changed or removed, and sensitive values are masked."
        actions={<>
          <button className="btn" onClick={() => void checkIntegrity()}>Check integrity</button>
          <button className="btn" onClick={() => void download("/activity/export", filters)}>Export CSV</button>
        </>}
      />
      {error && <Banner tone="error">{error}</Banner>}
      {integrity && (integrity.intact
        ? <Banner tone="success">History intact: all {integrity.checked} entries checked on {formatDateTime(integrity.checked_at)}; none were changed or removed.</Banner>
        : <Banner tone="error">The history check failed at entry {integrity.broken_entry_id} after {integrity.checked} entries. Contact M.Y HRMS support.</Banner>)}

      <Panel>
        <div className="formgrid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))" }}>
          <Field label="Search"><input className="input" value={filters.search} placeholder="Name, record or action" onChange={(event) => set({ search: event.target.value })} /></Field>
          <Field label="Area">
            <select value={filters.area} onChange={(event) => set({ area: event.target.value })}>
              <option value="">All areas</option>
              {history?.areas.map((area) => <option key={area.key} value={area.key}>{area.label}</option>)}
            </select>
          </Field>
          <Field label="Who">
            <select value={filters.actor_user_id} onChange={(event) => set({ actor_user_id: event.target.value })}>
              <option value="">Anyone</option>
              {history?.actors.map((actor) => <option key={actor.id} value={actor.id}>{actor.name}</option>)}
            </select>
          </Field>
          <Field label="From"><input className="input" type="date" value={filters.from} onChange={(event) => set({ from: event.target.value })} /></Field>
          <Field label="To"><input className="input" type="date" value={filters.to} onChange={(event) => set({ to: event.target.value })} /></Field>
        </div>
      </Panel>

      <Panel title={history ? `${history.total} entr${history.total === 1 ? "y" : "ies"}` : "Entries"} bodyless={!!history && history.data.length > 0}>
        {!history ? <div className="empty">Loading…</div> : history.data.length === 0 ? <EmptyState icon="◷" title="No activity matches">Try other filters.</EmptyState> : (
          <>
            <Table headers={["When", "Who", "What happened", "Record", ""]}>
              {history.data.map((entry) => (
                <Fragment key={entry.id}>
                  <tr>
                    <td style={{ whiteSpace: "nowrap" }}>{formatDateTime(entry.created_at)}</td>
                    <td>{entry.actor ?? <span className="muted">System</span>}</td>
                    <td>{entry.description}<div className="tiny"><Badge tone="gray" label={entry.area} /> <code>{entry.action}</code></div></td>
                    <td>{entry.subject ? (entry.subject.link ? <Link className="link" href={entry.subject.link}>{entry.subject.label}</Link> : entry.subject.label) : "—"}</td>
                    <td style={{ textAlign: "right" }}>
                      {(entry.details || entry.details_restricted || entry.ip_address) && <button className="btn soft" onClick={() => setOpen(open === entry.id ? null : entry.id)}>{open === entry.id ? "Hide" : "Details"}</button>}
                    </td>
                  </tr>
                  {open === entry.id && (
                    <tr>
                      <td colSpan={5} style={{ background: "var(--soft, #f8fafc)" }}>
                        {entry.details_restricted && <div className="tiny">Details of payroll and pay entries are shown only to people with salary access.</div>}
                        {entry.details && (changeLines(entry.details).length > 0
                          ? changeLines(entry.details).map((line) => <div key={line} className="tiny">{line}</div>)
                          : <pre className="tiny" style={{ whiteSpace: "pre-wrap", margin: 0 }}>{JSON.stringify(entry.details, null, 2)}</pre>)}
                        {entry.ip_address && <div className="tiny" style={{ marginTop: 6 }}>IP address: {entry.ip_address}</div>}
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </Table>
            {history.last_page > 1 && (
              <div className="row" style={{ padding: "12px 16px", justifyContent: "space-between" }}>
                <button className="btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>Newer</button>
                <span className="tiny">Page {history.current_page} of {history.last_page}</span>
                <button className="btn" disabled={page >= history.last_page} onClick={() => setPage(page + 1)}>Older</button>
              </div>
            )}
          </>
        )}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["activity.view"]}>
      <ActivityPage />
    </Guard>
  );
}
