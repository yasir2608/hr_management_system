"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge, Panel, Table, formatDate } from "@/components/ui";
import { api } from "@/lib/api";
import type { Contract } from "./ContractsPanel";

type Alert = { kind: "ends" | "renewal"; date: string; days_left: number; contract: Contract; contractor: { id: string; name: string } };

function when(daysLeft: number) {
  if (daysLeft < 0) return { tone: "red", label: `${-daysLeft} day${daysLeft === -1 ? "" : "s"} overdue` };
  if (daysLeft === 0) return { tone: "red", label: "Today" };
  return { tone: daysLeft <= 14 ? "amber" : "blue", label: `In ${daysLeft} day${daysLeft === 1 ? "" : "s"}` };
}

/** Contracts that end or are due for renewal soon, so nobody is caught out. */
export function ContractAlerts() {
  const [days, setDays] = useState(60);
  const [alerts, setAlerts] = useState<Alert[] | null>(null);

  useEffect(() => {
    api<{ data: Alert[] }>("/contractor-contracts/upcoming", { query: { days } }).then((result) => setAlerts(result.data));
  }, [days]);

  if (alerts === null) return null;

  return (
    <Panel
      title="Ending or up for renewal"
      subtitle={alerts.length === 0 ? `No contract ends or needs renewing in the next ${days} days.` : "Draft and active contracts with an end or renewal date coming up, or already passed."}
      right={
        <select style={{ width: 140 }} value={days} onChange={(event) => setDays(Number(event.target.value))} aria-label="Window">
          <option value={30}>Next 30 days</option>
          <option value={60}>Next 60 days</option>
          <option value={90}>Next 90 days</option>
        </select>
      }
      bodyless={alerts.length > 0}
    >
      {alerts.length > 0 && (
        <Table headers={["Contractor", "Contract", "What", "Date", ""]}>
          {alerts.map((alert) => {
            const due = when(alert.days_left);
            return (
              <tr key={`${alert.contract.id}-${alert.kind}`}>
                <td><Link href={`/contractors/${alert.contractor.id}`}>{alert.contractor.name}</Link></td>
                <td>{alert.contract.title}</td>
                <td>{alert.kind === "ends" ? "Contract ends" : "Renewal or review"}</td>
                <td>{formatDate(alert.date)}</td>
                <td><Badge tone={due.tone} label={due.label} /></td>
              </tr>
            );
          })}
        </Table>
      )}
    </Panel>
  );
}
