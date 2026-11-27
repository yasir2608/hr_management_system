"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table, formatDateTime, humanize, money } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export type Correction = {
  reason: string | null;
  corrects: { id: string; status: string; kind: string } | null;
  limited_to_count: number | null;
  reversed_at: string | null;
  corrected_by: { id: string; kind: string; status: string; correction_reason: string | null; created_at: string }[];
};

type Pair = { approved: string; corrected: string; difference: string };
type AdjustmentLine = { id: string; employee_name: string; results: { adjustment?: { gross_pay: Pair; net_pay: Pair; employer_costs: Pair } } | null };

/** Start a correction of an approved run: an adjustment for chosen employees, or a full replacement. */
export function CorrectRunModal({ runId, employees, onClose }: { runId: string; employees: { employee_id: string; employee_name: string }[]; onClose: () => void }) {
  const router = useRouter();
  const [method, setMethod] = useState<"adjust" | "replace">("adjust");
  const [reason, setReason] = useState("");
  const [picked, setPicked] = useState<string[]>([]);
  const [error, setError] = useState<ApiError | null>(null);

  async function start() {
    setError(null);
    try {
      const result = await api<{ id: string }>(`/payroll/runs/${runId}/corrections`, { body: { method, reason, employee_ids: method === "adjust" ? picked : undefined } });
      router.push(`/payroll/runs/${result.id}`);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not start the correction.", 0));
    }
  }

  return (
    <Modal title="Correct this approved run" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void start()}>Start correction</button></>}>
      {error && <Banner tone="error">{error.errors.run?.[0] ?? error.message}</Banner>}
      <div className="callout">The approved run is never changed. The correction is a new run that is calculated, reviewed and approved on its own, and both stay on record.</div>
      <div className="stack">
        <Field label="How to correct it" required>
          <label className="checkrow"><input type="radio" checked={method === "adjust"} onChange={() => setMethod("adjust")} /> <span><b>Adjustment</b> for some employees: pay or recover only the difference</span></label>
          <label className="checkrow"><input type="radio" checked={method === "replace"} onChange={() => setMethod("replace")} /> <span><b>Reverse and replace</b> the whole run: once the replacement is approved, this run is marked reversed</span></label>
        </Field>
        {method === "adjust" && (
          <Field label="Employees to adjust" required error={error?.errors.employee_ids?.[0]}>
            <div className="stack" style={{ gap: 6 }}>
              {employees.map((employee) => (
                <label key={employee.employee_id} className="checkrow">
                  <input type="checkbox" checked={picked.includes(employee.employee_id)} onChange={(event) => setPicked(event.target.checked ? [...picked, employee.employee_id] : picked.filter((id) => id !== employee.employee_id))} />
                  {employee.employee_name}
                </label>
              ))}
            </div>
          </Field>
        )}
        <Field label="Reason" required error={error?.errors.reason?.[0]} hint="Kept on both runs and in the activity log.">
          <textarea className="input" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="e.g. Car allowance missed for March" />
        </Field>
      </div>
    </Modal>
  );
}

/** Banners linking a correction and the run it corrects. */
export function CorrectionBanners({ kind, status, correction }: { kind: string; status: string; correction: Correction }) {
  return (
    <>
      {kind !== "regular" && correction.corrects && (
        <Banner tone="info">
          {kind === "adjustment" ? `Adjustment for ${correction.limited_to_count ?? 0} employee(s)` : "Replacement run"} correcting an{" "}
          <Link className="link" href={`/payroll/runs/${correction.corrects.id}`}>{correction.corrects.status} run</Link>. Reason: {correction.reason}
          {kind === "replacement" && status !== "approved" && " The original stays in force until this replacement is approved."}
        </Banner>
      )}
      {status === "reversed" && (
        <Banner tone="error">Reversed{correction.reversed_at && ` on ${formatDateTime(correction.reversed_at)}`}: replaced by an approved replacement run. Kept unchanged for the record.</Banner>
      )}
      {correction.corrected_by.length > 0 && (
        <Panel title="Corrections of this run" bodyless>
          <Table headers={["Correction", "Reason", "Started", "Status"]}>
            {correction.corrected_by.map((item) => (
              <tr key={item.id}>
                <td><Link href={`/payroll/runs/${item.id}`}><strong>{item.kind === "adjustment" ? "Adjustment" : "Replacement"}</strong></Link></td>
                <td>{item.correction_reason ?? "—"}</td>
                <td>{formatDateTime(item.created_at)}</td>
                <td><Badge status={item.status} label={humanize(item.status)} /></td>
              </tr>
            ))}
          </Table>
        </Panel>
      )}
    </>
  );
}

/** For an adjustment run: approved against corrected figures and the difference to pay or recover. */
export function AdjustmentPanel({ lines, totals, currency }: { lines: AdjustmentLine[]; totals: { gross_pay: string; net_pay: string; employer_costs: string }; currency: string }) {
  const signed = (value: string) => `${Number(value) > 0 ? "+" : ""}${money(value, currency)}`;

  return (
    <Panel title="Adjustment" subtitle={`Net ${Number(totals.net_pay) >= 0 ? "to pay" : "to recover"}: ${signed(totals.net_pay)} · employer costs ${signed(totals.employer_costs)}`} bodyless>
      <Table headers={["Employee", "Gross (approved → corrected)", "Net (approved → corrected)", "Net difference"]}>
        {lines.filter((line) => line.results?.adjustment).map((line) => {
          const adjustment = line.results!.adjustment!;
          return (
            <tr key={line.id}>
              <td>{line.employee_name}</td>
              <td>{money(adjustment.gross_pay.approved, currency)} → {money(adjustment.gross_pay.corrected, currency)}</td>
              <td>{money(adjustment.net_pay.approved, currency)} → {money(adjustment.net_pay.corrected, currency)}</td>
              <td><strong>{signed(adjustment.net_pay.difference)}</strong></td>
            </tr>
          );
        })}
      </Table>
    </Panel>
  );
}
