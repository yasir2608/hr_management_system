"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, Modal, PageHead, Panel, Table, formatDate, humanize } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Run = {
  id: string; status: string; country_code: string; currency: string; group: { id: string; name: string } | null;
  period_start: string; period_end: string; pay_date: string; employee_count: number; excluded_count: number; created_at: string;
};
type Group = { id: string; name: string; country_code: string };
type Period = { start: string; end: string; pay_date: string; input_cutoff: string; is_current: boolean; run: { id: string; status: string } | null };

function NewRunModal({ groups, onClose }: { groups: Group[]; onClose: () => void }) {
  const router = useRouter();
  const [groupId, setGroupId] = useState(groups[0]?.id ?? "");
  const [periods, setPeriods] = useState<Period[] | null>(null);
  const [periodStart, setPeriodStart] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    if (!groupId) return;
    api<{ data: Period[] }>("/payroll/runs/periods", { query: { group_id: groupId } })
      .then((result) => {
        setPeriods(result.data);
        setPeriodStart(result.data.find((period) => period.is_current && !period.run)?.start ?? result.data.find((period) => !period.run)?.start ?? "");
        setError(null);
      })
      .catch((caught) => { setPeriods([]); setError(caught instanceof ApiError ? caught : null); });
  }, [groupId]);

  async function start() {
    setError(null);
    setStarting(true);
    try {
      const run = await api<Run>("/payroll/runs", { body: { group_id: groupId, period_start: periodStart } });
      router.push(`/payroll/runs/${run.id}`);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not start the run.", 0));
      setStarting(false);
    }
  }

  return (
    <Modal title="Start a draft payroll run" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" disabled={!periodStart || starting} onClick={() => void start()}>{starting ? "Gathering inputs…" : "Start draft run"}</button></>}>
      {error && <Banner tone="error">{error.errors.group_id?.[0] ?? error.errors.period_start?.[0] ?? error.message}</Banner>}
      <div className="stack">
        <Field label="Payroll group" required>
          <select value={groupId} onChange={(event) => setGroupId(event.target.value)}>
            {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
          </select>
        </Field>
        <Field label="Pay period" required>
          {periods === null ? <span className="muted">Loading…</span> : periods.length === 0 ? <span className="muted">No pay periods: set up the schedule first.</span> : (
            <div className="stack">
              {periods.map((period) => (
                <label key={period.start} className="checkrow" style={{ opacity: period.run ? 0.6 : 1 }}>
                  <input type="radio" name="period" disabled={!!period.run} checked={periodStart === period.start} onChange={() => setPeriodStart(period.start)} />
                  {formatDate(period.start)} – {formatDate(period.end)} <span className="tiny">paid {formatDate(period.pay_date)}</span>
                  {period.is_current && <Badge tone="blue" label="Current" />}
                  {period.run && <Badge status={period.run.status} label={`Run ${humanize(period.run.status).toLowerCase()}`} />}
                </label>
              ))}
            </div>
          )}
        </Field>
        <div className="tiny">The run checks readiness and takes a snapshot of each employee’s pay, allowances, deductions, unpaid leave and tax details for the period. Nothing is paid.</div>
      </div>
    </Modal>
  );
}

function RunsPage() {
  const { can } = useAuth();
  const [runs, setRuns] = useState<Run[] | null>(null);
  const [groups, setGroups] = useState<Group[]>([]);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    const result = await api<{ data: Run[]; groups: Group[] }>("/payroll/runs");
    setRuns(result.data);
    setGroups(result.groups);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payroll runs" subtitle="One run per payroll group and pay period: draft, calculate, review and approve." actions={can("payroll.run") && groups.length > 0 && <button className="btn primary" onClick={() => setCreating(true)}>Start draft run</button>} />
      <Panel bodyless>
        {runs === null ? <div className="empty">Loading…</div> : runs.length === 0 ? <EmptyState title="No payroll runs yet">Start a draft run for a payroll group and pay period.</EmptyState> : (
          <Table headers={["Group", "Pay period", "Pay date", "Employees", "Status"]}>
            {runs.map((run) => (
              <tr key={run.id}>
                <td><Link href={`/payroll/runs/${run.id}`}><strong>{run.group?.name ?? "—"}</strong></Link><div className="tiny">{run.country_code} · {run.currency}</div></td>
                <td>{formatDate(run.period_start)} – {formatDate(run.period_end)}</td>
                <td>{formatDate(run.pay_date)}</td>
                <td>{run.employee_count}{run.excluded_count > 0 && <div className="tiny">{run.excluded_count} left out</div>}</td>
                <td><Badge status={run.status} label={humanize(run.status)} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {creating && <NewRunModal groups={groups} onClose={() => setCreating(false)} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.run"]}>
      <RunsPage />
    </Guard>
  );
}
