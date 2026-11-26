export const COUNTRIES = [
  { code: "GB", name: "United Kingdom" },
  { code: "PK", name: "Pakistan" },
  { code: "US", name: "United States" },
];

export function countryName(code?: string | null) {
  return COUNTRIES.find((country) => country.code === code)?.name ?? code ?? "—";
}
