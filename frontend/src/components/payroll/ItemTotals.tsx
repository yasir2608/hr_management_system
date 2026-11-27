"use client";

import { Panel, Table, money } from "@/components/ui";

export type ItemGroup = { category: string; label: string; total: string; items: { code: string; label: string; amount: string; employees: number }[] };

/** The run as a whole: each pay item totalled across employees, grouped like a payslip. */
export function ItemTotals({ groups, currency }: { groups: ItemGroup[]; currency: string }) {
  return (
    <Panel title="Totals by item" subtitle="Each pay item added up across everyone in the run." bodyless>
      <Table headers={["Item", "Employees", "Total"]}>
        {groups.flatMap((group) => [
          <tr key={`${group.category}-head`}>
            <td colSpan={2}><strong>{group.label}</strong></td>
            <td><strong>{money(group.total, currency)}</strong></td>
          </tr>,
          ...group.items.map((item) => (
            <tr key={`${group.category}-${item.code}-${item.label}`}>
              <td style={{ paddingLeft: 24 }}>{item.label}</td>
              <td className="tiny">{item.employees}</td>
              <td>{money(item.amount, currency)}</td>
            </tr>
          )),
        ])}
      </Table>
    </Panel>
  );
}
