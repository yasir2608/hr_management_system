"use client";

import { useEffect, useState } from "react";
import { Banner, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { COUNTRIES } from "@/lib/countries";

type Choice = { countries: string[]; primary: string | null };

const offered: Record<string, string> = {
  PK: "Income tax, EOBI and provincial social security; Pakistan payroll summaries.",
  GB: "PAYE, National Insurance, pension auto-enrolment, student loans; RTI, P45 and P60 reports.",
  US: "Federal withholding, Social Security, Medicare, FUTA and supported states; 941, 940 and W-2 reports.",
};

export function PayrollCountriesTab() {
  const [choice, setChoice] = useState<Choice | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api<Choice>("/company/payroll-countries").then(setChoice);
  }, []);

  if (!choice) return <div className="empty">Loading…</div>;

  function toggle(code: string, on: boolean) {
    if (!choice) return;
    const countries = on ? [...choice.countries, code] : choice.countries.filter((item) => item !== code);
    const primary = countries.includes(choice.primary ?? "") ? choice.primary : (countries[0] ?? null);
    setChoice({ countries, primary });
    setSaved(false);
  }

  async function save() {
    setError(null);
    try {
      setChoice(await api<Choice>("/company/payroll-countries", { method: "PUT", body: choice }));
      setSaved(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  return (
    <>
      {saved && <Banner tone="success">Payroll countries saved.</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <Panel
        title="Countries and legal entities"
        subtitle="Choose every country where you employ direct employees. This decides which payroll setup and reports are offered."
        right={<button className="btn primary" onClick={() => void save()}>Save countries</button>}
      >
        <div className="stack">
          {COUNTRIES.map((country) => {
            const selected = choice.countries.includes(country.code);
            return (
              <div key={country.code} className="choice" style={{ cursor: "default" }}>
                <label className="checkrow" style={{ flex: 1 }}>
                  <input type="checkbox" checked={selected} onChange={(event) => toggle(country.code, event.target.checked)} />
                  <span>
                    <strong style={{ color: "var(--ink)" }}>{country.name}</strong>
                    <div className="tiny">{offered[country.code]}</div>
                  </span>
                </label>
                {selected && (
                  <label className="checkrow">
                    <input type="radio" name="primary" checked={choice.primary === country.code} onChange={() => setChoice({ ...choice, primary: country.code })} />
                    Primary
                  </label>
                )}
              </div>
            );
          })}
          {choice.countries.length === 0 && (
            <div className="callout blue">No payroll countries yet. You can use HR features now and set up payroll later.</div>
          )}
        </div>
      </Panel>
    </>
  );
}
