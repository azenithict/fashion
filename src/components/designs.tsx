"use client";
import Link from "next/link";
import { useCallback, useEffect, useState, type KeyboardEvent } from "react";
import { useSearchParams } from "next/navigation";
import { api, apiPath, ApiError, text } from "@/lib/api";
import { categories, categoryLabel, enquiryHref, humanize, kindLabels, publicStatusLabels, type ProductCard, type ProductDetail, type PublicMaterial, type PublicMedia } from "@/lib/catalog";

const mediaUrl = (id: string, size: "thumb" | "display") => apiPath("/catalog/media/" + encodeURIComponent(id) + "?size=" + size);
export const EDITORIAL = "Editorial image — illustrates style, not an orderable product";
const enqCustom = "/enquiry/?service=custom_tailoring";

function useLoad<T>(path: string | null) {
  const [state, setState] = useState<{ data: T | null; error: ApiError | Error | null; loading: boolean }>({ data: null, error: null, loading: Boolean(path) });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    if (!path) return;
    let active = true;
    void Promise.resolve().then(() => { if (active) setState(s => ({ ...s, error: null, loading: true })); return api<T>(path); })
      .then(data => { if (active) setState({ data, error: null, loading: false }); })
      .catch(cause => { if (active) setState({ data: null, error: cause instanceof Error ? cause : new Error("Unable to load designs."), loading: false }); });
    return () => { active = false; };
  }, [path, attempt]);
  const retry = useCallback(() => setAttempt(n => n + 1), []);
  return { ...state, retry };
}

export function Photo({ media, size, eager }: { media: PublicMedia; size: "thumb" | "display"; eager?: boolean }) {
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={mediaUrl(media.id, size)} alt={media.alt_text} width={media.width} height={media.height} loading={eager ? "eager" : "lazy"} {...(eager ? { fetchPriority: "high" as const } : {})} />;
}

export function DesignCard({ product }: { product: ProductCard }) {
  const href = "/designs/item/?slug=" + encodeURIComponent(product.slug); const m = product.featured_media;
  return <li className="dz-card">
    <Link href={href} className="dz-card-media" aria-label={"View " + product.name}>{m ? <Photo media={m} size="thumb" /> : <span className="dz-noimg">Photograph in preparation</span>}</Link>
    <div className="dz-card-body">
      <p className="dz-cat">{categoryLabel(product.category)}</p>
      <h3><Link href={href}>{product.name}</Link></h3>
      <p><span className={"dz-badge" + (product.status === "available_to_order" ? " dz-badge-on" : "")}>{publicStatusLabels[product.status] ?? humanize(product.status)}</span></p>
      {m?.editorial && <p className="dz-editorial">{EDITORIAL}</p>}
      <p>{product.summary}</p>
    </div>
  </li>;
}

function Failure({ message, retry }: { message: string; retry: () => void }) {
  return <div className="enq-alert" role="alert"><p>{message}</p><button type="button" className="hm-btn hm-btn-secondary" onClick={retry}>Try again</button></div>;
}

export function DesignsList() {
  const params = useSearchParams(); const requested = params.get("category") ?? "";
  const [category, setCategory] = useState(categories.includes(requested as never) ? requested : "");
  const { data, error, loading, retry } = useLoad<ProductCard[]>("/catalog/products");
  const all = Array.isArray(data) ? data : []; const shown = category ? all.filter(p => p.category === category) : all;
  const pick = (key: string) => { setCategory(key); const url = new URL(window.location.href); if (key) url.searchParams.set("category", key); else url.searchParams.delete("category"); window.history.replaceState(null, "", url); };
  if (loading) return <p role="status">Loading designs…</p>;
  if (error) return <Failure message="We could not load our designs just now. Please try again." retry={retry} />;
  if (all.length === 0) return <div className="dz-empty"><p>Our first verified designs are being photographed and confirmed with our manufacturer. Start from your own idea in the meantime.</p><Link className="hm-btn hm-btn-primary" href={enqCustom}>Start a custom request</Link></div>;
  return <>
    <div className="enq-chips" role="group" aria-label="Filter by category"><button type="button" aria-pressed={!category} onClick={() => pick("")}>All designs</button>{categories.map(c => <button key={c} type="button" aria-pressed={category === c} onClick={() => pick(c)}>{categoryLabel(c)}</button>)}</div>
    {shown.length === 0 ? <div className="dz-empty"><p>No designs are listed in this category yet. You can still start from your own idea.</p><Link className="hm-btn hm-btn-primary" href={enqCustom}>Start a custom request</Link></div> : <ul className="dz-grid">{shown.map(p => <DesignCard key={p.slug} product={p} />)}</ul>}
  </>;
}

