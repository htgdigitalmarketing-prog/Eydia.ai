import { InvoiceItem } from "@/lib/api";

const esc = (s: unknown) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c] as string));
const money = (minor: number, currency = "INR") => (currency === "INR" ? "₹" : currency + " ") + (minor / 100).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtDate = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }) : "—");

/** A clean, professional invoice document (full HTML) suitable for print/PDF. */
export function renderInvoiceHtml(inv: InvoiceItem): string {
  const seller = inv.meta?.seller ?? {};
  const cust = inv.meta?.customer ?? {};
  const rate = inv.meta?.gst_rate ?? 0;
  const cur = inv.currency || "INR";
  const rows = (inv.line_items ?? []).map((li) => `
    <tr>
      <td style="padding:10px 8px;border-bottom:1px solid #eee">${esc(li.description)}</td>
      <td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:right">${esc(money(li.amount_minor, cur))}</td>
    </tr>`).join("");

  const paid = inv.status === "paid";

  return `<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${esc(inv.number)}</title>
  <style>
    *{box-sizing:border-box} body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2d2c;margin:0;padding:32px;background:#fff}
    .wrap{max-width:720px;margin:0 auto}
    .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:3px solid #5b6bf5;padding-bottom:18px}
    .brand{font-size:20px;font-weight:800;color:#5b6bf5}
    .muted{color:#667781;font-size:13px;line-height:1.5}
    h1{font-size:26px;margin:0;color:#5b6bf5;letter-spacing:1px}
    .badge{display:inline-block;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:700}
    .paid{background:#e7f7ef;color:#0a7d47}.due{background:#fdecec;color:#c53030}
    table{width:100%;border-collapse:collapse;margin-top:18px;font-size:14px}
    th{text-align:left;padding:10px 8px;background:#f4f8f7;color:#54656f;font-size:12px;text-transform:uppercase}
    .tot td{padding:6px 8px}
    .grand{font-size:17px;font-weight:800;border-top:2px solid #5b6bf5}
    .cols{display:flex;justify-content:space-between;gap:24px;margin-top:22px}
    .col{flex:1}
    .col h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;color:#8a9a97}
    @media print{body{padding:0}.wrap{max-width:none}}
  </style></head><body><div class="wrap">
    <div class="head">
      <div>
        <div class="brand">${esc(seller.company_name || "Eydia.ai")}</div>
        <div class="muted">${esc(seller.address || "")}</div>
        <div class="muted">${seller.gstin ? "GSTIN: " + esc(seller.gstin) : ""}${seller.tax_details ? " · " + esc(seller.tax_details) : ""}</div>
        <div class="muted">${[seller.email, seller.phone].filter(Boolean).map(esc).join(" · ")}</div>
      </div>
      <div style="text-align:right">
        <h1>INVOICE</h1>
        <div class="muted"># ${esc(inv.number ?? "")}</div>
        <div class="muted">Date: ${esc(fmtDate(inv.issued_at))}</div>
        <div style="margin-top:8px"><span class="badge ${paid ? "paid" : "due"}">${paid ? "PAID" : esc((inv.status || "").toUpperCase())}</span></div>
      </div>
    </div>

    <div class="cols">
      <div class="col">
        <h4>Billed to</h4>
        <div style="font-weight:700">${esc(cust.name || "")}</div>
        <div class="muted">${esc(cust.address || "")}</div>
        <div class="muted">${cust.gstin ? "GSTIN: " + esc(cust.gstin) : ""}</div>
        <div class="muted">${[cust.email, cust.phone].filter(Boolean).map(esc).join(" · ")}</div>
      </div>
      <div class="col" style="text-align:right">
        <h4>Subscription</h4>
        <div style="font-weight:700">${esc(inv.meta?.plan_name || "")} ${inv.meta?.billing_period ? "(" + esc(inv.meta.billing_period) + ")" : ""}</div>
        <div class="muted">${inv.meta?.period_start ? esc(fmtDate(inv.meta.period_start)) + " → " + esc(fmtDate(inv.meta.period_end)) : ""}</div>
      </div>
    </div>

    <table>
      <thead><tr><th>Description</th><th style="text-align:right">Amount</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>

    <table style="margin-top:6px">
      <tbody>
        <tr class="tot"><td style="text-align:right">Subtotal</td><td style="text-align:right;width:160px">${esc(inv.subtotal ?? money(inv.subtotal_minor ?? 0, cur))}</td></tr>
        <tr class="tot"><td style="text-align:right">GST (${esc(rate)}%)</td><td style="text-align:right">${esc(inv.tax ?? money(inv.tax_minor ?? 0, cur))}</td></tr>
        <tr class="tot grand"><td style="text-align:right">Total</td><td style="text-align:right">${esc(inv.total)}</td></tr>
      </tbody>
    </table>

    <p class="muted" style="margin-top:26px;text-align:center">Thank you for your business. This is a computer-generated invoice.</p>
  </div></body></html>`;
}

/** Open the invoice in a new window and trigger the print / Save-as-PDF dialog. */
export function printInvoice(inv: InvoiceItem): void {
  const w = window.open("", "_blank", "width=820,height=900");
  if (!w) return;
  w.document.open();
  w.document.write(renderInvoiceHtml(inv));
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}
