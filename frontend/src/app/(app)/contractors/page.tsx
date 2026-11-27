"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ContractorForm, type Contractor, type ContractorField } from "@/components/contractors/ContractorForm";
import { FieldsModal } from "@/components/contractors/FieldsModal";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, PageHead, Panel, Person, Table } from "@/components/ui";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { countryName } from "@/lib/countries";

function ContractorsPage() {
  const { can } = useAuth();
  const canManage = can("contractors.manage");
  const [contractors, setContractors] = useState<Contractor[] | null>(null);
  const [fields, setFields] = useState<ContractorField[]>([]);
  const [filters, setFilters] = useState({ search: "", status: "active", kind: "" });
  const [adding, setAdding] = useState(false);
  const [editingFields, setEditingFields] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    const result = await api<{ data: Contractor[]; fields: ContractorField[] }>("/contractors", { query: filters });
    setContractors(result.data);
    setFields(result.fields);
  }, [filters]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- fetch when filters change
    void load();
  }, [load]);

  return (
    <>
      <PageHead
        eyebrow="Contractors"
        title="Contracts & invoices"
        subtitle="Track contractor agreements and invoices separately from employee payroll. Contractors are never paid through payroll."
        actions={canManage && <><button className="btn" onClick={() => setEditingFields(true)}>Fields</button><button className="btn primary" onClick={() => setAdding(true)}>Add contractor</button></>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      <Panel
        title="Contractors"
        right={
          <div className="row">
            <input className="input" style={{ width: 200 }} placeholder="Search" value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
            <select style={{ width: 140 }} value={filters.kind} onChange={(event) => setFilters({ ...filters, kind: event.target.value })} aria-label="Type">
              <option value="">Everyone</option>
              <option value="person">Individuals</option>
              <option value="supplier">Suppliers</option>
            </select>
            <select style={{ width: 120 }} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })} aria-label="Status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        }
        bodyless
      >
        {contractors && contractors.length === 0 ? (
          <EmptyState icon="◇" title="No contractors yet" action={canManage && <button className="btn primary" onClick={() => setAdding(true)}>Add contractor</button>} />
        ) : (
          <Table headers={["Contractor", "Type", "Country", "Owner", "Status"]}>
            {contractors?.map((contractor) => (
              <tr key={contractor.id}>
                <td><Link href={`/contractors/${contractor.id}`} style={{ textDecoration: "none" }}><Person name={contractor.name} detail={contractor.email} /></Link></td>
                <td>{contractor.kind === "supplier" ? "Supplier" : "Individual"}</td>
                <td>{countryName(contractor.country_code)}</td>
                <td>{contractor.owner?.name ?? "—"}</td>
                <td><Badge status={contractor.status} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      {editingFields && <FieldsModal fields={fields} onClose={() => setEditingFields(false)} onSaved={() => { setEditingFields(false); setNotice("Fields saved."); void load(); }} />}
      {adding && <ContractorForm fields={fields} onClose={() => setAdding(false)} onSaved={(saved) => { setAdding(false); setNotice(`${saved.name} added.`); void load(); }} />}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["contractors.view", "contractors.manage"]}>
      <ContractorsPage />
    </Guard>
  );
}
