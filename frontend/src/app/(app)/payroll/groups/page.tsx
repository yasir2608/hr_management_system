"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { FREQUENCY_LABELS, GroupForm, type CountryOption, type PayrollGroup } from "@/components/payroll/GroupForm";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, PageHead, Panel, Table, formatDate } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Country = CountryOption & { has_settings: boolean; unassigned_count: number; groups: PayrollGroup[] };

function PayrollGroupsPage() {
  const { can } = useAuth();
  const canManage = can("payroll.settings.manage");
  const [countries, setCountries] = useState<Country[] | null>(null);
  const [creating, setCreating] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setCountries((await api<{ countries: Country[] }>("/payroll/groups")).countries);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!countries) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payroll groups" subtitle="Employees paid together on one schedule. Each employee belongs to one group in the country they work in." />
      {notice && <Banner tone="success">{notice}</Banner>}
      {countries.length === 0 && <Panel><EmptyState title="No payroll countries yet">Choose the countries you employ people in under <Link className="link" href="/settings/company">Company setup</Link>.</EmptyState></Panel>}
      {countries.map((country) => (
        <Panel
          key={country.country_code}
          title={country.country_name}
          subtitle={country.has_settings ? undefined : "Payroll settings are not set up for this country yet, so groups without their own schedule have no pay dates."}
          right={canManage && <button className="btn soft" onClick={() => setCreating(country.country_code)}>New group</button>}
          bodyless={country.groups.length > 0}
        >
          {country.unassigned_count > 0 && (
            <div style={{ padding: "10px 16px 0" }}>
              <Banner tone="info">{country.unassigned_count} employee{country.unassigned_count === 1 ? " works" : "s work"} in {country.country_name} but {country.unassigned_count === 1 ? "is" : "are"} not in a payroll group, so they will not be paid. Open a group to add them.</Banner>
            </div>
          )}
          {country.groups.length === 0 ? <span className="muted">No groups yet.</span> : (
            <Table headers={["Group", "Schedule", "Current period", "Employees"]}>
              {country.groups.map((group) => (
                <tr key={group.id}>
                  <td><Link href={`/payroll/groups/${group.id}`}><strong>{group.name}</strong></Link>{group.description && <div className="tiny">{group.description}</div>}</td>
                  <td>
                    {group.schedule ? FREQUENCY_LABELS[group.schedule.pay_frequency] ?? group.schedule.pay_frequency : "—"}{" "}
                    <Badge tone={group.own_schedule ? "blue" : "gray"} label={group.own_schedule ? "Own schedule" : "Country settings"} />
                  </td>
                  <td>{group.current_period ? <>{formatDate(group.current_period.start)} – {formatDate(group.current_period.end)}<div className="tiny">Paid {formatDate(group.current_period.pay_date)}</div></> : "—"}</td>
                  <td>{group.member_count}</td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      ))}
      {creating && <GroupForm countries={countries} defaultCountry={creating} onClose={() => setCreating(null)} onSaved={() => { setCreating(null); setNotice("Payroll group created."); void load(); }} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <PayrollGroupsPage />
    </Guard>
  );
}
