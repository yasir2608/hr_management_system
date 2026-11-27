"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Badge, Banner } from "@/components/ui";
import { api, ApiError, session } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { CompanyChoice } from "@/lib/types";

const countryNames: Record<string, string> = { PK: "Pakistan", GB: "United Kingdom", US: "United States" };

function blockedReason(company: CompanyChoice) {
  if (company.company_status !== "active") return "This company account is suspended. Contact M.Y HRMS support.";
  if (company.membership_status !== "active") return "Your access is suspended. Contact the company administrator.";
  return null;
}

export default function SelectCompanyPage() {
  const { status, user, enterCompany, signOut } = useAuth();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [opening, setOpening] = useState<string | null>(null);

  useEffect(() => {
    try {
      const notice = window.sessionStorage.getItem("myhrms.companyNotice");
      if (notice) {
        window.sessionStorage.removeItem("myhrms.companyNotice");
        // eslint-disable-next-line react-hooks/set-state-in-effect -- read once from browser storage
        setError(notice);
      }
    } catch {
      /* storage unavailable */
    }
  }, []);

  useEffect(() => {
    if (status === "guest") router.replace("/login");
  }, [status, router]);

  async function open(company: CompanyChoice) {
    setOpening(company.id);
    setError(null);
    const previous = session.companyId();
    session.setCompanyId(company.id);
    try {
      // The server confirms the membership before we enter.
      await api("/company");
      enterCompany(company.id);
      router.replace("/dashboard");
    } catch (caught) {
      session.setCompanyId(previous);
      setError(caught instanceof ApiError ? caught.message : "We could not open that company.");
      setOpening(null);
    }
  }

  if (!user) return <div className="loadingpage">Loading…</div>;

  return (
    <div className="authwrap">
      <div className="panel authcard" style={{ width: "min(470px,100%)" }}>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>✦</div>
          <h2>Choose a company</h2>
          <div className="tiny">You have access to more than one company workspace.</div>
        </div>
        {error && <Banner tone="error">{error}</Banner>}
        <div className="stack">
          {user.companies.map((company) => {
            const reason = blockedReason(company);
            return (
              <button key={company.id} className="choice" disabled={!!reason || opening !== null} onClick={() => void open(company)}>
                <div className="person">
                  <div className="mini">{company.name.slice(0, 2).toUpperCase()}</div>
                  <div>
                    <strong>{company.name}</strong>
                    <div className="tiny">{reason ?? countryNames[company.country_code] ?? company.country_code}</div>
                  </div>
                </div>
                {reason ? <Badge status="suspended" /> : <span className="link">{opening === company.id ? "Opening…" : "Open →"}</span>}
              </button>
            );
          })}
          {user.companies.length === 0 && <p className="muted">Your account is not linked to a company yet.</p>}
          <button className="btn" onClick={() => void signOut()}>Sign out</button>
        </div>
      </div>
    </div>
  );
}
