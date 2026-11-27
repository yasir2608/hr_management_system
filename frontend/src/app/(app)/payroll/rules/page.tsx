"use client";

import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, PageHead, Panel, Table, formatDate } from "@/components/ui";
import { api } from "@/lib/api";

type Version = { country: string; version: string; from: string; to: string | null; status: "current" | "past" | "upcoming"; figures: { label: string; value: string }[] };
type Data = { countries: { code: string; name: string; versions: Version[] }[]; note: string };

const STATUS: Record<Version["status"], { label: string; tone: string }> = { current: { label: "In force", tone: "green" }, upcoming: { label: "Upcoming", tone: "blue" }, past: { label: "Past", tone: "gray" } };

function RulesPage() {
  const [data, setData] = useState<Data | null>(null);

  useEffect(() => {
    api<Data>("/payroll/rules").then(setData);
  }, []);

  if (!data) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead eyebrow="Payroll" title="Tax rules" subtitle="The country rule versions payroll uses, the dates each covers and their main figures." />
      <Banner tone="info">{data.note}</Banner>
      {data.countries.map((country) => (
        <Panel key={country.code} title={country.name} bodyless={country.versions.length > 0}>
          {country.versions.length === 0 ? <EmptyState title={`No ${country.name} rules yet`}>Runs for {country.name} cannot work out taxes until a rule version covers their pay period.</EmptyState> : (
            <Table headers={["Version", "Covers pay periods ending", "Main figures", "Status"]}>
              {country.versions.map((version) => (
                <tr key={version.version}>
                  <td><strong>{version.version}</strong></td>
                  <td>{formatDate(version.from)} – {version.to ? formatDate(version.to) : "until replaced"}</td>
                  <td>{version.figures.map((figure) => <div key={figure.label} className="tiny">{figure.label}: <strong>{figure.value}</strong></div>)}</td>
                  <td><Badge tone={STATUS[version.status].tone} label={STATUS[version.status].label} /></td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      ))}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["payroll.view", "payroll.settings.manage"]}>
      <RulesPage />
    </Guard>
  );
}
