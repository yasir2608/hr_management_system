"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Banner, EmptyState, Field, PageHead, Panel, Stat, Table, formatDate, money } from "@/components/ui";
import { api, ApiError, download } from "@/lib/api";

type Options = {
  countries: { country_code: string; country_name: string; currency: string }[];
  groups: { id: string; name: string; country_code: string }[];
  types: { key: string; name: string; description: string }[];
  filing_summaries: Record<string, string>;
  notice: string;
};
type Section = { key: string; title: string; description: string; columns: Column[]; rows: Row[]; totals: Record<string, string> };
type Filing = {
  country: string; name: string; description: string; currency: string; sections: Section[];
  employee_count: number; run_count: number; warnings: string[]; tax_ids_restricted: boolean;
  excluded: { employee_id: string; name: string; reason: string }[];
};

const FILING = "filing_summary";
/** First day of the tax year, as month-day: Pakistan 1 July, UK 6 April, US 1 January. */
const TAX_YEAR_START: Record<string, string> = { PK: "07-01", GB: "04-06", US: "01-01" };

function currentTaxYear(country: string) {
  const start = TAX_YEAR_START[country] ?? "01-01";
  const today = new Date().toISOString().slice(0, 10);
  const year = Number(today.slice(0, 4));
  const from = `${today.slice(5) >= start ? year : year - 1}-${start}`;
  const end = new Date(`${Number(from.slice(0, 4)) + 1}-${start}T00:00:00Z`);
  end.setUTCDate(end.getUTCDate() - 1);
  return { from, to: end.toISOString().slice(0, 10) };
}
type Column = { key: string; label: string; type: "text" | "money" | "number" | "date" };
type Row = Record<string, string | number | boolean | null>;
type Report = {
  type: string; name: string; description: string; currency: string; notice: string;
  columns: Column[]; rows: Row[]; totals: Record<string, string | number>;
  employee_count: number; run_count: number; warnings: string[];
  excluded: { employee_id: string; name: string; reason: string }[];
  breakdown?: { item: string; employees: number; amount: string }[];
};

const year = new Date().getFullYear();

