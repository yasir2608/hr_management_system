"use client";

import Link from "next/link";
import { Fragment, useState } from "react";
import { Badge, Panel, Person, Table, money } from "@/components/ui";

export type ResultLine = { code: string; label: string; category: string; amount: string; explanation: string };
export type Results = {
  lines?: ResultLine[]; gross_pay?: string; taxable_pay?: string; employee_deductions?: string; net_pay?: string; employer_costs?: string; total_cost?: string;
  summary?: string[]; notes?: string[]; warnings: string[]; blocking: string[]; rules_version: string | null;
};
export type ResultRow = { id: string; employee_id: string; employee_name: string; employee_number: string | null; results: Results | null };

const CATEGORY: Record<string, string> = {
  earning: "Pay", pre_tax_deduction: "Before tax", tax: "Tax", employee_contribution: "Contribution", post_tax_deduction: "After tax", employer_contribution: "Employer pays",
};

/** Per-employee results of a calculated run, each opening into a plain-language breakdown. */
export function RunResults({ rows, currency, restricted }: { rows: ResultRow[]; currency: string; restricted: boolean }) {
  const [open, setOpen] = useState<string | null>(null);
  const amount = (value?: string) => (restricted ? <span className="badge gray">Restricted</span> : money(value ?? "0", currency));

  return (
    <Panel title="Calculated pay" subtitle="Open an employee to see how each number was reached." bodyless>
      <Table headers={["Employee", "Gross pay", "Employee deductions", "Net pay", "Employer costs", "Flags", ""]}>
        {rows.map((row) => {
          const results = row.results!;
          const isOpen = open === row.id;
          return (
            <Fragment key={row.id}>
              <tr>
                <td><Link href={`/payroll/employees/${row.employee_id}`} style={{ textDecoration: "none" }}><Person name={row.employee_name} detail={row.employee_number} /></Link></td>
                <td>{amount(results.gross_pay)}</td>
                <td>{amount(results.employee_deductions)}</td>
                <td><strong>{amount(results.net_pay)}</strong></td>
                <td>{amount(results.employer_costs)}</td>
                <td>
                  {results.blocking.length > 0 && <Badge tone="red" label={`${results.blocking.length} blocking`} />}
                  {results.warnings.length > 0 && <Badge tone="amber" label={`${results.warnings.length} to review`} />}
                  {results.blocking.length === 0 && results.warnings.length === 0 && <Badge status="ready" label="OK" />}
                </td>
                <td style={{ textAlign: "right" }}>{!restricted && <button className="btn" onClick={() => setOpen(isOpen ? null : row.id)}>{isOpen ? "Hide" : "Breakdown"}</button>}</td>
              </tr>
              {(isOpen || results.blocking.length > 0) && (
                <tr>
                  <td colSpan={7} style={{ background: "var(--soft, #f7f9fc)" }}>
                    {results.blocking.map((message) => <div key={message}><Badge tone="red" label="Blocks" /> {message}</div>)}
                    {results.warnings.map((message) => <div key={message}><Badge tone="amber" label="Review" /> {message}</div>)}
                    {isOpen && results.lines && (
                      <div className="stack" style={{ marginTop: 8 }}>
                        <Table headers={["Item", "Type", "Amount", "How it was worked out"]}>
                          {results.lines.map((line, index) => (
                            <tr key={index}>
                              <td>{line.label}</td>
                              <td className="tiny">{CATEGORY[line.category] ?? line.category}</td>
                              <td style={{ whiteSpace: "nowrap" }}>{money(line.amount, currency)}</td>
                              <td className="tiny">{line.explanation}</td>
                            </tr>
                          ))}
                        </Table>
                        {results.summary?.map((sentence) => <div key={sentence}>{sentence}</div>)}
                        {results.notes?.map((note) => <div key={note} className="tiny">{note}</div>)}
                        {results.rules_version && <div className="tiny">Rules version: {results.rules_version}</div>}
                      </div>
                    )}
                  </td>
                </tr>
              )}
            </Fragment>
          );
        })}
      </Table>
    </Panel>
  );
}
