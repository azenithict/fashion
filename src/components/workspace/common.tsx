"use client";
import { useCallback, useEffect, useId, useState, type FormEvent, type ReactNode } from "react";
import { api, amount, label, text, type Row } from "@/lib/api";
export function Notice({ error, message }: { error?: string; message?: string }) { return <>{error && <p className="portal-notice error" role="alert">{error}</p>}{message && <p className="portal-notice success" role="status">{message}</p>}</>; }
export function useAction() {
  const [pending, setPending] = useState(false); const [error, setError] = useState(""); const [message, setMessage] = useState("");
  async function run(task: () => Promise<unknown>, success = "Saved."): Promise<boolean> {
    setPending(true); setError(""); setMessage("");
    try { await task(); setMessage(success); return true; } catch (cause) { setError(cause instanceof Error ? cause.message : "The request could not be completed."); return false; } finally { setPending(false); }
  }
  return { pending, error, message, run };
}
export function useResource<T>(path: string) {
  const [data, setData] = useState<T | null>(null); const [error, setError] = useState(""); const [loading, setLoading] = useState(true);
  const refresh = useCallback(async () => { setLoading(true); setError(""); try { setData(await api<T>(path)); } catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to load records."); } finally { setLoading(false); } }, [path]);
  useEffect(() => { let active = true; void Promise.resolve().then(()=>{if(active){setLoading(true);setData(null);}return api<T>(path);}).then(value => { if (active) { setData(value); setError(""); } }).catch(cause => { if (active) setError(cause instanceof Error ? cause.message : "Unable to load records."); }).finally(() => { if (active) setLoading(false); }); return () => { active = false; }; }, [path]);
  return { data, error, loading, refresh };
}
export function ResourceState({ loading, error, empty, onRetry }: { loading: boolean; error: string; empty: boolean; onRetry: () => void }) { return <>{loading && <p role="status">Loading your records…</p>}{error && <div className="portal-notice error" role="alert"><p>{error}</p><button className="button button-outline" onClick={onRetry}>Try again</button></div>}{!loading && !error && empty && <p className="empty-state">No records yet.</p>}</>; }
export function Field({ title, name, type = "text", required = true, value, defaultValue, onChange, children, min, max, step, placeholder }: { title: string; name?: string; type?: string; required?: boolean; value?: string; defaultValue?: string; onChange?: (value: string) => void; children?: ReactNode; min?: number; max?: number; step?: string; placeholder?: string }) {
  const id = useId(); return <label className="portal-field" htmlFor={id}><span>{title}{!required && <small> (optional)</small>}</span>{children ? <select id={id} name={name} required={required} value={value} defaultValue={defaultValue} onChange={onChange ? event => onChange(event.target.value) : undefined}>{children}</select> : type === "textarea" ? <textarea id={id} name={name} rows={4} required={required} maxLength={max ?? 4000} value={value} defaultValue={defaultValue} onChange={onChange ? event => onChange(event.target.value) : undefined} placeholder={placeholder} /> : <input id={id} name={name} type={type} required={required} min={min} max={max} step={step} maxLength={type === "text" ? max ?? 500 : undefined} value={value} defaultValue={defaultValue} onChange={onChange ? event => onChange(event.target.value) : undefined} placeholder={placeholder} />}</label>;
}
export function ActionForm({ title, children, submitLabel = "Save", onSubmit, onDone, success }: { title?: string; children: ReactNode; submitLabel?: string; onSubmit: (data: FormData) => Promise<unknown>; onDone?: () => void; success?: string }) {
  const action = useAction(); async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); if (await action.run(() => onSubmit(data), success)) { form.reset(); onDone?.(); } }
  return <form className="portal-form" onSubmit={submit}>{title && <h3>{title}</h3>}<fieldset disabled={action.pending}>{children}<button className="button button-dark" type="submit">{action.pending ? "Saving…" : submitLabel}</button></fieldset><Notice error={action.error} message={action.message} /></form>;
}
export const value = (data: FormData, name: string) => String(data.get(name) ?? "").trim();
export const integer = (data: FormData, name: string) => { const result = Number(value(data, name)); if (!Number.isSafeInteger(result)) throw new Error("Enter a whole number for " + label(name) + "."); return result; };
export function RecordFacts({ record, fields }: { record: Row; fields: string[] }) { return <dl className="record-facts">{fields.filter(key => record[key] !== undefined && record[key] !== null && record[key] !== "").map(key => <div key={key}><dt>{label(key)}</dt><dd>{key.endsWith("_minor")?amount(record[key]):text(record[key])}</dd></div>)}</dl>; }
export function Status({ value }: { value: unknown }) { return <span className="status-chip">{label(text(value, "pending"))}</span>; }
export function RecordList({ records, fields }: { records: Row[]; fields: string[] }) { return <div className="record-stack">{records.map((record, index) => <RecordFacts key={text(record.id, String(index))} record={record} fields={fields} />)}</div>; }

export function utcDate(data: FormData, name: string) { const date = new Date(value(data,name)); if (Number.isNaN(date.getTime())) throw new Error("Enter a valid date and time."); return date.toISOString().slice(0,19).replace("T"," "); }
