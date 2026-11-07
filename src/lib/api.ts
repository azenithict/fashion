export type Row = Record<string, unknown>;
export type User = { id: string; name: string; email: string; role: "customer" | "operations" | "admin" | "partner"; email_verified_at?: string | null };
export type Session = { user: User | null; csrf: string; capabilities: { manualPayments: boolean; stripe: boolean } };
export class ApiError extends Error {
  constructor(message: string, public status = 0, public code = "unavailable") { super(message); this.name = "ApiError"; }
}
let session: Session | null = null;
let sessionRequest: Promise<Session> | null = null;
export function clearSession() { session = null; sessionRequest = null; }
export async function loadSession(refresh = false): Promise<Session> {
  if (refresh) clearSession();
  if (session) return session;
  if (!sessionRequest) sessionRequest = read<Session>("/session").then(value => { session = value; return value; }).finally(() => { sessionRequest = null; });
  return sessionRequest;
}
async function read<T>(path: string, options: RequestInit = {}): Promise<T> {
  let response: Response;
  try { response = await fetch("/api/v1" + path, { ...options, credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", ...options.headers } }); }
  catch { throw new ApiError("We could not reach the service. Your changes have not been confirmed. Please try again."); }
  let body: { data?: T; error?: { code?: string; message?: string } };
  try { body = await response.json(); }
  catch { throw new ApiError("The service is unavailable. Your changes have not been confirmed.", response.status); }
  if (!response.ok || body.error) {
    if (response.status === 401 || body.error?.code?.includes("csrf")) clearSession();
    throw new ApiError(body.error?.message || "The request could not be completed.", response.status, body.error?.code);
  }
  if (!("data" in body)) throw new ApiError("The service returned an unexpected response.", response.status);
  return body.data as T;
}
export async function api<T>(path: string, method = "GET", data?: unknown): Promise<T> {
  if (method === "GET") return read<T>(path);
  const current = await loadSession();
  const form = data instanceof FormData;
  return read<T>(path, { method, headers: { "X-CSRF-Token": current.csrf, ...(form ? {} : { "Content-Type": "application/json" }) }, body: form ? data : JSON.stringify(data ?? {}) });
}
export const row = (value: unknown): Row => {
  if (typeof value === "string") { try { return row(JSON.parse(value)); } catch { return {}; } }
  return typeof value === "object" && value !== null && !Array.isArray(value) ? value as Row : {};
};
export const rows = (value: unknown): Row[] => {
  if (typeof value === "string") { try { return rows(JSON.parse(value)); } catch { return []; } }
  return Array.isArray(value) ? value.map(row) : [];
};
export const text = (value: unknown, fallback = "—"): string => typeof value === "string" || typeof value === "number" ? String(value) : fallback;
export const amount = (value: unknown): string => typeof value === "number" || typeof value === "string" && /^-?\d+$/.test(value) ? new Intl.NumberFormat("en-CA", { style: "currency", currency: "CAD" }).format(Number(value) / 100) : "—";
export const label = (value: string): string => value.replaceAll("_", " ").replace(/^./, first => first.toUpperCase());
export function checkoutUrl(value: unknown): string {
  if (typeof value !== "string") throw new ApiError("Checkout could not be opened.");
  const url = new URL(value);
  if (url.protocol !== "https:" || url.hostname !== "checkout.stripe.com") throw new ApiError("The payment service returned an invalid checkout address.");
  return url.href;
}
export async function download(path: string, filename: string) {
  const data = await api<unknown>(path);
  const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }));
  const link = document.createElement("a"); link.href = url; link.download = filename; link.click(); URL.revokeObjectURL(url);
}
