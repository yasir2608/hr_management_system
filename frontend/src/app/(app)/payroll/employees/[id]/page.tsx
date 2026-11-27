"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { PayrollDetailsModal, RecordChangeModal } from "@/components/employees/EditModals";
import { FREQUENCY_LABELS } from "@/components/payroll/GroupForm";
import { PayItemsPanel } from "@/components/payroll/PayItemsPanel";
import { Guard } from "@/components/Guard";
import { Badge, Banner, Field, PageHead, Panel, Sensitive, Table, formatDate, humanize, money } from "@/components/ui";
import { api } from "@/lib/api";
import type { Employee } from "@/lib/employees";

type Pay = { pay_basis: string; amount: string; currency: string; frequency: string; hours_per_week?: string | null; valid_from: string };
type Profile = {
  employee: Employee;
  work_country: string | null;
  country_name: string | null;
  is_payroll_country: boolean;
  group: { id: string; name: string } | null;
  schedule: { pay_frequency: string; pay_date_rule: string; pay_day: number | null; currency: string } | null;
  pay: { current: Pay | null; history: (Pay & { valid_to: string | null; reason: string | null })[] } | null;
  tax_fields: { key: string; label: string; required: boolean; hint: string | null; value: string | null; is_set: boolean }[];
  bank: { id: string; account_holder: string; bank_name: string | null; account_number: string; is_primary: boolean }[] | null;
  missing: { key: string; label: string }[];
  restricted_sections: string[];
  can_edit_payroll: boolean;
  currency_mismatch: string | null;
};

const PER: Record<string, string> = { annual: "a year", monthly: "a month", hourly: "an hour" };

function PayrollProfilePage() {
  const { id } = useParams<{ id: string }>();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [modal, setModal] = useState<"pay" | "details" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setProfile(await api<Profile>(`/payroll/employees/${id}`));
  }, [id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!profile) return <div className="loadingpage">Loading…</div>;
  const { employee, pay } = profile;
  const done = (message: string) => { setModal(null); setNotice(message); void load(); };

  return (
    <>
      <PageHead
        eyebrow="Payroll profile"
        title={employee.full_name}
        subtitle={[employee.employee_number, employee.current_job_title, profile.country_name].filter(Boolean).join(" · ")}
        actions={<>
          <Link className="btn" href="/payroll/employees">All employees</Link>
          <Link className="btn" href={`/employees/${employee.id}`}>HR record</Link>
          {profile.can_edit_payroll && <button className="btn" onClick={() => setModal("details")}>Edit tax and bank</button>}
          {profile.can_edit_payroll && <button className="btn primary" onClick={() => setModal("pay")}>Change pay</button>}
        </>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {profile.missing.length > 0
        ? <Banner tone="info">Not ready for payroll yet. Missing: {profile.missing.map((item) => item.label).join(", ")}.</Banner>
        : <Banner tone="success">Ready for payroll.</Banner>}
      {profile.currency_mismatch && <Banner tone="error">{profile.currency_mismatch}</Banner>}
      {!profile.is_payroll_country && profile.work_country && <Banner tone="error">{profile.country_name} is not one of the company’s payroll countries.</Banner>}
      <div className="grid two">
        <Panel title="Pay">
          <Sensitive field="pay" restricted={profile.restricted_sections}>
            {pay?.current ? (
              <div className="formgrid">
                <Field label="Pay basis"><div>{humanize(pay.current.pay_basis)}</div></Field>
                <Field label="Amount"><div><strong>{money(pay.current.amount, pay.current.currency)}</strong> <span className="tiny">{PER[pay.current.frequency] ?? pay.current.frequency}</span></div></Field>
                <Field label="Currency"><div>{pay.current.currency}</div></Field>
                <Field label="Since"><div>{formatDate(pay.current.valid_from)}</div></Field>
                {pay.current.hours_per_week && <Field label="Hours per week"><div>{pay.current.hours_per_week}</div></Field>}
              </div>
            ) : <span className="muted">No pay recorded.</span>}
          </Sensitive>
        </Panel>
        <Panel title="Payroll group">
          {profile.group ? (
            <div className="formgrid">
              <Field label="Group"><div><Link href={`/payroll/groups/${profile.group.id}`}>{profile.group.name}</Link></div></Field>
              <Field label="Paid"><div>{profile.schedule ? FREQUENCY_LABELS[profile.schedule.pay_frequency] ?? profile.schedule.pay_frequency : "No schedule yet"}</div></Field>
              <Field label="Payroll currency"><div>{profile.schedule?.currency ?? "—"}</div></Field>
            </div>
          ) : <span className="muted">Not in a payroll group, so not paid. Add them from <Link className="link" href="/payroll/groups">Payroll groups</Link>.</span>}
        </Panel>
      </div>
      <div className="grid two">
        <Panel title={`${profile.country_name ?? "Country"} tax details`} subtitle={profile.restricted_sections.includes("tax_details") ? "Values are hidden; you can see which are filled in." : undefined}>
          {profile.tax_fields.length === 0 ? <span className="muted">Set the country of employment on the HR record first.</span> : (
            <div className="formgrid">
              {profile.tax_fields.map((field) => (
                <Field key={field.key} label={field.label} required={field.required} hint={field.hint ?? undefined}>
                  <div>{field.value ?? (field.is_set ? <Badge tone="gray" label="Set" /> : field.required ? <Badge status="needs_attention" label="Missing" /> : <span className="muted">Not set</span>)}</div>
                </Field>
              ))}
            </div>
          )}
        </Panel>
        <Panel title="Bank account">
          <Sensitive field="bank" restricted={profile.restricted_sections}>
            {profile.bank && profile.bank.length > 0 ? profile.bank.map((account) => (
              <div key={account.id} className="formgrid">
                <Field label="Account holder"><div>{account.account_holder}</div></Field>
                <Field label="Bank"><div>{account.bank_name ?? "—"}</div></Field>
                <Field label="Account number"><div>{account.account_number} {account.is_primary && <Badge tone="blue" label="Primary" />}</div></Field>
              </div>
            )) : <span className="muted">No bank account recorded.</span>}
          </Sensitive>
        </Panel>
      </div>
      <PayItemsPanel employeeId={employee.id} currency={profile.schedule?.currency ?? pay?.current?.currency ?? "GBP"} />
      {pay && pay.history.length > 0 && (
        <Panel title="Pay history" subtitle="Effective-dated. Past payroll runs keep the values used at the time." bodyless>
          <Table headers={["From", "To", "Amount", "Basis", "Reason"]}>
            {pay.history.map((row, index) => (
              <tr key={index}>
                <td>{formatDate(row.valid_from)}</td>
                <td>{row.valid_to ? formatDate(row.valid_to) : "Current"}</td>
                <td>{money(row.amount, row.currency)} <span className="tiny">{PER[row.frequency] ?? row.frequency}</span></td>
                <td>{humanize(row.pay_basis)}</td>
                <td>{row.reason ?? "—"}</td>
              </tr>
            ))}
          </Table>
        </Panel>
      )}
      {modal === "pay" && <RecordChangeModal employee={employee} canChangePay initialType="compensation" onClose={() => setModal(null)} onDone={done} />}
      {modal === "details" && <PayrollDetailsModal employee={employee} onClose={() => setModal(null)} onDone={done} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <PayrollProfilePage />
    </Guard>
  );
}
