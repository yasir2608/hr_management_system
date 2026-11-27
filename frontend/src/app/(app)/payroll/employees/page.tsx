"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, EmptyState, PageHead, Panel, Person, Sensitive, Table, money } from "@/components/ui";
import { api } from "@/lib/api";

type Row = {
  id: string; name: string; employee_number: string | null; current_job_title: string | null; current_status: string; work_country: string;
  group: { id: string; name: string } | null;
  pay: { pay_basis: string; amount: string; currency: string; frequency: string } | null;
  missing: { key: string; label: string }[];
};
type Listing = { data: Row[]; pay_restricted: boolean; groups: { id: string; name: string; country_code: string }[]; countries: { code: string; name: string }[] };

const PER: Record<string, string> = { annual: "a year", monthly: "a month", hourly: "an hour" };

function PayrollEmployeesPage() {
  const [filters, setFilters] = useState({ country: "", group_id: "", readiness: "", search: "" });
  const [listing, setListing] = useState<Listing | null>(null);

  useEffect(() => {
    api<Listing>("/payroll/employees", { query: filters }).then(setListing);
  }, [filters]);

  const set = (patch: Partial<typeof filters>) => setFilters({ ...filters, ...patch });
  const missingCount = listing?.data.filter((row) => row.missing.length > 0).length ?? 0;

  return (
    <>
      <PageHead eyebrow="Payroll" title="Employee pay" subtitle="Pay, payroll group and missing details for everyone paid through payroll." />
      <Panel
        title={listing ? `${listing.data.length} employee${listing.data.length === 1 ? "" : "s"}${missingCount ? ` · ${missingCount} not ready` : ""}` : "Employees"}
        right={
          <div className="row" style={{ flexWrap: "wrap" }}>
            <input className="input" style={{ width: 180 }} placeholder="Search name or number" value={filters.search} onChange={(event) => set({ search: event.target.value })} />
            <select style={{ width: 150 }} value={filters.country} onChange={(event) => set({ country: event.target.value, group_id: "" })} aria-label="Country">
              <option value="">All countries</option>
              {listing?.countries.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
            </select>
            <select style={{ width: 160 }} value={filters.group_id} onChange={(event) => set({ group_id: event.target.value })} aria-label="Payroll group">
              <option value="">All groups</option>
              <option value="none">Not in a group</option>
              {listing?.groups.filter((group) => !filters.country || group.country_code === filters.country).map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
            </select>
            <select style={{ width: 140 }} value={filters.readiness} onChange={(event) => set({ readiness: event.target.value })} aria-label="Readiness">
              <option value="">Everyone</option>
              <option value="ready">Ready</option>
              <option value="missing">Missing details</option>
            </select>
          </div>
        }
        bodyless
      >
        {listing && listing.data.length === 0 ? <EmptyState title="No employees match" /> : (
          <Table headers={["Employee", "Group", "Pay", "Payroll details"]}>
            {listing?.data.map((row) => (
              <tr key={row.id}>
                <td><Link href={`/payroll/employees/${row.id}`} style={{ textDecoration: "none" }}><Person name={row.name} detail={[row.employee_number, row.current_job_title].filter(Boolean).join(" · ")} /></Link></td>
                <td>{row.group?.name ?? <span className="muted">Not in a group</span>}</td>
                <td>{listing.pay_restricted ? <Sensitive field="pay" restricted={["pay"]}>{null}</Sensitive> : row.pay ? <>{money(row.pay.amount, row.pay.currency)} <span className="tiny">{PER[row.pay.frequency] ?? row.pay.frequency}</span></> : <span className="muted">Not set</span>}</td>
                <td>{row.missing.length === 0 ? <Badge status="ready" label="Ready" /> : <><Badge status="needs_attention" label={`${row.missing.length} missing`} /><div className="tiny">{row.missing.map((item) => item.label).join(", ")}</div></>}</td>
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
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <PayrollEmployeesPage />
    </Guard>
  );
}