export function HomeDesigns() {
  const { data } = useLoad<ProductCard[]>("/catalog/products"); const list = Array.isArray(data) ? data.slice(0, 3) : [];
  if (list.length === 0) return <section className="hm-section hm-alt" aria-labelledby="real-h"><div className="hm-wrap hm-narrow">
    <p className="hm-kicker">Explore Real Designs</p><h2 id="real-h">Our first design collection is being prepared.</h2>
    <p>We are preparing our first verified design collection with our manufacturing partner. Designs will appear here only once they have been photographed and confirmed.</p>
    <p>Until then, start from your own idea or reference images.</p>
    <Link className="hm-btn hm-btn-primary" href="/enquiry/?service=design_question">Describe your design</Link>
  </div></section>;
  return <section className="hm-section hm-alt" aria-labelledby="real-h"><div className="hm-wrap">
    <p className="hm-kicker">Explore Real Designs</p><h2 id="real-h">Verified designs, quoted individually.</h2>
    <ul className="dz-grid">{list.map(p => <DesignCard key={p.slug} product={p} />)}</ul>
    <p><Link className="hm-btn hm-btn-secondary" href="/designs/">View all designs</Link></p>
  </div></section>;
}

function Gallery({ media, name }: { media: PublicMedia[]; name: string }) {
  const [index, setIndex] = useState(0);
  if (media.length === 0) return <div className="dz-gallery"><div className="dz-main"><span className="dz-noimg">Photographs are in preparation</span></div></div>;
  const current = media[Math.min(index, media.length - 1)]; const go = (i: number) => setIndex((i + media.length) % media.length);
  const onKey = (e: KeyboardEvent) => { if (e.key === "ArrowRight") { e.preventDefault(); go(index + 1); } else if (e.key === "ArrowLeft") { e.preventDefault(); go(index - 1); } };
  return <div className="dz-gallery" onKeyDown={onKey}>
    <div className="dz-main" tabIndex={0} role="group" aria-roledescription="carousel" aria-label={name + " photographs. Use the left and right arrow keys to change photo."}>
      <Photo key={current.id} media={current} size="display" eager={index === 0} />
      {media.length > 1 && <><button type="button" className="dz-nav dz-prev" aria-label="Previous photograph" onClick={() => go(index - 1)}>‹</button><button type="button" className="dz-nav dz-next" aria-label="Next photograph" onClick={() => go(index + 1)}>›</button></>}
    </div>
    {current.editorial && <p className="dz-editorial">{EDITORIAL}</p>}
    {media.length > 1 && <ul className="dz-thumbs" aria-label="Photograph thumbnails">{media.map((m, i) => <li key={m.id}><button type="button" aria-label={"Show photograph " + (i + 1) + " of " + media.length} aria-current={i === index ? "true" : undefined} onClick={() => setIndex(i)}><Photo media={m} size="thumb" /></button></li>)}</ul>}
  </div>;
}

