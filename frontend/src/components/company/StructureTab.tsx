"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { COUNTRIES, countryName } from "@/lib/countries";

type Location = { id: string; code: string; name: string; country_code: string; city: string | null; timezone: string | null; is_active: boolean };
type Department = { id: string; name: string; code: string | null; parent_id: string | null; parent?: { name: string } | null; is_active: boolean };
type Team = { id: string; name: string; department_id: string | null; department?: { name: string } | null; is_active: boolean };

type Kind = "locations" | "departments" | "teams";
type Editing = { kind: Kind; record: Record<string, unknown> & { id?: string } };

const empty: Record<Kind, Record<string, unknown>> = {
  locations: { code: "", name: "", country_code: "GB", city: "", timezone: "", is_active: true },
  departments: { name: "", code: "", parent_id: null, is_active: true },
  teams: { name: "", department_id: null, is_active: true },
};

const singular: Record<Kind, string> = { locations: "location", departments: "department", teams: "team" };

export function StructureTab() {
  const [locations, setLocations] = useState<Location[]>([]);
  const [departments, setDepartments] = useState<Department[]>([]);
  const [teams, setTeams] = useState<Team[]>([]);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "error"; text: string } | null>(null);

  const load = useCallback(async () => {
    const [l, d, t] = await Promise.all([
      api<{ data: Location[] }>("/locations"),
      api<{ data: Department[] }>("/departments"),
      api<{ data: Team[] }>("/teams"),
    ]);
    setLocations(l.data);
    setDepartments(d.data);
    setTeams(t.data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial fetch
    void load();
  }, [load]);

  async function save() {
    if (!editing) return;
    setError(null);
    const { kind, record } = editing;
    const body = Object.fromEntries(Object.entries(record).map(([key, value]) => [key, value === "" ? null : value]));
    try {
      await api(record.id ? `/${kind}/${record.id}` : `/${kind}`, { method: record.id ? "PUT" : "POST", body });
      setEditing(null);
      setNotice({ tone: "success", text: `The ${singular[kind]} was saved.` });
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  async function toggleArchive(kind: Kind, record: { id: string; is_active: boolean } & Record<string, unknown>) {
    await api(`/${kind}/${record.id}`, { method: "PUT", body: { ...record, is_active: !record.is_active } });
    await load();
  }

  async function remove(kind: Kind, id: string) {
    try {
      await api(`/${kind}/${id}`, { method: "DELETE" });
      setNotice({ tone: "success", text: `The ${singular[kind]} was deleted.` });
      await load();
    } catch (caught) {
      setNotice({ tone: "error", text: caught instanceof ApiError ? caught.message : "Could not delete." });
    }
  }

  const open = (kind: Kind, record?: Record<string, unknown>) => {
    setError(null);
    setEditing({ kind, record: { ...empty[kind], ...(record ?? {}) } });
  };
  const set = (key: string, value: unknown) => editing && setEditing({ ...editing, record: { ...editing.record, [key]: value } });
  const value = (key: string) => (editing?.record[key] as string | null) ?? "";
  const fieldError = (key: string) => error?.errors[key]?.[0];

  const actions = (kind: Kind, record: { id: string; is_active: boolean } & Record<string, unknown>) => (
    <div className="row" style={{ justifyContent: "flex-end" }}>
      <button className="btn" onClick={() => open(kind, record)}>Edit</button>
      <button className="btn" onClick={() => void toggleArchive(kind, record)}>{record.is_active ? "Archive" : "Restore"}</button>
      <button className="btn danger" onClick={() => void remove(kind, record.id)}>Delete</button>
    </div>
  );

  return (
    <>
      <div className="callout blue" style={{ marginBottom: 14 }}>Optional. Add locations, departments and teams only if your company uses them. Items used in employee history are archived rather than deleted.</div>
      {notice && <Banner tone={notice.tone}>{notice.text}</Banner>}
      <div className="stack">
        <Panel title="Work locations" right={<button className="btn soft" onClick={() => open("locations")}>Add location</button>} bodyless>
          {locations.length === 0 ? <EmptyState title="No locations yet" /> : (
            <Table headers={["Code", "Name", "Country", "Time zone", "Status", ""]}>
              {locations.map((location) => (
                <tr key={location.id}>
                  <td>{location.code}</td>
                  <td><strong>{location.name}</strong>{location.city && <div className="tiny">{location.city}</div>}</td>
                  <td>{countryName(location.country_code)}</td>
                  <td>{location.timezone ?? <span className="muted">Company default</span>}</td>
                  <td><Badge status={location.is_active ? "active" : "archived"} tone={location.is_active ? "green" : "gray"} /></td>
                  <td>{actions("locations", location)}</td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
        <div className="grid split">
          <Panel title="Departments" right={<button className="btn soft" onClick={() => open("departments")}>Add department</button>} bodyless>
            {departments.length === 0 ? <EmptyState title="No departments yet" /> : (
              <Table headers={["Name", "Part of", "Status", ""]}>
                {departments.map((department) => (
                  <tr key={department.id}>
                    <td><strong>{department.name}</strong></td>
                    <td>{department.parent?.name ?? "—"}</td>
                    <td><Badge status={department.is_active ? "active" : "archived"} tone={department.is_active ? "green" : "gray"} /></td>
                    <td>{actions("departments", department)}</td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>
          <Panel title="Teams" right={<button className="btn soft" onClick={() => open("teams")}>Add team</button>} bodyless>
            {teams.length === 0 ? <EmptyState title="No teams yet" /> : (
              <Table headers={["Name", "Department", "Status", ""]}>
                {teams.map((team) => (
                  <tr key={team.id}>
                    <td><strong>{team.name}</strong></td>
                    <td>{team.department?.name ?? "—"}</td>
                    <td><Badge status={team.is_active ? "active" : "archived"} tone={team.is_active ? "green" : "gray"} /></td>
                    <td>{actions("teams", team)}</td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>
        </div>
      </div>
      {editing && (
        <Modal
          title={`${editing.record.id ? "Edit" : "Add"} ${singular[editing.kind]}`}
          onClose={() => setEditing(null)}
          footer={<><button className="btn" onClick={() => setEditing(null)}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save</button></>}
        >
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="formgrid">
            {editing.kind === "locations" && (
              <>
                <Field label="Code" required error={fieldError("code")}><input className="input" value={value("code")} onChange={(event) => set("code", event.target.value)} /></Field>
                <Field label="Name" required error={fieldError("name")}><input className="input" value={value("name")} onChange={(event) => set("name", event.target.value)} /></Field>
                <Field label="Country" required error={fieldError("country_code")}>
                  <select value={value("country_code")} onChange={(event) => set("country_code", event.target.value)}>
                    {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
                  </select>
                </Field>
                <Field label="City" optional><input className="input" value={value("city")} onChange={(event) => set("city", event.target.value)} /></Field>
                <Field label="Time zone" optional full error={fieldError("timezone")} hint="Leave empty to use the company time zone, e.g. Europe/London.">
                  <input className="input" value={value("timezone")} onChange={(event) => set("timezone", event.target.value)} />
                </Field>
              </>
            )}
            {editing.kind === "departments" && (
              <>
                <Field label="Name" required error={fieldError("name")}><input className="input" value={value("name")} onChange={(event) => set("name", event.target.value)} /></Field>
                <Field label="Code" optional><input className="input" value={value("code")} onChange={(event) => set("code", event.target.value)} /></Field>
                <Field label="Part of department" optional full error={fieldError("parent_id")}>
                  <select value={value("parent_id")} onChange={(event) => set("parent_id", event.target.value || null)}>
                    <option value="">None (top level)</option>
                    {departments.filter((department) => department.id !== editing.record.id).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                  </select>
                </Field>
              </>
            )}
            {editing.kind === "teams" && (
              <>
                <Field label="Name" required error={fieldError("name")}><input className="input" value={value("name")} onChange={(event) => set("name", event.target.value)} /></Field>
                <Field label="Department" optional error={fieldError("department_id")}>
                  <select value={value("department_id")} onChange={(event) => set("department_id", event.target.value || null)}>
                    <option value="">None</option>
                    {departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                  </select>
                </Field>
              </>
            )}
          </div>
        </Modal>
      )}
    </>
  );
}
