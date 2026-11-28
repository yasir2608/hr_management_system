"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Field, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type StaffMember = { id: string; name: string; email: string; platform_role: "ops" | "support"; status: string; can_manage_billing: boolean };

/** Which operations staff may change customer billing. Holders grant or remove the right for others, with a reason. */
export function BillingAccessPanel({ onChange }: { onChange?: () => void }) {
  const [data, setData] = useState<{ data: StaffMember[]; can_manage: boolean } | null>(null);
  const [changing, setChanging] = useState<StaffMember | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setData(await api("/platform/billing/access", { noCompany: true }));
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  if (!data) return null;
  const holders = data.data.filter((member) => member.can_manage_billing).length;

  return (
    <Panel title="Billing access" subtitle="Operations staff with billing rights can change plans, agreements, invoices and payment status. Everyone else can view billing only." bodyless>
      {notice && <div style={{ padding: "10px 16px 0" }}><Banner tone="success">{notice}</Banner></div>}
      <Table headers={["Staff member", "Role", "Billing", ""]}>
        {data.data.map((member) => (
          <tr key={member.id}>
            <td><strong>{member.name}</strong><div className="tiny">{member.email}</div></td>
            <td>{member.platform_role === "ops" ? "Operations" : "Support"}</td>
            <td>{member.can_manage_billing ? <Badge tone="green" label="Can change" /> : <Badge tone="gray" label="View only" />}</td>
            <td style={{ textAlign: "right" }}>
              {data.can_manage && member.platform_role === "ops" && !member.can_manage_billing && <button className="btn soft" onClick={() => setChanging(member)}>Give billing rights</button>}
              {data.can_manage && member.can_manage_billing && holders > 1 && <button className="btn" onClick={() => setChanging(member)}>Remove</button>}
            </td>
          </tr>
        ))}
      </Table>
      {changing && (
        <ChangeAccess
          member={changing}
          onClose={() => setChanging(null)}
          onSaved={() => {
            setNotice(changing.can_manage_billing ? `Billing rights removed from ${changing.name}.` : `${changing.name} can now change billing.`);
            setChanging(null);
            void load();
            onChange?.();
          }}
        />
      )}
    </Panel>
  );
}

function ChangeAccess({ member, onClose, onSaved }: { member: StaffMember; onClose: () => void; onSaved: () => void }) {
  const removing = member.can_manage_billing;
  const [reason, setReason] = useState("");
  const [error, setError] = useState<ApiError | null>(null);

  async function save() {
    setError(null);
    try {
      await api(`/platform/billing/access/${member.id}`, { method: removing ? "DELETE" : "POST", noCompany: true, body: { reason } });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not change billing rights.", 0));
    }
  }

  return (
    <Modal
      title={removing ? `Remove billing rights from ${member.name}` : `Give ${member.name} billing rights`}
      onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>Cancel</button><button className={`btn ${removing ? "danger" : "primary"}`} onClick={() => void save()}>{removing ? "Remove rights" : "Give rights"}</button></>}
    >
      {error && <Banner tone="error">{error.message}</Banner>}
      <p className="tiny" style={{ marginBottom: 10 }}>The change and your reason are kept in the platform activity.</p>
      <Field label="Reason" required error={error?.errors.reason?.[0]}><textarea className="input" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} /></Field>
    </Modal>
  );
}