const hex = (v?: string | null) => (v && /^#[0-9a-fA-F]{6}$/.test(v) ? v : "");
function Material({ m }: { m: PublicMaterial }) {
  const facts: [string, string | null | undefined][] = [["Composition", m.composition], ["Colour", m.colour_name], ["Pattern", m.pattern], ["Weight", m.weight_gsm ? m.weight_gsm + " gsm" : null], ["Weave", m.weave], ["Finish", m.finish], ["Stretch", m.stretch], ["Care", m.care]];
  const swatch = hex(m.colour_hex); const photo = m.media[0];
  return <li className="dz-material">
    {photo && <Photo media={photo} size="thumb" />}
    <div><h4>{m.name}</h4>
      <dl className="dz-facts">{facts.filter(([, v]) => v).map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
      {swatch && <p className="dz-swatch"><span className="dz-chip" style={{ background: swatch }} role="img" aria-label={"Approximate on-screen colour " + swatch} /> Approximate on-screen colour</p>}
      {photo?.editorial && <p className="dz-editorial">{EDITORIAL}</p>}
    </div>
  </li>;
}

function Unavailable() {
  return <div className="dz-empty"><h1>This design is not available</h1><p>It may have been withdrawn or the link may be incorrect.</p><Link className="hm-btn hm-btn-primary" href="/designs/">Browse all designs</Link></div>;
}

export function DesignDetailView() {
  const slug = useSearchParams().get("slug") ?? "";
  const { data, error, loading, retry } = useLoad<ProductDetail>(slug ? "/catalog/products/" + encodeURIComponent(slug) : null);
  const p = data;
  useEffect(() => { if (p?.name) document.title = p.name + " | A Zenith Fashion"; }, [p?.name]);
  if (!slug) return <Unavailable />;
  if (loading) return <p role="status">Loading design…</p>;
  if (error) return error instanceof ApiError && error.status === 404 ? <Unavailable /> : <Failure message="We could not load this design just now. Please try again." retry={retry} />;
  if (!p) return <Unavailable />;
  const materials = [...new Set(p.materials.map(m => m.kind))].map(kind => [kind, p.materials.filter(m => m.kind === kind)] as const);
  const options = [...new Set(p.options.map(o => o.group))].map(g => [g, p.options.filter(o => o.group === g)] as const);
  return <article className="dz-detail">
    <p><Link className="hm-link" href="/designs/">All designs</Link></p>
    <div className="dz-top">
      <Gallery media={p.media} name={p.name} />
      <div>
        <p className="dz-cat">{categoryLabel(p.category)}</p>
        <h1>{p.name}</h1>
        <p><span className={"dz-badge" + (p.status === "available_to_order" ? " dz-badge-on" : "")}>{publicStatusLabels[p.status] ?? humanize(p.status)}</span></p>
        <p><strong>Pricing: quoted individually after review</strong></p>
        {p.min_order_quantity ? <p>Minimum order quantity: {p.min_order_quantity}</p> : null}
        <p className="dz-desc">{p.description}</p>
        <p className="dz-cta"><Link className="hm-btn hm-btn-primary" href={"/studio/?product=" + encodeURIComponent(p.slug)}>Start customization</Link> <Link className="hm-btn hm-btn-secondary" href={enquiryHref(p.category, p.name)}>Request a quote</Link></p>
      </div>
    </div>
    {materials.length > 0 && <section aria-labelledby="dz-mat"><h2 id="dz-mat">Materials</h2>{materials.map(([kind, list]) => <div key={kind}><h3>{kindLabels[kind] ?? humanize(kind)}</h3><ul className="dz-materials">{list.map(m => <Material key={m.id} m={m} />)}</ul></div>)}<p className="hint">Screen colours are approximate; physical materials are confirmed before production.</p></section>}
    {options.length > 0 && <section aria-labelledby="dz-opt"><h2 id="dz-opt">Construction options</h2>{options.map(([g, list]) => <div key={g}><h3>{humanize(g)}</h3><ul className="dz-options">{list.map(o => <li key={o.id}><strong>{o.label}</strong>{o.description ? <span> — {o.description}</span> : null}{o.media[0] && <Photo media={o.media[0]} size="thumb" />}</li>)}</ul></div>)}</section>}
    <section aria-labelledby="dz-fit"><h2 id="dz-fit">Fit and measurements</h2>
      {p.measurement_requirements.length > 0 && <><p>We will need these measurements before production:</p><ul className="hm-tags">{p.measurement_requirements.map(m => <li key={m}>{humanize(text(m, ""))}</li>)}</ul></>}
      <p><Link className="hm-link" href="/measurements/">Read the measurements guide</Link></p></section>
    {p.faq.length > 0 && <section aria-labelledby="dz-faq"><h2 id="dz-faq">Questions about this design</h2><div className="hm-faq">{p.faq.map((f, i) => <details key={i}><summary>{f.q}</summary><p>{f.a}</p></details>)}</div></section>}
    <p className="dz-cta"><Link className="hm-btn hm-btn-primary" href={"/studio/?product=" + encodeURIComponent(p.slug)}>Start customization</Link> <Link className="hm-btn hm-btn-secondary" href={enquiryHref(p.category, p.name)}>Request a quote</Link></p>
  </article>;
}
