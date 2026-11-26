"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, ApiError, onApiError, session } from "./api";
import type { CompanyChoice, SessionUser } from "./types";

type Status = "loading" | "guest" | "authenticated";

type AuthState = {
  status: Status;
  user: SessionUser | null;
  company: CompanyChoice | null;
  signIn: (email: string, password: string) => Promise<SessionUser>;
  signOut: () => Promise<void>;
  enterCompany: (companyId: string) => void;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<Status>("loading");
  const [user, setUser] = useState<SessionUser | null>(null);
  const [companyId, setCompanyId] = useState<string | null>(null);

  const resetToGuest = useCallback(() => {
    session.clear();
    setUser(null);
    setCompanyId(null);
    setStatus("guest");
  }, []);

  const adopt = useCallback((next: SessionUser) => {
    setUser(next);
    const stored = session.companyId();
    const enterable = next.companies.filter((company) => company.can_enter);
    const keep = enterable.find((company) => company.id === stored);
    const chosen = keep?.id ?? (enterable.length === 1 ? enterable[0].id : null);
    session.setCompanyId(chosen);
    setCompanyId(chosen);
    setStatus("authenticated");
  }, []);

  const refresh = useCallback(async () => {
    if (!session.token()) return resetToGuest();
    try {
      adopt(await api<SessionUser>("/auth/me", { noCompany: true }));
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) resetToGuest();
      else setStatus("guest");
    }
  }, [adopt, resetToGuest]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- restoring the stored session on first load
    void refresh();
  }, [refresh]);

  useEffect(
    () =>
      onApiError((error) => {
        if (error.status === 401) resetToGuest();
      }),
    [resetToGuest],
  );

  const signIn = useCallback(
    async (email: string, password: string) => {
      const result = await api<{ token: string; user: SessionUser }>("/auth/login", {
        body: { email, password },
        noCompany: true,
      });
      session.setToken(result.token);
      adopt(result.user);
      return result.user;
    },
    [adopt],
  );

  const signOut = useCallback(async () => {
    await api("/auth/logout", { method: "POST", noCompany: true }).catch(() => undefined);
    resetToGuest();
  }, [resetToGuest]);

  const enterCompany = useCallback((id: string) => {
    session.setCompanyId(id);
    setCompanyId(id);
  }, []);

  const value = useMemo<AuthState>(
    () => ({
      status,
      user,
      company: user?.companies.find((company) => company.id === companyId) ?? null,
      signIn,
      signOut,
      enterCompany,
      refresh,
    }),
    [status, user, companyId, signIn, signOut, enterCompany, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside AuthProvider");
  return value;
}
