"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, formatDate, PageHead, Panel, Person, Stat, Table } from "@/components/ui";
import { api, download } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { COUNTRIES, countryName } from "@/lib/countries";
import { statusLabel, type Employee } from "@/lib/employees";

type Option = { id: string; name: string };
type Directory = { data: Employee[]; meta: { current_page: number; last_page: number; total: number }; status_counts: Record<string, number> };

function EmployeesPage() {
  const { can } = useAuth();
  const [flash, setFlash] = useState<string | null>(null);
  const [directory, setDirectory] = useState<Directory | null>(null);
  const [filters, setFilters] = useState({ search: "", status: "", department_id: "", team_id: "", location_id: "", work_country: "" });
  const [page, setPage] = useState(1);
  const [departments, setDepartments] = useState<Option[]>([]);
  const [teams, setTeams] = useState<Option[]>([]);
  const [locations, setLocations] = useState<Option[]>([]);

  useEffect(() => {
    try {
      const message = window.sessionStorage.getItem("myhrms.flash");
      if (message) {
        window.sessionStorage.removeItem("myhrms.flash");
        // eslint-disable-next-line react-hooks/set-state-in-effect -- read once from browser storage
        setFlash(message);
      }
    } catch {
      /* storage unavailable */
    }
    api<{ data: Option[] }>("/departments").then((result) => setDepartments(result.data)).catch(() => undefined);
    api<{ data: Option[] }>("/teams").then((result) => setTeams(result.data)).catch(() => undefined);
    api<{ data: Option[] }>("/locations").then((result) => setLocations(result.data)).catch(() => undefined);
  }, []);

  const load = useCallback(async () => {
    setDirectory(await api<Directory>("/employees", { query: { ...filters, page } }));
  }, [filters, page]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when filters change
    void load();
  }, [load]);

  const setFilter = (key: keyof typeof filters) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setPage(1);
    setFilters({ ...filters, [key]: event.target.value });
  };
  const counts = directory?.status_counts ?? {};

  return (
    <>
      <PageHead
        eyebrow="People"
        title="Employees"
        subtitle="Keep employee details, employment history and payroll readiness in one place."
        actions={
          <>
            {can("employees.view") && <button className="btn" title="Downloads the people shown, with these filters" onClick={() => void download("/employees/export", filters)}>Export CSV</button>}
            {can("employees.import") && <Link className="btn" href="/employees/import">Import</Link>}
            {can("employees.manage") && <Link className="btn primary" href="/employees/new">Add employee</Link>}
          </>
        }
      />
      {flash && <Banner tone="success">{flash}</Banner>}
      <div className="grid stats">
        <Stat label="Active" value={counts.active ?? 0} />
        <Stat label="Onboarding" value={counts.onboarding ?? 0} />
        <Stat label="On leave" value={counts.on_leave ?? 0} />
        <Stat label="Ended" value={counts.ended ?? 0} note="Records are kept" />
      </div>
      <Panel
        title="Directory"
        subtitle={directory ? `${directory.meta.total} people` : undefined}
        right={
          <div className="row" style={{ flexWrap: "wrap", justifyContent: "flex-end" }}>
            <input className="input" style={{ width: 190 }} placeholder="Search name, number, title" value={filters.search} onChange={setFilter("search")} />
            <select style={{ width: 130 }} value={filters.status} onChange={setFilter("status")} aria-label="Status">
              <option value="">Current people</option>
              {["active", "onboarding", "on_leave", "invited", "ended"].map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
            </select>
            {departments.length > 0 && (
              <select style={{ width: 140 }} value={filters.department_id} onChange={setFilter("department_id")} aria-label="Department">
                <option value="">All departments</option>
                {departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            )}
            {teams.length > 0 && (
              <select style={{ width: 120 }} value={filters.team_id} onChange={setFilter("team_id")} aria-label="Team">
                <option value="">All teams</option>
                {teams.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            )}
            {locations.length > 0 && (
              <select style={{ width: 130 }} value={filters.location_id} onChange={setFilter("location_id")} aria-label="Location">
                <option value="">All locations</option>
                {locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            )}
            <select style={{ width: 130 }} value={filters.work_country} onChange={setFilter("work_country")} aria-label="Country">
              <option value="">All countries</option>
              {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
            </select>
          </div>
        }
        bodyless
      >
        {directory && directory.data.length === 0 ? (
          <EmptyState icon="◉" title="No employees found" action={can("employees.manage") && <Link className="btn primary" href="/employees/new">Add employee</Link>}>
            Try clearing the filters, or add your first employee.
          </EmptyState>
        ) : (
          <Table headers={["Employee", "Number", "Department / team", "Country", "Start date", "Status"]}>
            {directory?.data.map((employee) => (
              <tr key={employee.id}>
                <td><Link href={`/employees/${employee.id}`} style={{ textDecoration: "none" }}><Person name={employee.full_name} detail={employee.current_job_title} /></Link></td>
                <td>{employee.employee_number}</td>
                <td>{[employee.department?.name, employee.team?.name].filter(Boolean).join(" · ") || "—"}</td>
                <td>{countryName(employee.work_country)}</td>
                <td>{formatDate(employee.start_date)}</td>
                <td><Badge status={employee.current_status} label={statusLabel(employee.current_status)} /></td>
              </tr>
            ))}
          </Table>
        )}
        {directory && directory.meta.last_page > 1 && (
          <div className="row" style={{ padding: "12px 16px" }}>
            <span className="tiny">Page {directory.meta.current_page} of {directory.meta.last_page}</span>
            <div className="row">
              <button className="btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
              <button className="btn" disabled={page >= directory.meta.last_page} onClick={() => setPage(page + 1)}>Next</button>
            </div>
          </div>
        )}
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["employees.view", "employees.manage"]}>
      <EmployeesPage />
    </Guard>
  );
}