function PayrollReportsPage() {
  const [options, setOptions] = useState<Options | null>(null);
  const [filters, setFilters] = useState({ type: "register", country: "", group_id: "", from: `${year}-01-01`, to: `${year}-12-31` });
  const [report, setReport] = useState<Report | null>(null);
  const [filing, setFiling] = useState<Filing | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    void api<Options>("/payroll/reports").then((result) => {
      setOptions(result);
      setFilters((current) => ({ ...current, country: result.countries[0]?.country_code ?? "" }));
    });
  }, []);

  const set = (patch: Partial<typeof filters>) => { setFilters({ ...filters, ...patch }); setReport(null); setFiling(null); };
  const query = () => ({ country: filters.country, from: filters.from, to: filters.to, ...(filters.group_id ? { group_id: filters.group_id } : {}) });
  const fieldError = (key: string) => error?.errors[key]?.[0];
  const path = filters.type === FILING ? "/payroll/reports/filing-summary" : `/payroll/reports/${filters.type}`;

  async function preview() {
    setError(null);
    setLoading(true);
    try {
      if (filters.type === FILING) {
        setFiling(await api<Filing>(path, { query: query() }));
      } else {
        setReport(await api<Report>(path, { query: query() }));
      }
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not build the report.", 0));
      setReport(null);
      setFiling(null);
    } finally {
      setLoading(false);
    }
  }

  async function downloadCsv() {
    setError(null);
    try {
      await download(`${path}/download`, query());
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not download the report.", 0));
    }
  }

  if (!options) return <div className="loadingpage">Loading…</div>;
  if (options.countries.length === 0) {
    return <><PageHead eyebrow="Payroll" title="Payroll reports" /><Panel><EmptyState title="No payroll countries yet">Choose the countries you employ people in under <Link className="link" href="/settings/company">Company setup</Link>.</EmptyState></Panel></>;
  }

  const groups = options.groups.filter((group) => group.country_code === filters.country);
  const filingName = options.filing_summaries[filters.country];
  const types = [
    ...options.types,
    ...(filingName ? [{ key: FILING, name: filingName, description: "Approved figures arranged by filing period to help you complete your own country filings. Not the official forms." }] : []),
  ];
  const chosen = types.find((type) => type.key === filters.type);
  const cell = (column: Column, value: Row[string] | undefined, currency: string) => {
    if (value === null || value === undefined || value === "") return column.type === "money" ? "" : "—";
    if (column.type === "money") return money(String(value), currency);
    if (column.type === "date") return formatDate(String(value));
    return String(value);
  };

  return (
    <>
      <PageHead eyebrow="Payroll" title="Payroll reports" subtitle="Costs, deductions and payments from approved payroll runs, by pay date." />
      <Banner tone="info">{options.notice}</Banner>
      {error && <Banner tone="error">{error.message}</Banner>}

      <Panel title="Choose a report">
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: 10, marginBottom: 16 }}>
          {types.map((type) => (
            <label key={type.key} className="checkrow" style={{ alignItems: "flex-start", border: `1px solid ${filters.type === type.key ? "var(--blue)" : "var(--line, #e3e8ef)"}`, borderRadius: 10, padding: 12, cursor: "pointer" }}>
              <input type="radio" name="report" checked={filters.type === type.key} onChange={() => set({ type: type.key })} style={{ marginTop: 3 }} />
              <span><strong>{type.name}</strong><span className="tiny" style={{ display: "block" }}>{type.description}</span></span>
            </label>
          ))}
        </div>
        <div className="formgrid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))" }}>
          <Field label="Country" required error={fieldError("country")}>
            <select value={filters.country} onChange={(event) => set({ country: event.target.value, group_id: "", ...(filters.type === FILING && !options.filing_summaries[event.target.value] ? { type: "register" } : {}) })}>
              {options.countries.map((country) => <option key={country.country_code} value={country.country_code}>{country.country_name} ({country.currency})</option>)}
            </select>
          </Field>
          <Field label="Payroll group" error={fieldError("group_id")}>
            <select value={filters.group_id} onChange={(event) => set({ group_id: event.target.value })}>
              <option value="">All groups</option>
              {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
            </select>
          </Field>
          <Field label="Pay dates from" required error={fieldError("from")}><input className="input" type="date" value={filters.from} onChange={(event) => set({ from: event.target.value })} /></Field>
          <Field label="Pay dates to" required error={fieldError("to")}><input className="input" type="date" value={filters.to} onChange={(event) => set({ to: event.target.value })} /></Field>
        </div>
        <div style={{ display: "flex", gap: 8, marginTop: 14 }}>
          <button className="btn primary" disabled={loading} onClick={() => void preview()}>{loading ? "Building…" : `Preview ${chosen?.name.toLowerCase() ?? "report"}`}</button>
          {(report || filing) && <button className="btn" onClick={() => void downloadCsv()}>Download CSV</button>}
          <button className="btn soft" onClick={() => set(currentTaxYear(filters.country))}>Use current tax year</button>
        </div>
      </Panel>

      {filing && (
        <>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 14, marginBottom: 16 }}>
            <Stat label="Employees included" value={filing.employee_count} />
            <Stat label="Payroll runs" value={filing.run_count} />
            <Stat label="Left out" value={filing.excluded.length} />
            <Stat label="Currency" value={filing.currency} />
          </div>
          <Banner tone="info">{filing.description}</Banner>
          {filing.tax_ids_restricted && <Banner tone="info">Tax identifiers are hidden because you do not have access to them.</Banner>}
          {filing.warnings.map((warning) => <Banner key={warning} tone="info">{warning}</Banner>)}
          {filing.sections.map((section) => (
            <Panel key={section.key} title={section.title} subtitle={section.description} bodyless={section.rows.length > 0}>
              {section.rows.length === 0 ? <span className="muted">No approved pay in this range.</span> : (
                <div style={{ overflowX: "auto" }}>
                  <Table headers={section.columns.map((column) => <span key={column.key} style={{ display: "block", textAlign: column.type === "money" || column.type === "number" ? "right" : "left" }}>{column.label}</span>)}>
                    {section.rows.map((row, index) => (
                      <tr key={index}>
                        {section.columns.map((column) => <td key={column.key} style={{ textAlign: column.type === "money" || column.type === "number" ? "right" : "left", whiteSpace: column.type === "money" ? "nowrap" : undefined }}>{cell(column, row[column.key], filing.currency)}</td>)}
                      </tr>
                    ))}
                    <tr style={{ fontWeight: 600, borderTop: "2px solid var(--line, #e3e8ef)" }}>
                      {section.columns.map((column, index) => (
                        <td key={column.key} style={{ textAlign: column.type === "money" ? "right" : "left", whiteSpace: "nowrap" }}>
                          {index === 0 ? "Total" : section.totals[column.key] !== undefined ? cell(column, section.totals[column.key], filing.currency) : ""}
                        </td>
                      ))}
                    </tr>
                  </Table>
                </div>
              )}
            </Panel>
          ))}
          {filing.excluded.length > 0 && (
            <Panel title="Not included" subtitle="Employees left out of a payroll run in this range." bodyless>
              <Table headers={["Employee", "Reason"]}>
                {filing.excluded.map((row, index) => <tr key={`${row.employee_id}-${index}`}><td><Link className="link" href={`/payroll/employees/${row.employee_id}`}>{row.name}</Link></td><td>{row.reason}</td></tr>)}
              </Table>
            </Panel>
          )}
        </>
      )}

      {report && (
        <>
          <div className="statgrid" style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 14, marginBottom: 16 }}>
            <Stat label="Employees included" value={report.employee_count} />
            <Stat label="Payroll runs" value={report.run_count} />
            <Stat label="Left out" value={report.excluded.length} />
            <Stat label="Currency" value={report.currency} />
          </div>
          {report.warnings.map((warning) => <Banner key={warning} tone="info">{warning}</Banner>)}
          <Panel title={report.name} subtitle={report.description} bodyless={report.rows.length > 0}>
            {report.rows.length === 0 ? <EmptyState title="No approved pay in this range">Check the dates and group, or approve the payroll runs for this period first.</EmptyState> : (
              <div style={{ overflowX: "auto" }}>
                <Table headers={report.columns.map((column) => <span key={column.key} style={{ display: "block", textAlign: column.type === "money" || column.type === "number" ? "right" : "left" }}>{column.label}</span>)}>
                  {report.rows.map((row, index) => (
                    <tr key={index} style={row.subtotal ? { fontWeight: 600, background: "var(--soft, #f6f8fb)" } : undefined}>
                      {report.columns.map((column) => <td key={column.key} style={{ textAlign: column.type === "money" || column.type === "number" ? "right" : "left", whiteSpace: column.type === "money" ? "nowrap" : undefined }}>{cell(column, row[column.key], report.currency)}</td>)}
                    </tr>
                  ))}
                  {report.type !== "gross_to_net" && (
                    <tr style={{ fontWeight: 600, borderTop: "2px solid var(--line, #e3e8ef)" }}>
                      {report.columns.map((column, index) => (
                        <td key={column.key} style={{ textAlign: column.type === "money" || column.type === "number" ? "right" : "left", whiteSpace: "nowrap" }}>
                          {index === 0 ? "Total" : report.totals[column.key] !== undefined ? cell(column, report.totals[column.key], report.currency) : ""}
                        </td>
                      ))}
                    </tr>
                  )}
                </Table>
              </div>
            )}
          </Panel>
          {report.type === "deductions" && report.rows.length > 0 && (
            <Panel title="Totals">
              <div className="tiny">Taken from employees: <strong>{money(String(report.totals.employee), report.currency)}</strong> · Paid by the company: <strong>{money(String(report.totals.employer), report.currency)}</strong> · Total: <strong>{money(String(report.totals.amount), report.currency)}</strong></div>
            </Panel>
          )}
          {report.breakdown && report.breakdown.length > 0 && (
            <Panel title="Employer contributions by item" bodyless>
              <Table headers={["Item", "Employees", "Amount"]}>
                {report.breakdown.map((item) => <tr key={item.item}><td>{item.item}</td><td>{item.employees}</td><td>{money(item.amount, report.currency)}</td></tr>)}
              </Table>
            </Panel>
          )}
          {report.excluded.length > 0 && (
            <Panel title="Not included" subtitle="Employees left out of a payroll run in this range." bodyless>
              <Table headers={["Employee", "Reason"]}>
                {report.excluded.map((row, index) => <tr key={`${row.employee_id}-${index}`}><td><Link className="link" href={`/payroll/employees/${row.employee_id}`}>{row.name}</Link></td><td>{row.reason}</td></tr>)}
              </Table>
            </Panel>
          )}
        </>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.reports.view"]}>
      <PayrollReportsPage />
    </Guard>
  );
}
