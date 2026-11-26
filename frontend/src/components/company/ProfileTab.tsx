"use client";

import { useEffect, useState } from "react";
import { Banner, Field, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { COUNTRIES } from "@/lib/countries";

type Profile = {
  name: string;
  legal_name: string | null;
  country_code: string;
  contact_email: string | null;
  phone: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
};

export function ProfileTab() {
  const { refresh } = useAuth();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api<Profile>("/company/profile").then(setProfile);
  }, []);

  if (!profile) return <div className="empty">Loading…</div>;

  const set = (key: keyof Profile) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setSaved(false);
    setProfile({ ...profile, [key]: event.target.value });
  };
  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      setProfile(await api<Profile>("/company/profile", { method: "PUT", body: profile }));
      setSaved(true);
      void refresh();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <form onSubmit={save}>
      {saved && <Banner tone="success">Company profile saved.</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <Panel title="Company profile" subtitle="Names and contact details used across M.Y HRMS and on documents." right={<button className="btn primary" type="submit">Save profile</button>}>
        <div className="formgrid">
          <Field label="Trading name" required error={fieldError("name")}>
            <input className="input" value={profile.name} onChange={set("name")} />
          </Field>
          <Field label="Legal name" optional error={fieldError("legal_name")}>
            <input className="input" value={profile.legal_name ?? ""} onChange={set("legal_name")} />
          </Field>
          <Field label="Main country" required error={fieldError("country_code")} hint="Payroll countries are set separately.">
            <select value={profile.country_code} onChange={set("country_code")}>
              {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
            </select>
          </Field>
          <Field label="Contact email" optional error={fieldError("contact_email")}>
            <input className="input" type="email" value={profile.contact_email ?? ""} onChange={set("contact_email")} />
          </Field>
          <Field label="Phone" optional error={fieldError("phone")}>
            <input className="input" value={profile.phone ?? ""} onChange={set("phone")} />
          </Field>
          <Field label="Address line 1" optional>
            <input className="input" value={profile.address_line1 ?? ""} onChange={set("address_line1")} />
          </Field>
          <Field label="Address line 2" optional>
            <input className="input" value={profile.address_line2 ?? ""} onChange={set("address_line2")} />
          </Field>
          <Field label="City" optional>
            <input className="input" value={profile.city ?? ""} onChange={set("city")} />
          </Field>
          <Field label="Province / county / state" optional>
            <input className="input" value={profile.region ?? ""} onChange={set("region")} />
          </Field>
          <Field label="Postal code" optional>
            <input className="input" value={profile.postal_code ?? ""} onChange={set("postal_code")} />
          </Field>
        </div>
      </Panel>
    </form>
  );
}
