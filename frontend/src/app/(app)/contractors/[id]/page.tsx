"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ContractorForm, type Contractor, type ContractorField } from "@/components/contractors/ContractorForm";
import { Guard } from "@/components/Guard";
import { Badge, Banner, Field, PageHead, Panel } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { countryName } from "@/lib/countries";

function ContractorPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const [contractor, setContractor] = useState<(Contractor & { fields: ContractorField[] }) | null>(null);
  const [editing, setEditing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<Contractor & { fields: ContractorField[] }>(`/contractors/${id}`).then(setContractor);
  }, [id]);

  useEffect(load, [load]);

  if (!contractor) return <div className="loadingpage">Loading…</div>;

  return (
    <>
      <PageHead
        eyebrow="Contractor"
        title={contractor.name}
        subtitle={`${contractor.kind === "supplier" ? "Supplier" : "Individual"} · not paid through payroll`}
        actions={<><Link className="btn" href="/contractors">All contractors</Link>{can("contractors.manage") && <button className="btn primary" onClick={() => setEditing(true)}>Edit</button>}</>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      <div className="grid two">
        <Panel title="Details" right={<Badge status={contractor.status} />}>
          <div className="formgrid">
            <Field label="Legal name"><div>{contractor.legal_name ?? "—"}</div></Field>
            <Field label="Email"><div>{contractor.email ?? "—"}</div></Field>
            <Field label="Phone"><div>{contractor.phone ?? "—"}</div></Field>
            <Field label="Country"><div>{countryName(contractor.country_code)}</div></Field>
            <Field label="Owner"><div>{contractor.owner?.name ?? "—"}</div></Field>
            {contractor.fields.map((field) => <Field key={field.key} label={field.label}><div>{contractor.custom_fields?.[field.key] ?? "—"}</div></Field>)}
            {contractor.notes && <Field label="Notes" full><div style={{ whiteSpace: "pre-wrap" }}>{contractor.notes}</div></Field>}
          </div>
        </Panel>
        <Panel title="Contacts">
          {(contractor.contacts ?? []).length === 0 ? <span className="muted">No contacts.</span> : contractor.contacts?.map((contact, index) => (
            <div key={index} style={{ padding: "6px 0" }}>
              <strong>{contact.name}</strong> {index === 0 && <Badge tone="blue" label="Primary" />}
              <div className="tiny">{[contact.role, contact.email, contact.phone].filter(Boolean).join(" · ")}</div>
            </div>
          ))}
        </Panel>
      </div>
      {editing && <ContractorForm contractor={contractor} fields={contractor.fields} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); setNotice("Saved."); load(); }} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["contractors.view", "contractors.manage"]}>
      <ContractorPage />
    </Guard>
  );
}
