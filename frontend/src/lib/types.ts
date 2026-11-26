export type CompanyChoice = {
  id: string;
  name: string;
  country_code: string;
  can_enter: boolean;
  membership_status: "active" | "suspended";
  company_status: "active" | "suspended";
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
