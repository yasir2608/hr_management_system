"use client";

import Link from "next/link";
import { MyAttendanceTab } from "@/components/attendance/MyAttendanceTab";
import { RecordsTab } from "@/components/attendance/RecordsTab";
import { ExceptionsTab } from "@/components/attendance/ExceptionsTab";
import { CorrectionsTab } from "@/components/attendance/CorrectionsTab";
import { Guard } from "@/components/Guard";
import { PageHead } from "@/components/ui";
import { useAuth } from "@/lib/auth";
import { useTab } from "@/lib/useTab";

type TabDef = { key: string; label: string; permissions: string[]; render: () => React.ReactNode };

const tabs: TabDef[] = [
  { key: "mine", label: "My attendance", permissions: ["self.attendance.clock"], render: () => <MyAttendanceTab /> },
  { key: "records", label: "Records", permissions: ["attendance.view", "attendance.manage"], render: () => <RecordsTab /> },
  { key: "exceptions", label: "Exceptions", permissions: ["attendance.view", "attendance.manage"], render: () => <ExceptionsTab /> },
  { key: "corrections", label: "Corrections", permissions: ["attendance.view", "attendance.corrections.approve"], render: () => <CorrectionsTab /> },
];

function AttendancePage() {
  const { can } = useAuth();
  const visible = tabs.filter((tab) => can(...tab.permissions));
  const [active, setActive] = useTab(visible.map((tab) => tab.key));
  const current = visible.find((tab) => tab.key === active) ?? visible[0];

  return (
    <>
      <PageHead
        eyebrow="Time & attendance"
        title="Attendance"
        subtitle="Review clock records and resolve exceptions. Overtime is not calculated in release 1."
        actions={can("self.attendance.clock") && <Link className="btn primary" href="/attendance/clock">Clock in / out</Link>}
      />
      <div className="tabs" role="tablist">
        {visible.map((tab) => (
          <button key={tab.key} role="tab" aria-selected={tab.key === current?.key} className={`tab${tab.key === current?.key ? " on" : ""}`} onClick={() => setActive(tab.key)}>{tab.label}</button>
        ))}
      </div>
      {current?.render()}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["self.attendance.clock", "attendance.view", "attendance.manage"]}>
      <AttendancePage />
    </Guard>
  );
}
