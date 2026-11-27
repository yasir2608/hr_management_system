/** Employee record shapes shared by the employee pages. Mirrors App\Models\Employee. */

export const EMPLOYEE_STATUSES = ["invited", "onboarding", "active", "on_leave", "ended"] as const;
export type EmployeeStatus = (typeof EMPLOYEE_STATUSES)[number];

export const EMPLOYMENT_TYPES = [
  { value: "full_time", label: "Full time" },
  { value: "part_time", label: "Part time" },
  { value: "fixed_term", label: "Fixed term" },
  { value: "intern", label: "Intern" },
];

export const WORK_ARRANGEMENTS = [
  { value: "onsite", label: "On site" },
  { value: "remote", label: "Remote" },
  { value: "hybrid", label: "Hybrid" },
];

export type CountryField = { label: string; required: boolean; hint: string | null; options?: string[] };

export type Compensation = {
  pay_basis: "salary" | "hourly";
  amount: string;
  currency: string;
  frequency: "annual" | "monthly" | "hourly";
  hours_per_week: string | null;
  valid_from: string;
  valid_to: string | null;
  reason: string | null;
};

export type HistoryRow = {
  id: string;
  valid_from: string;
  valid_to: string | null;
  reason: string | null;
  changed_by?: string | null;
  [key: string]: unknown;
};

export type Employee = {
  id: string;
  employee_number: string;
  first_name: string;
  last_name: string;
  preferred_name: string | null;
  full_name: string;
  work_email: string | null;
  personal_email: string | null;
  phone: string | null;
  date_of_birth: string | null;
  gender: string | null;
  nationality: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  address_country: string | null;
  emergency_contact_name: string | null;
  emergency_contact_relationship: string | null;
  emergency_contact_phone: string | null;
  start_date: string | null;
  end_date: string | null;
  current_job_title: string | null;
  current_employment_type: string | null;
  current_status: EmployeeStatus;
  current_work_arrangement: string | null;
  work_country: string | null;
  department: { id: string; name: string } | null;
  team: { id: string; name: string } | null;
  location: { id: string; name: string } | null;
  manager: { id: string; full_name: string } | null;
  has_account: boolean;
  /** Sections the API left out for this viewer. */
  restricted_sections?: string[];
};

export function statusLabel(status: string) {
  return { invited: "Invited", onboarding: "Onboarding", active: "Active", on_leave: "On leave", ended: "Ended" }[status] ?? status;
}
