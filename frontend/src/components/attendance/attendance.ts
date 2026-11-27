export type Shift = {
  id: string;
  clock_in_local: string;
  clock_out_local: string | null;
  clock_in_timezone: string;
  work_date: string | null;
  minutes: number;
  source: string;
  note: string | null;
  employee?: { id: string; full_name: string };
};

export type MonthSummary = { month: string; days: { date: string; minutes: number; shifts: Shift[] }[]; total_minutes: number; days_worked: number };

export function hours(minutes: number) {
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m`;
}

export function timeOf(local: string | null) {
  return local ? local.slice(11, 16) : "—";
}
