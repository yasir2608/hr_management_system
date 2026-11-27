"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Banner, EmptyState, Field, PageHead, Panel, Stat, Table, formatDate, formatDateTime } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type ReportType = { key: string; name: string; description: string; uses: ("as_of" | "range")[] };
type Options = {
  types: ReportType[];
  departments: { id: string; name: string }[];
  locations: { id: string; name: string }[];
  countries: { code: string; name: string }[];
};
type Column = { key: string; label: string; type: "text" | "number" | "date" };
type Row = Record<string, string | number | null>;
type Section = { key: string; title: string; columns: Column[]; rows: Row[]; totals: Record<string, number> | null };
type Report = {
  type: string; name: string; description: string; uses: string[]; generated_at: string; notes: string[];
  stats: { label: string; value: string | number; note?: string }[];
  sections: Section[];
  filters: { department: string | null; location: string | null; country: string | null; as_of: string | null; from: string | null; to: string | null };
};

const today = new Date().toISOString().slice(0, 10);
const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

function HrReportsPage() {
  const { can } = useAuth();
  const [options, setOptions] = useState<Options | null>(null);
  const [filters, setFilters] = useState({ type: "headcount", department_id: "", location_id: "", country: "", as_of: today, from: monthAgo, to: today });
  const [report, setReport] = useState<Report | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    void api<Options>("/reports/hr").then(setOptions);
  }, []);

  const chosen = options?.types.find((type) => type.key === filters.type);

  const query = useCallback(() => ({
    ...(filters.department_id ? { department_id: filters.department_id } : {}),
    ...(filters.location_id ? { location_id: filters.location_id } : {}),
    ...(filters.country ? { country: filters.country } : {}),
    ...(chosen?.uses.includes("as_of") ? { as_of: filters.as_of } : {}),
    ...(chosen?.uses.includes("range") ? { from: filters.from, to: filters.to } : {}),
  }), [chosen, filters]);

  async function exportCsv() {
    if (!chosen) return;
    setError(null);
    try {
      await download(`/reports/hr/${chosen.key}/export`, query());
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not export the report.", 0));
    }
  }

  const load = useCallback(async () => {
    if (!chosen) return;
    setError(null);
    try {
      setReport(await api<Report>(`/reports/hr/${chosen.key}`, { query: query() }));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not build the report.", 0));
      setReport(null);
    }
  }, [chosen, query]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- rebuild when the report or filters change
    void load();
  }, [load]);

  const set = (patch: Partial<typeof filters>) => setFilters({ ...filters, ...patch });
  const fieldError = (key: string) => error?.errors[key]?.[0];

  if (!options) return <div className="loadingpage">Loading…</div>;

  const cell = (column: Column, value: Row[string] | undefined) => {
    if (value === null || value === undefined || value === "") return "—";
    if (column.type === "date") return formatDate(String(value));
    return String(value);
  };
  const align = (column: Column): "right" | "left" => (column.type === "number" ? "right" : "left");
  const appliedFilters = report ? [
    report.filters.as_of && `On ${formatDate(report.filters.as_of)}`,
    report.filters.from && report.filters.to && `${formatDate(report.filters.from)} – ${formatDate(report.filters.to)}`,
    report.filters.department && `Department: ${report.filters.department}`,
    report.filters.location && `Location: ${report.filters.location}`,
    report.filters.country && `Country: ${report.filters.country}`,
  ].filter(Boolean) : [];

  return (
    <>
      <PageHead
        eyebrow="Workspace"
        title="Reports"
        subtitle="Headcount, status, departments and locations, onboarding, leave and attendance for the people you can see."
        actions={can("payroll.reports.view") && <Link className="btn" href="/payroll/reports">Payroll reports</Link>}
      />
      {error && <Banner tone="error">{error.message}</Banner>}

      <Panel title="Choose a report">
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(210px, 1fr))", gap: 10, marginBottom: 16 }}>
          {options.types.map((type) => (
            <label key={type.key} className="checkrow" style={{ alignItems: "flex-start", border: `1px solid ${filters.type === type.key ? "var(--blue)" : "var(--line, #e3e8ef)"}`, borderRadius: 10, padding: 12, cursor: "pointer" }}>
              <input type="radio" name="report" checked={filters.type === type.key} onChange={() => set({ type: type.key })} style={{ marginTop: 3 }} />
              <span><strong>{type.name}</strong><span className="tiny" style={{ display: "block" }}>{type.description}</span></span>
            </label>
          ))}
        </div>
        <div className="formgrid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))" }}>
          <Field label="Department" error={fieldError("department_id")}>
            <select value={filters.department_id} onChange={(event) => set({ department_id: event.target.value })}>
              <option value="">All departments</option>
              {options.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
            </select>
          </Field>
          <Field label="Location" error={fieldError("location_id")}>
            <select value={filters.location_id} onChange={(event) => set({ location_id: event.target.value })}>
              <option value="">All locations</option>
              {options.locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
            </select>
          </Field>
          <Field label="Work country" error={fieldError("country")}>
            <select value={filters.country} onChange={(event) => set({ country: event.target.value })}>
              <option value="">All countries</option>
              {options.countries.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
            </select>
          </Field>
          {chosen?.uses.includes("as_of") && <Field label="Headcount on" error={fieldError("as_of")}><input className="input" type="date" value={filters.as_of} onChange={(event) => set({ as_of: event.target.value })} /></Field>}
          {chosen?.uses.includes("range") && <>
            <Field label="From" error={fieldError("from")}><input className="input" type="date" value={filters.from} onChange={(event) => set({ from: event.target.value })} /></Field>
            <Field label="To" error={fieldError("to")}><input className="input" type="date" value={filters.to} onChange={(event) => set({ to: event.target.value })} /></Field>
          </>}
        </div>
      </Panel>

      {report && (
        <>
          <div className="tiny" style={{ margin: "0 0 12px" }}>
            <strong>{report.name}</strong> · generated {formatDateTime(report.generated_at)}{appliedFilters.length > 0 && <> · {appliedFilters.join(" · ")}</>}
            {" "}<button className="btn soft" style={{ marginLeft: 8 }} onClick={() => void exportCsv()}>Export CSV</button>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(170px, 1fr))", gap: 14, marginBottom: 16 }}>
            {report.stats.map((stat) => <Stat key={stat.label} label={stat.label} value={stat.value} note={stat.note} />)}
          </div>
          {report.notes.map((note) => <Banner key={note} tone="info">{note}</Banner>)}
          {report.sections.map((section) => (
            <Panel key={section.key} title={section.title} bodyless={section.rows.length > 0}>
              {section.rows.length === 0 ? <EmptyState title="Nothing to show">No people match this report and its filters.</EmptyState> : (
                <div style={{ overflowX: "auto" }}>
                  <Table headers={section.columns.map((column) => <span key={column.key} style={{ display: "block", textAlign: align(column) }}>{column.label}</span>)}>
                    {section.rows.map((row, index) => (
                      <tr key={`${row.employee_id ?? ""}-${index}`}>
                        {section.columns.map((column) => (
                          <td key={column.key} style={{ textAlign: align(column) }}>
                            {column.key === "employee" && row.employee_id ? <Link href={`/employees/${row.employee_id}`}>{cell(column, row[column.key])}</Link> : cell(column, row[column.key])}
                          </td>
                        ))}
                      </tr>
                    ))}
                    {section.totals && (
                      <tr style={{ fontWeight: 600, borderTop: "2px solid var(--line, #e3e8ef)" }}>
                        {section.columns.map((column, index) => <td key={column.key} style={{ textAlign: align(column) }}>{index === 0 ? "Total" : section.totals?.[column.key] ?? ""}</td>)}
                      </tr>
                    )}
                  </Table>
                </div>
              )}
            </Panel>
          ))}
        </>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["reports.hr.view"]}>
      <HrReportsPage />
    </Guard>
  );
}
