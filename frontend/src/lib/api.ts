export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";

const TOKEN_KEY = "myhrms.token";
const COMPANY_KEY = "myhrms.company";

function read(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function write(key: string, value: string | null) {
  try {
    if (value === null) window.localStorage.removeItem(key);
    else window.localStorage.setItem(key, value);
  } catch {
    /* storage unavailable: session lasts for this page only */
  }
}

let memoryToken: string | null = null;
let memoryCompany: string | null = null;

export const session = {
  token: () => memoryToken ?? read(TOKEN_KEY),
  companyId: () => memoryCompany ?? read(COMPANY_KEY),
  setToken(token: string | null) {
    memoryToken = token;
    write(TOKEN_KEY, token);
  },
  setCompanyId(id: string | null) {
    memoryCompany = id;
    write(COMPANY_KEY, id);
  },
  clear() {
    this.setToken(null);
    this.setCompanyId(null);
  },
};

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code?: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

type Options = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  /** Send without the company header (sign-in, company picker, platform pages). */
  noCompany?: boolean;
};

type Listener = (error: ApiError) => void;
const listeners = new Set<Listener>();

/** Notified on 401 (session ended) and company-access errors, so the app can react globally. */
export function onApiError(listener: Listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function buildUrl(path: string, query?: Options["query"]) {
  const url = new URL(API_URL + path);
  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== "") url.searchParams.set(key, String(value));
  });
  return url.toString();
}

function headers(options: Options, isForm: boolean): HeadersInit {
  const result: Record<string, string> = { Accept: "application/json" };
  if (!isForm) result["Content-Type"] = "application/json";
  const token = session.token();
  if (token) result.Authorization = `Bearer ${token}`;
  const companyId = session.companyId();
  if (companyId && !options.noCompany) result["X-Company-Id"] = companyId;
  return result;
}

async function toError(response: Response): Promise<ApiError> {
  const data = await response.json().catch(() => ({}));
  const error = new ApiError(
    data.message ?? "Something went wrong. Please try again.",
    response.status,
    data.code,
    data.errors ?? {},
  );
  listeners.forEach((listener) => listener(error));
  return error;
}

export async function api<T = unknown>(path: string, options: Options = {}): Promise<T> {
  const isForm = typeof FormData !== "undefined" && options.body instanceof FormData;
  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? (options.body === undefined ? "GET" : "POST"),
      headers: headers(options, isForm),
      body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError("We could not reach M.Y HRMS. Check your connection and try again.", 0, "NETWORK");
  }

  if (!response.ok) throw await toError(response);
  if (response.status === 204) return undefined as T;
  return (await response.json()) as T;
}

/** Download a protected file through the API (the link itself carries no access). */
export async function download(path: string, query?: Options["query"]) {
  const response = await fetch(buildUrl(path, query), { headers: headers({}, true) });
  if (!response.ok) throw await toError(response);
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? "download";
  const url = URL.createObjectURL(await response.blob());
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = name;
  anchor.click();
  URL.revokeObjectURL(url);
}
