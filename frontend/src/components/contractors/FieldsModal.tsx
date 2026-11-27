"use client";

import { useState } from "react";
import { Banner, Modal } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import type { ContractorField } from "./ContractorForm";

type Draft = { label: string; type: ContractorField["type"]; options: string; is_required: boolean };

/** The company's own fields on contractor records. */
export function FieldsModal({ fields, onClose, onSaved }: { fields: ContractorField[]; onClose: () => void; onSaved: () => void }) {
  const [drafts, setDrafts] = useState<Draft[]>(fields.map((field) => ({ label: field.label, type: field.type, options: (field.options ?? []).join(", "), is_required: field.is_required })));
  const [error, setError] = useState<ApiError | null>(null);

  async function save() {
    setError(null);
    try {
      await api("/contractor-fields", {
        method: "PUT",
        body: { fields: drafts.map((draft) => ({ ...draft, options: draft.type === "select" ? draft.options.split(",").map((item) => item.trim()).filter(Boolean) : null })) },
      });
      onSaved();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const set = (index: number, patch: Partial<Draft>) => setDrafts(drafts.map((draft, position) => (position === index ? { ...draft, ...patch } : draft)));

  return (
    <Modal title="Contractor fields" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" onClick={() => void save()}>Save fields</button></>}>
      {error && <Banner tone="error">{error.message}</Banner>}
      <p className="tiny" style={{ marginTop: 0 }}>Add fields your company wants on every contractor, e.g. supplier number or insurance expiry.</p>
      <div className="stack">
        {drafts.map((draft, index) => (
          <div key={index} className="row">
            <input className="input" placeholder="Label" value={draft.label} onChange={(event) => set(index, { label: event.target.value })} />
            <select style={{ width: 110 }} value={draft.type} onChange={(event) => set(index, { type: event.target.value as Draft["type"] })}>
              <option value="text">Text</option>
              <option value="number">Number</option>
              <option value="date">Date</option>
              <option value="select">Choice</option>
            </select>
            {draft.type === "select" && <input className="input" placeholder="Choices, comma separated" value={draft.options} onChange={(event) => set(index, { options: event.target.value })} />}
            <label className="checkrow"><input type="checkbox" checked={draft.is_required} onChange={(event) => set(index, { is_required: event.target.checked })} /> Required</label>
            <button className="btn danger" onClick={() => setDrafts(drafts.filter((_, position) => position !== index))}>×</button>
          </div>
        ))}
        <div><button className="btn soft" onClick={() => setDrafts([...drafts, { label: "", type: "text", options: "", is_required: false }])}>Add field</button></div>
      </div>
    </Modal>
  );
}
