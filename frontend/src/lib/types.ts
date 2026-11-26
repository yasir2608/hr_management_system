export type CompanyChoice = {
  id: string;
  name: string;
  country_code: string;
  can_enter: boolean;
  membership_status: "active" | "suspended" | "support";
  company_status: "active" | "suspended";
  /** Set when an M.Y HRMS staff member views the company through a support grant. */
  support_access_until: string | null;
};

export type SessionUser = {
  id: string;
  name: string;
  email: string;
  platform_role: "ops" | "support" | null;
  companies: CompanyChoice[];
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
};
