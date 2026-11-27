"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, PageHead, Panel, Person, Stat, Table, formatDate } from "@/components/ui";
import { api } from "@/lib/api";

type Issue = { key: string; message: string; severity: "blocking" | "warning" };
type Row = { id: string; name: string; employee_number: string | null; current_status: string; state: "ready" | "needs_review" | "missing_information"; issues: Issue[] };
type Readiness = {
  groups: { id: string; name: string; country_code: string; country_name: string }[];
  unassigned: { id: string; name: string; work_country: string }[];
  group: null | {
    id: string; name: string; country_name: string;
    period: { start: string; end: string; pay_date: string; input_cutoff: string } | null;
    issues: Issue[]; can_run: boolean;
    counts: Record<Row["state"], number>;
    employees: Row[];
  };
};

const STATE: Record<Row["state"], { label: string; status: string }> = {
  ready: { label: "Ready", status: "ready" },
  needs_review: { label: "Needs review", status: "needs_attention" },
  missing_information: { label: "Missing information", status: "blocked" },
};

function ReadinessPage() {
  const [groupId, setGroupId] = useState("");
  const [state, setState] = useState("");
  const [data, setData] = useState<Readiness | null>(null);

  useEffect(() => {
    api<Readiness>("/payroll/readiness", { query: { group_id: groupId } }).then(setData);
  }, [groupId]);

  if (!data) return <div className="loadingpage">Loading…</div>;
  const group = data.group;
  const rows = group?.employees.filter((row) => !state || row.state === state) ?? [];

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payroll readiness" subtitle="Before a run: who can be paid this period, what blocks the rest, and what to review." />
      {data.unassigned.length > 0 && (
        <Banner tone="error">
          {data.unassigned.length} employee{data.unassigned.length === 1 ? " is" : "s are"} not in any payroll group and will not be paid: {data.unassigned.map((person) => person.name).join(", ")}.{" "}
          <Link className="link" href="/payroll/groups">Add them to a group</Link>
        </Banner>
      )}
      {data.groups.length === 0 ? <Panel><EmptyState title="No payroll groups yet">Create one under <Link className="link" href="/payroll/groups">Payroll groups</Link>.</EmptyState></Panel> : (
        <>
          <div className="tabs" role="tablist">
            {data.groups.map((item) => (
              <button key={item.id} role="tab" aria-selected={item.id === group?.id} className={`tab${item.id === group?.id ? " on" : ""}`} onClick={() => { setGroupId(item.id); setState(""); }}>
                {item.name} <span className="tiny">{item.country_name}</span>
              </button>
            ))}
          </div>
          {group && (
            <>
              {group.issues.length > 0 && (
                <Banner tone="error">
                  This group cannot run yet:
                  <ul style={{ margin: "6px 0 0 18px" }}>{group.issues.map((issue) => <li key={issue.key}>{issue.message}</li>)}</ul>
                  <Link className="link" href="/payroll/settings">Open payroll settings</Link>
                </Banner>
              )}
              <div className="grid stats">
                <Stat label="Pay period" value={group.period ? `${formatDate(group.period.start)} – ${formatDate(group.period.end)}` : "—"} note={group.period ? `Inputs close ${formatDate(group.period.input_cutoff)} · paid ${formatDate(group.period.pay_date)}` : "No schedule"} />
                <Stat label="Ready" value={group.counts.ready} good={group.counts.ready > 0} />
                <Stat label="Needs review" value={group.counts.needs_review} note="Can be calculated" />
                <Stat label="Missing information" value={group.counts.missing_information} note="Left out until fixed" />
              </div>
              <Panel
                title="Employees"
                right={
                  <select style={{ width: 180 }} value={state} onChange={(event) => setState(event.target.value)} aria-label="State">
                    <option value="">Everyone</option>
                    <option value="missing_information">Missing information</option>
                    <option value="needs_review">Needs review</option>
                    <option value="ready">Ready</option>
                  </select>
                }
                bodyless
              >
                {rows.length === 0 ? <EmptyState title={group.employees.length === 0 ? "No employees in this group" : "Nobody in this state"} /> : (
                  <Table headers={["Employee", "State", "What to do"]}>
                    {rows.map((row) => (
                      <tr key={row.id}>
                        <td><Link href={`/payroll/employees/${row.id}`} style={{ textDecoration: "none" }}><Person name={row.name} detail={row.employee_number} /></Link></td>
                        <td><Badge status={STATE[row.state].status} label={STATE[row.state].label} /></td>
                        <td>
                          {row.issues.length === 0 ? <span className="muted">Nothing</span> : row.issues.map((issue) => (
                            <div key={issue.key} style={{ marginBottom: 3 }}>
                              <Badge tone={issue.severity === "blocking" ? "red" : "amber"} label={issue.severity === "blocking" ? "Blocks" : "Review"} /> {issue.message}
                            </div>
                          ))}
                        </td>
                      </tr>
                    ))}
                  </Table>
                )}
              </Panel>
            </>
          )}
        </>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage", "payroll.run"]}>
      <ReadinessPage />
    </Guard>
  );
}
