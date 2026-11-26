"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, formatDateTime, Panel, Table } from "@/components/ui";
import { api } from "@/lib/api";

type Grant = { id: string; staff: string; reason: string; ticket_reference: string | null; expires_at: string; revoked_at: string | null; created_at: string; active: boolean };

/** Shows the company when M.Y HRMS support had access, why, and lets it end access. */
export function SupportAccessPanel({ canManage }: { canManage: boolean }) {
  const [grants, setGrants] = useState<Grant[]>([]);

  const load = useCallback(async () => {
    setGrants((await api<{ data: Grant[] }>("/support-access")).data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (grants.length === 0) return null;

  async function revoke(id: string) {
    await api(`/support-access/${id}/revoke`, { method: "POST" });
    await load();
  }

  return (
    <div style={{ marginTop: 15 }}>
      <Panel title="M.Y HRMS support access" subtitle="Support staff can only view, never change, and never see salary, bank or tax details. Every page they open is in the activity history." bodyless>
        <Table headers={["Staff member", "Reason", "Opened", "Until", "Status", ""]}>
          {grants.map((grant) => (
            <tr key={grant.id}>
              <td><strong>{grant.staff}</strong></td>
              <td style={{ whiteSpace: "normal", maxWidth: 300 }}>{grant.reason}</td>
              <td>{formatDateTime(grant.created_at)}</td>
              <td>{formatDateTime(grant.expires_at)}</td>
              <td>{grant.active ? <Badge tone="amber" label="Active" /> : <Badge tone="gray" label={grant.revoked_at ? "Ended" : "Expired"} />}</td>
              <td style={{ textAlign: "right" }}>{canManage && grant.active && <button className="btn danger" onClick={() => void revoke(grant.id)}>End access</button>}</td>
            </tr>
          ))}
        </Table>
      </Panel>
    </div>
  );
}
