"use client";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
export default function MobileNav({ links, cta }: { links: readonly (readonly [string, string])[]; cta: string }) {
  const [open, setOpen] = useState(false);
  const button = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") { setOpen(false); button.current?.focus(); } };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open]);
  return <div className="az-mobile">
    <button ref={button} type="button" className="az-toggle" aria-expanded={open} aria-controls="az-menu" onClick={() => setOpen(!open)}>{open ? "Close" : "Menu"}</button>
    <nav id="az-menu" className="az-panel" aria-label="Mobile navigation" hidden={!open}>
      {links.map(([t, h]) => <Link key={h} href={h} onClick={() => setOpen(false)}>{t}</Link>)}
      <Link className="az-panel-cta" href={cta} onClick={() => setOpen(false)}>Start Custom Order</Link>
    </nav>
  </div>;
}
