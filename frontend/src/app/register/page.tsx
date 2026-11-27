"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Banner, Field } from "@/components/ui";
import { api, ApiError, session } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { SessionUser } from "@/lib/types";
import { COUNTRIES } from "@/lib/countries";

export default function RegisterPage() {
  const { refresh } = useAuth();
  const router = useRouter();
  const [company, setCompany] = useState({ name: "", legal_name: "", country_code: "", contact_email: "", phone: "" });
  const [admin, setAdmin] = useState({ name: "", email: "", password: "", password_confirmation: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const fieldError = (key: string) => error?.errors[key]?.[0];

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const result = await api<{ token: string; user: SessionUser }>("/auth/register-company", { body: { company, admin }, noCompany: true });
      session.setToken(result.token);
      session.setCompanyId(result.user.companies[0]?.id ?? null);
      await refresh();
      router.replace("/dashboard");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Something went wrong.", 0));
      setSubmitting(false);
    }
  }

  return (
    <div className="authwrap">
      <form className="panel authcard" style={{ width: "min(620px,100%)" }} onSubmit={submit} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>✦</div>
          <h2>Set up a company workspace</h2>
          <div className="tiny">You will be the primary administrator. Payroll and other settings come later.</div>
        </div>
        {error && <Banner tone="error">{error.message}</Banner>}
        <div className="sectiontitle">Company</div>
        <div className="formgrid">
          <Field label="Company or trading name" required error={fieldError("company.name")}>
            <input className="input" value={company.name} onChange={(event) => setCompany({ ...company, name: event.target.value })} autoFocus />
          </Field>
          <Field label="Legal name" optional error={fieldError("company.legal_name")}>
            <input className="input" value={company.legal_name} onChange={(event) => setCompany({ ...company, legal_name: event.target.value })} />
          </Field>
          <Field label="Main country" required error={fieldError("company.country_code")} hint="Where the company is based. Payroll countries are chosen separately.">
            <select value={company.country_code} onChange={(event) => setCompany({ ...company, country_code: event.target.value })}>
              <option value="">Choose a country</option>
              {COUNTRIES.map((country) => <option key={country.code} value={country.code}>{country.name}</option>)}
            </select>
          </Field>
          <Field label="Company contact email" optional error={fieldError("company.contact_email")}>
            <input className="input" type="email" value={company.contact_email} onChange={(event) => setCompany({ ...company, contact_email: event.target.value })} />
          </Field>
        </div>
        <div className="sectiontitle">Primary administrator</div>
        <div className="formgrid">
          <Field label="Your name" required error={fieldError("admin.name")}>
            <input className="input" value={admin.name} onChange={(event) => setAdmin({ ...admin, name: event.target.value })} autoComplete="name" />
          </Field>
          <Field label="Work email" required error={fieldError("admin.email")}>
            <input className="input" type="email" value={admin.email} onChange={(event) => setAdmin({ ...admin, email: event.target.value })} autoComplete="username" />
          </Field>
          <Field label="Password" required error={fieldError("admin.password")} hint="At least 10 characters, with letters and numbers.">
            <input className="input" type="password" value={admin.password} onChange={(event) => setAdmin({ ...admin, password: event.target.value })} autoComplete="new-password" />
          </Field>
          <Field label="Confirm password" required>
            <input className="input" type="password" value={admin.password_confirmation} onChange={(event) => setAdmin({ ...admin, password_confirmation: event.target.value })} autoComplete="new-password" />
          </Field>
        </div>
        <div className="stack" style={{ marginTop: 18 }}>
          <button className="btn primary block" type="submit" disabled={submitting}>{submitting ? "Creating workspace…" : "Create workspace"}</button>
          <div className="tiny" style={{ textAlign: "center" }}>
            Already have an account? <Link className="link" href="/login">Sign in</Link>
          </div>
        </div>
      </form>
    </div>
  );
}
