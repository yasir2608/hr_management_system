"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { ItemTotals, type ItemGroup } from "@/components/payroll/ItemTotals";
import { RunIssuesPanel, type IssueSummary } from "@/components/payroll/RunIssuesPanel";
import { RunResults, type Results } from "@/components/payroll/RunResults";
import { Badge, Banner, PageHead, Panel, Person, Stat, Table, formatDate, formatDateTime, humanize, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Issue = { key: string; message: string; severity: "blocking" | "warning" };
type PayRow = { amount: string | null; currency: string; frequency: string; valid_from: string; valid_to: string | null };
type Item = { id: string; kind: string; name: string; calculation: string; amount: string | null; percent: string | null };
type Line = {
  id: string; employee_id: string; employee_name: string; employee_number: string | null; included: boolean; readiness: string; issues: Issue[];
  inputs: { pay: PayRow[]; pay_items: Item[]; unpaid_leave_days: number; has_tax_details: boolean; has_bank_account: boolean };
  results: Results | null;
};
type Totals = { employees: number; gross_pay?: string; employee_deductions?: string; net_pay?: string; employer_costs?: string; total_cost?: string; with_warnings: number; blocked: number; rules_versions: string[]; calculated_at: string };
type Run = {
  id: string; status: string; country_code: string; country_name: string; currency: string; pay_frequency: string;
  group: { id: string; name: string } | null; period_start: string; period_end: string; pay_date: string;
  employee_count: number; excluded_count: number; created_by: string | null; inputs_gathered_at: string | null;
  amounts_restricted: boolean; lines: Line[]; totals: Totals | null; rules_versions: string[] | null; company_name: string | null; item_totals: ItemGroup[] | null;
};

const PER: Record<string, string> = { annual: "/yr", monthly: "/mo", hourly: "/hr" };

function RunPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const [run, setRun] = useState<Run | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setRun(await api<Run>(`/payroll/runs/${id}`));
  }, [id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  const [calculating, setCalculating] = useState(false);
  const [issueSummary, setIssueSummary] = useState<IssueSummary | null>(null);

  async function calculate() {
    setError(null);
    setCalculating(true);
    try {
      setRun(await api<Run>(`/payroll/runs/${id}/calculate`, { method: "POST" }));
      setNotice("Calculated. Review the results below.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not calculate.");
    } finally {
      setCalculating(false);
    }
  }

  async function submitForReview() {
    setError(null);
    try {
      setRun(await api<Run>(`/payroll/runs/${id}/submit-review`, { method: "POST" }));
      setNotice("Sent for review.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not send for review.");
    }
  }

  async function cancel() {
    if (!window.confirm("Cancel this run? Its snapshot is kept for the record and you can start a new run for the period.")) return;
    try {
      setNotice((await api<{ message: string }>(`/payroll/runs/${id}/cancel`, { method: "POST" })).message);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Could not cancel.");
    }
  }

  if (!run) return <div className="loadingpage">Loading…</div>;
  const included = run.lines.filter((line) => line.included);
  const excluded = run.lines.filter((line) => !line.included);
  const amount = (value: string | null) => (run.amounts_restricted ? <span className="badge gray">Restricted</span> : money(value, run.currency));

  return (
    <>
      <PageHead
        eyebrow={`${run.company_name ?? "Payroll run"} · ${run.country_name} payroll`}
        title={`${run.group?.name ?? "Payroll"}: ${formatDate(run.period_start)} – ${formatDate(run.period_end)}`}
        subtitle={`Pay date ${formatDate(run.pay_date)} · ${run.currency} · started by ${run.created_by ?? "—"}`}
        actions={<>
          <Link className="btn" href="/payroll/runs">All runs</Link>
          {can("payroll.run") && run.status !== "approved" && run.status !== "cancelled" && <button className="btn danger" onClick={() => void cancel()}>Cancel run</button>}
          {can("payroll.run") && (run.status === "draft" || run.status === "calculated") && <button className="btn primary" disabled={calculating} onClick={() => void calculate()}>{calculating ? "Calculating…" : run.status === "calculated" ? "Calculate again" : "Calculate"}</button>}
          {can("payroll.run") && run.status === "calculated" && (issueSummary?.blocking ?? 1) === 0 && <button className="btn primary" onClick={() => void submitForReview()}>Send for review</button>}
        </>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      {run.status === "draft" && <Banner tone="info">Draft: inputs have been gathered. Calculate to work out pay.</Banner>}
      {run.totals && run.totals.blocked > 0 && <Banner tone="error">{run.totals.blocked} employee{run.totals.blocked === 1 ? " has" : "s have"} blocking issues. Fix them before this run can be approved.</Banner>}
      {run.totals && (
        <div className="grid stats">
          <Stat label="Gross pay" value={run.amounts_restricted ? "Restricted" : money(run.totals.gross_pay ?? "0", run.currency)} note={`${run.totals.employees} employees`} />
          <Stat label="Employee deductions" value={run.amounts_restricted ? "Restricted" : money(run.totals.employee_deductions ?? "0", run.currency)} />
          <Stat label="Net pay" value={run.amounts_restricted ? "Restricted" : money(run.totals.net_pay ?? "0", run.currency)} />
          <Stat label="Total cost" value={run.amounts_restricted ? "Restricted" : money(run.totals.total_cost ?? "0", run.currency)} note={run.amounts_restricted ? undefined : `incl. ${money(run.totals.employer_costs ?? "0", run.currency)} employer costs`} />
        </div>
      )}
      {run.status === "cancelled" && <Banner tone="error">This run was cancelled.</Banner>}
      {run.status === "in_review" && <Banner tone="info">In review. Check the totals, each employee’s breakdown and any warnings before approval. Calculating again takes the run back out of review.</Banner>}
      <div className="grid stats">
        <Stat label="Status" value={<Badge status={run.status} label={humanize(run.status)} />} />
        <Stat label="Employees included" value={included.length} />
        <Stat label="Left out" value={excluded.length} note={excluded.length ? "Blocking issues" : undefined} />
        <Stat label="Inputs gathered" value={run.inputs_gathered_at ? formatDateTime(run.inputs_gathered_at) : "—"} note={run.rules_versions?.length ? `Tax rules ${run.rules_versions.join(", ")}` : undefined} />
      </div>
      {excluded.length > 0 && (
        <Panel title="Left out of this run" subtitle="Fix these, then gather inputs again before calculating." bodyless>
          <Table headers={["Employee", "Why"]}>
            {excluded.map((line) => (
              <tr key={line.id}>
                <td><Link href={`/payroll/employees/${line.employee_id}`} style={{ textDecoration: "none" }}><Person name={line.employee_name} detail={line.employee_number} /></Link></td>
                <td>{line.issues.filter((issue) => issue.severity === "blocking").map((issue) => <div key={issue.key}>{issue.message}</div>)}</td>
              </tr>
            ))}
          </Table>
        </Panel>
      )}
      {issueSummary?.can_approve && <Banner tone="success">No blocking issues and every warning has been reviewed: this run is ready for approval.</Banner>}
      <RunIssuesPanel runId={run.id} version={run.totals?.calculated_at ?? run.inputs_gathered_at ?? ""} canReview={can("payroll.run") && run.status !== "approved" && run.status !== "cancelled"} onChange={setIssueSummary} />
      {run.item_totals && run.item_totals.length > 0 && <ItemTotals groups={run.item_totals} currency={run.currency} />}
      {run.lines.some((line) => line.results) && <RunResults rows={included.filter((line) => line.results)} currency={run.currency} restricted={run.amounts_restricted} />}
      <Panel title="Gathered inputs" subtitle="A snapshot taken when the run started. Later changes do not alter it." bodyless>
        <Table headers={["Employee", "Pay in this period", "Allowances and deductions", "Other", "Review"]}>
          {included.map((line) => (
            <tr key={line.id}>
              <td><Link href={`/payroll/employees/${line.employee_id}`} style={{ textDecoration: "none" }}><Person name={line.employee_name} detail={line.employee_number} /></Link></td>
              <td>{line.inputs.pay.map((row) => <div key={row.valid_from}>{amount(row.amount)} <span className="tiny">{PER[row.frequency] ?? ""} from {formatDate(row.valid_from)}</span></div>)}</td>
              <td>{line.inputs.pay_items.length === 0 ? <span className="muted">None</span> : line.inputs.pay_items.map((item) => (
                <div key={item.id}><Badge tone={item.kind === "allowance" ? "green" : "amber"} label={item.kind === "allowance" ? "+" : "−"} /> {item.name}: {item.calculation === "fixed" ? amount(item.amount) : run.amounts_restricted ? <span className="badge gray">Restricted</span> : `${Number(item.percent)}%`}</div>
              ))}</td>
              <td className="tiny">
                {line.inputs.unpaid_leave_days > 0 && <div>{line.inputs.unpaid_leave_days} unpaid leave day(s)</div>}
                {!line.inputs.has_bank_account && <div>No bank account</div>}
                {line.inputs.has_tax_details && <div>Tax details captured</div>}
              </td>
              <td>{line.issues.length === 0 ? <Badge status="ready" label="Ready" /> : line.issues.map((issue) => <div key={issue.key} className="tiny"><Badge tone="amber" label="Review" /> {issue.message}</div>)}</td>
            </tr>
          ))}
        </Table>
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.run"]}>
      <RunPage />
    </Guard>
  );
}
