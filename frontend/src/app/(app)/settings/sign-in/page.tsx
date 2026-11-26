"use client";

import { useEffect, useState } from "react";
import { Banner, Field, PageHead, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Sso = {
  protocol: "oidc" | "saml";
  display_name: string;
  email_domain: string;
  login_url: string;
  client_id: string | null;
  is_enabled: boolean;
  is_enforced: boolean;
};

type Settings = { require_mfa: boolean; sso: Sso | null };

const blankSso: Sso = { protocol: "oidc", display_name: "", email_domain: "", login_url: "", client_id: "", is_enabled: false, is_enforced: false };

export default function SignInSettingsPage() {
  const [requireMfa, setRequireMfa] = useState(false);
  const [sso, setSso] = useState<Sso>(blankSso);
  const [useSso, setUseSso] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api<Settings>("/company/sign-in-settings")
      .then((settings) => {
        setRequireMfa(settings.require_mfa);
        if (settings.sso) {
          setSso({ ...settings.sso, client_id: settings.sso.client_id ?? "" });
          setUseSso(true);
        }
        setLoaded(true);
      })
      .catch((caught) => setError(caught instanceof ApiError ? caught : null));
  }, []);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setSaved(false);
    try {
      await api("/company/sign-in-settings", { method: "PUT", body: { require_mfa: requireMfa, sso: useSso ? sso : null } });
      setSaved(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not save.", 0));
    }
  }

  const fieldError = (key: string) => error?.errors[`sso.${key}`]?.[0];
  const update = (patch: Partial<Sso>) => setSso((current) => ({ ...current, ...patch }));

  return (
    <form onSubmit={save}>
      <PageHead
        eyebrow="Settings"
        title="Sign-in controls"
        subtitle="Decide how people in your company prove who they are when they sign in."
        actions={<button className="btn primary" type="submit" disabled={!loaded}>Save changes</button>}
      />
      {saved && <Banner tone="success">Sign-in controls saved. They apply from each person&apos;s next sign-in.</Banner>}
      {error && <Banner tone="error">{error.message}</Banner>}
      <div className="grid split">
        <Panel title="Two-step verification" subtitle="Everyone in the company enters a 6-digit code sent to their email after their password.">
          <label className="checkrow">
            <input type="checkbox" checked={requireMfa} onChange={(event) => setRequireMfa(event.target.checked)} />
            Require two-step verification for all members
          </label>
        </Panel>
        <Panel title="Single sign-on" subtitle="Optional. Let people sign in through your company identity provider.">
          <div className="stack">
            <label className="checkrow">
              <input type="checkbox" checked={useSso} onChange={(event) => setUseSso(event.target.checked)} />
              Configure single sign-on
            </label>
            {useSso && (
              <>
                <div className="callout amber">Demo: the identity-provider exchange is not connected yet (the SSO method is still to be decided). Sign-in requests are sent to the address below.</div>
                <div className="formgrid">
                  <Field label="Protocol" required>
                    <select value={sso.protocol} onChange={(event) => update({ protocol: event.target.value as Sso["protocol"] })}>
                      <option value="oidc">OpenID Connect</option>
                      <option value="saml">SAML 2.0</option>
                    </select>
                  </Field>
                  <Field label="Button name" required error={fieldError("display_name")}>
                    <input className="input" value={sso.display_name} onChange={(event) => update({ display_name: event.target.value })} placeholder="Northstar Google Workspace" />
                  </Field>
                  <Field label="Email domain" required error={fieldError("email_domain")} hint="People with this email domain are offered SSO.">
                    <input className="input" value={sso.email_domain} onChange={(event) => update({ email_domain: event.target.value })} placeholder="northstar.example" />
                  </Field>
                  <Field label="Client ID" optional error={fieldError("client_id")}>
                    <input className="input" value={sso.client_id ?? ""} onChange={(event) => update({ client_id: event.target.value })} />
                  </Field>
                  <Field label="Identity provider sign-in URL" required full error={fieldError("login_url")}>
                    <input className="input" value={sso.login_url} onChange={(event) => update({ login_url: event.target.value })} placeholder="https://idp.example.com/authorize" />
                  </Field>
                </div>
                <label className="checkrow">
                  <input type="checkbox" checked={sso.is_enabled} onChange={(event) => update({ is_enabled: event.target.checked, is_enforced: event.target.checked && sso.is_enforced })} />
                  Switch on single sign-on
                </label>
                <label className="checkrow">
                  <input type="checkbox" disabled={!sso.is_enabled} checked={sso.is_enforced} onChange={(event) => update({ is_enforced: event.target.checked })} />
                  Require single sign-on (password sign-in is refused for this domain)
                </label>
              </>
            )}
          </div>
        </Panel>
      </div>
    </form>
  );
}
