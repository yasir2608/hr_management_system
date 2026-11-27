"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { FREQUENCY_LABELS, GroupForm, type CountryOption, type PayrollGroup } from "@/components/payroll/GroupForm";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Modal, PageHead, Panel, Person, Table, formatDate } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Person = { id: string; name: string; employee_number: string | null; current_job_title: string | null; current_status: string; current_group?: string | null };
type Detail = { group: PayrollGroup; members: Person[]; candidates: Person[] };

function GroupPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const canManage = can("payroll.settings.manage");
  const [detail, setDetail] = useState<Detail | null>(null);
  const [countries, setCountries] = useState<CountryOption[]>([]);
  const [editing, setEditing] = useState(false);
  const [adding, setAdding] = useState(false);
  const [picked, setPicked] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setDetail(await api<Detail>(`/payroll/groups/${id}`));
  }, [id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
    api<{ countries: CountryOption[] }>("/payroll/groups").then((result) => setCountries(result.countries));
  }, [load]);

  async function run(action: () => Promise<{ message: string }>) {
    setError(null);
    try {
      setNotice((await action()).message);
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Something went wrong.");
    }
  }

  if (!detail) return <div className="loadingpage">Loading…</div>;
  const { group } = detail;

  return (
    <>
      <PageHead
        eyebrow={`Payroll group · ${countries.find((country) => country.country_code === group.country_code)?.country_name ?? group.country_code}`}
        title={group.name}
        subtitle={group.description ?? undefined}
        actions={<>
          <Link className="btn" href="/payroll/groups">All groups</Link>
          {canManage && <button className="btn" onClick={() => setEditing(true)}>Edit</button>}
          {canManage && group.member_count === 0 && <button className="btn danger" onClick={() => void run(async () => { const result = await api<{ message: string }>(`/payroll/groups/${id}`, { method: "DELETE" }); router.push("/payroll/groups"); return result; })}>Delete</button>}
        </>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      <Panel title="Schedule">
        {group.schedule ? (
          <div className="row" style={{ gap: 24, flexWrap: "wrap" }}>
            <div><div className="tiny">How often</div><strong>{FREQUENCY_LABELS[group.schedule.pay_frequency] ?? group.schedule.pay_frequency}</strong> <Badge tone={group.own_schedule ? "blue" : "gray"} label={group.own_schedule ? "Own schedule" : "Country settings"} /></div>
            {group.current_period && <>
              <div><div className="tiny">Current period</div><strong>{formatDate(group.current_period.start)} – {formatDate(group.current_period.end)}</strong></div>
              <div><div className="tiny">Inputs close</div><strong>{formatDate(group.current_period.input_cutoff)}</strong></div>
              <div><div className="tiny">Pay date</div><strong>{formatDate(group.current_period.pay_date)}</strong></div>
            </>}
          </div>
        ) : <span className="muted">No schedule yet. Set up payroll settings for this country, or give the group its own schedule.</span>}
      </Panel>
      <Panel title={`Employees (${detail.members.length})`} right={canManage && <button className="btn soft" onClick={() => { setPicked([]); setAdding(true); }}>Add employees</button>} bodyless={detail.members.length > 0}>
        {detail.members.length === 0 ? <EmptyState title="No employees in this group yet" /> : (
          <Table headers={["Employee", "Job title", "Status", ""]}>
            {detail.members.map((member) => (
              <tr key={member.id}>
                <td><Link href={`/employees/${member.id}`} style={{ textDecoration: "none" }}><Person name={member.name} detail={member.employee_number} /></Link></td>
                <td>{member.current_job_title ?? "—"}</td>
                <td><Badge status={member.current_status} /></td>
                <td style={{ textAlign: "right" }}>{canManage && <button className="btn" onClick={() => void run(() => api(`/payroll/groups/${id}/members/${member.id}`, { method: "DELETE" }))}>Remove</button>}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {adding && (
        <Modal
          title={`Add employees to ${group.name}`}
          onClose={() => setAdding(false)}
          footer={<><button className="btn" onClick={() => setAdding(false)}>Cancel</button><button className="btn primary" disabled={picked.length === 0} onClick={() => void run(async () => { const result = await api<{ message: string }>(`/payroll/groups/${id}/members`, { body: { employee_ids: picked } }); setAdding(false); return result; })}>Add {picked.length || ""}</button></>}
        >
          {detail.candidates.length === 0 ? <span className="muted">Everyone who works in this country is already in this group.</span> : (
            <div className="stack">
              <div className="tiny">Only employees who work in this country are listed. Someone already in another group is moved.</div>
              {detail.candidates.map((candidate) => (
                <label key={candidate.id} className="checkrow">
                  <input type="checkbox" checked={picked.includes(candidate.id)} onChange={(event) => setPicked(event.target.checked ? [...picked, candidate.id] : picked.filter((value) => value !== candidate.id))} />
                  {candidate.name}{candidate.current_job_title && <span className="tiny"> · {candidate.current_job_title}</span>}
                  {candidate.current_group ? <Badge tone="amber" label={`In ${candidate.current_group}`} /> : <Badge tone="gray" label="No group" />}
                </label>
              ))}
            </div>
          )}
        </Modal>
      )}
      {editing && <GroupForm group={group} countries={countries} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); setNotice("Group saved."); void load(); }} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <GroupPage />
    </Guard>
  );
}
