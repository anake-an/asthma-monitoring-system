// The Activity Log as a PDF file, made in the browser, for the iPhone/iPad Home Screen app, where
// "Print" (window.print) does nothing. The pages are drawn on a canvas in the same layout and
// colours as the printed page (app/report/page.tsx, print styles), saved as JPEG images in a small
// hand-written PDF (no library), and handed to the share sheet: Print, Save to Files, Mail...
// Drawing on a canvas uses the phone's own fonts, so any name (also non-Latin, emoji) shows.

import { APP_VERSION } from "@/lib/version";
import { BADGE_STYLES, badgeColor, badgeText } from "@/components/ChildBadge";
import {
  LIMITS_NOTE, chartMax, changeText, lastSevenDays, limitUpdates, medicationText, observationText, whenText,
  type ReportData,
} from "@/lib/report";

// A4 in PDF points; the canvas has SCALE pixels per point (about 180 dpi).
const PAGE_W = 595.28;
const PAGE_H = 841.89;
const MARGIN = 40;
const CONTENT_W = PAGE_W - 2 * MARGIN;
const FOOTER_H = 30;
const SCALE = 2.5;
const FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
const MONO = 'ui-monospace, "SF Mono", Menlo, Consolas, monospace';

// Tailwind's colours used by the print styles.
const C = {
  black: "#000000", gray700: "#374151", gray600: "#4b5563", gray500: "#6b7280", gray400: "#9ca3af",
  gray200: "#e5e7eb", gray100: "#f3f4f6", gray50: "#f9fafb", zinc600: "#52525b",
  red700: "#b91c1c", red600: "#dc2626", red200: "#fecaca", red50: "#fef2f2",
  blue700: "#1d4ed8", blue600: "#2563eb", blue200: "#bfdbfe", blue100: "#dbeafe", blue50: "#eff6ff",
  orange600: "#ea580c", orange100: "#ffedd5",
};

// The card icons of the page (24 x 24 SVG paths; a canvas Path2D reads SVG path data).
const ICONS = {
  waves: ["M2 10v3", "M6 6v11", "M10 3v18", "M14 8v7", "M18 5v13", "M22 10v3"],
  bell: ["M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9", "M10.3 21a1.94 1.94 0 0 0 3.4 0", "M4 2C2.8 3.7 2 5.7 2 8", "M22 8c0-2.3-.8-4.3-2-6"],
  inhaler: ["M11 2h3a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z", "M8 9h9v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"],
  file: ["M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z", "M14 2v6h6"],
};

type TextStyle = { size: number; weight?: number; color?: string; align?: "left" | "right" | "center"; tracking?: number; font?: string };

class Pages {
  canvases: HTMLCanvasElement[] = [];
  ctx!: CanvasRenderingContext2D;
  y = MARGIN;

  constructor() {
    this.newPage();
  }

  newPage() {
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(PAGE_W * SCALE);
    canvas.height = Math.round(PAGE_H * SCALE);
    const ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("no canvas");
    ctx.scale(SCALE, SCALE);
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, PAGE_W, PAGE_H);
    ctx.textBaseline = "alphabetic";
    this.canvases.push(canvas);
    this.ctx = ctx;
    this.y = MARGIN;
  }

  /** Start a new page unless `height` still fits above the footer. */
  ensure(height: number) {
    if (this.y + height > PAGE_H - MARGIN - FOOTER_H) this.newPage();
  }

  setFont(s: TextStyle) {
    this.ctx.font = `${s.weight ?? 400} ${s.size}px ${s.font ?? FONT}`;
  }

  width(text: string, s: TextStyle): number {
    this.setFont(s);
    const tracking = s.tracking ?? 0;
    if (!tracking) return this.ctx.measureText(text).width;
    return Array.from(text).reduce((w, ch) => w + this.ctx.measureText(ch).width + tracking, 0) - tracking;
  }

  /** Draw one line; `y` is the baseline. Letter spacing is drawn character by character. */
  text(text: string, x: number, y: number, s: TextStyle) {
    const ctx = this.ctx;
    this.setFont(s);
    ctx.fillStyle = s.color ?? C.black;
    const w = this.width(text, s);
    let left = s.align === "right" ? x - w : s.align === "center" ? x - w / 2 : x;
    const tracking = s.tracking ?? 0;
    if (!tracking) {
      ctx.textAlign = "left";
      ctx.fillText(text, left, y);
      return;
    }
    for (const ch of Array.from(text)) {
      ctx.fillText(ch, left, y);
      left += ctx.measureText(ch).width + tracking;
    }
  }

  /** Split text into lines no wider than `max`. */
  wrap(text: string, max: number, s: TextStyle): string[] {
    const lines: string[] = [];
    let line = "";
    for (const word of text.split(/\s+/).filter(Boolean)) {
      const next = line ? `${line} ${word}` : word;
      if (line && this.width(next, s) > max) {
        lines.push(line);
        line = word;
      } else {
        line = next;
      }
    }
    if (line) lines.push(line);
    return lines;
  }

  /** Wrapped lines from the baseline `y`; returns the height used. */
  paragraph(text: string, x: number, y: number, max: number, s: TextStyle, lineHeight: number): number {
    const lines = this.wrap(text, max, s);
    lines.forEach((l, i) => this.text(l, x, y + i * lineHeight, s));
    return lines.length * lineHeight;
  }

  roundRect(x: number, y: number, w: number, h: number, r: number | [number, number, number, number]) {
    const [tl, tr, br, bl] = typeof r === "number" ? [r, r, r, r] : r;
    const ctx = this.ctx;
    ctx.beginPath();
    ctx.moveTo(x + tl, y);
    ctx.lineTo(x + w - tr, y);
    ctx.arcTo(x + w, y, x + w, y + tr, tr);
    ctx.lineTo(x + w, y + h - br);
    ctx.arcTo(x + w, y + h, x + w - br, y + h, br);
    ctx.lineTo(x + bl, y + h);
    ctx.arcTo(x, y + h, x, y + h - bl, bl);
    ctx.lineTo(x, y + tl);
    ctx.arcTo(x, y, x + tl, y, tl);
    ctx.closePath();
  }

  box(x: number, y: number, w: number, h: number, r: number, fill: string | null, stroke: string | null) {
    this.roundRect(x, y, w, h, r);
    if (fill) { this.ctx.fillStyle = fill; this.ctx.fill(); }
    if (stroke) { this.ctx.strokeStyle = stroke; this.ctx.lineWidth = 0.75; this.ctx.stroke(); }
  }

  line(x1: number, y1: number, x2: number, y2: number, color: string, width = 0.75) {
    const ctx = this.ctx;
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    ctx.stroke();
  }

  icon(paths: string[], x: number, y: number, size: number, color: string, alpha = 1, lineWidth = 2) {
    const ctx = this.ctx;
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(size / 24, size / 24);
    ctx.globalAlpha = alpha;
    ctx.strokeStyle = color;
    ctx.lineWidth = lineWidth;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    for (const p of paths) ctx.stroke(new Path2D(p));
    ctx.restore();
  }

  dot(x: number, y: number, r: number, color: string) {
    this.ctx.beginPath();
    this.ctx.arc(x, y, r, 0, Math.PI * 2);
    this.ctx.fillStyle = color;
    this.ctx.fill();
  }
}

/** Draw the report into A4 pages (canvases). */
function drawReport(data: ReportData): HTMLCanvasElement[] {
  const p = new Pages();
  const L = MARGIN;
  const R = PAGE_W - MARGIN;
  const generated = `Generated on ${new Date().toLocaleString()}`;

  // --- Header ---
  let y = p.y;
  p.box(L, y, 20, 20, 5, C.blue100, null);
  p.icon(ICONS.file, L + 4, y + 4, 12, C.blue700, 1, 2.5);
  p.text("RESPIROSYNC SYSTEM", L + 28, y + 13.5, { size: 7.5, weight: 700, color: C.blue700, tracking: 1.5 });
  p.text("Weekly Activity Log", L, y + 54, { size: 28, weight: 600 });
  p.text("Environment & Cough Monitoring", L, y + 72, { size: 10, weight: 300, color: C.zinc600 });

  const childName = data.patient?.name ?? "My child";
  p.text("CHILD", R, y + 34, { size: 7, weight: 500, color: C.zinc600, align: "right", tracking: 1.5 });
  const nameStyle: TextStyle = { size: 14, weight: 500, align: "right" };
  p.text(childName, R, y + 52, nameStyle);
  if (data.patient) {
    const badge = { id: data.patient.id, name: data.patient.name, color: data.patient.color, emoji: data.patient.emoji };
    const colors = BADGE_STYLES[badgeColor(badge)];
    const cx = R - p.width(childName, nameStyle) - 13;
    p.dot(cx, y + 47, 9, colors.bg);
    p.text(badgeText(badge), cx, y + 50.5, { size: 10, weight: 700, color: colors.fg, align: "center" });
  }
  p.text(`${data.start_date} — ${data.end_date}`, R, y + 68, { size: 8, color: C.zinc600, align: "right", font: MONO });
  p.line(L, y + 90, R, y + 90, "rgba(0,0,0,0.2)");
  p.y = y + 112;

  // --- Summary cards ---
  y = p.y;
  const gap = 12;
  const cardW = (CONTENT_W - 2 * gap) / 3;
  const cardH = 92;
  const cards = [
    { label: "TOTAL COUGH EVENTS", value: data.total_events, unit: "recorded", icon: ICONS.waves, bg: C.gray50, border: C.gray200, labelColor: C.gray500, valueColor: C.black, unitColor: C.zinc600, iconColor: C.gray500 },
    { label: "COUGH ALERTS", value: data.high_severity_events, unit: "met the alert rule", icon: ICONS.bell, bg: C.red50, border: C.red200, labelColor: C.red600, valueColor: C.red700, unitColor: C.red600, iconColor: C.red600 },
    { label: "INHALER ADMINISTERED", value: data.inhaler_doses, unit: "doses used", icon: ICONS.inhaler, bg: C.blue50, border: C.blue200, labelColor: C.blue600, valueColor: C.blue700, unitColor: C.blue600, iconColor: C.blue600 },
  ];
  cards.forEach((c, i) => {
    const x = L + i * (cardW + gap);
    p.box(x, y, cardW, cardH, 12, c.bg, c.border);
    p.icon(c.icon, x + cardW - 14 - 22, y + 12, 22, c.iconColor, 0.7);
    const labelLines = p.wrap(c.label, cardW - 28 - 30, { size: 7, weight: 700, tracking: 1.2 });
    labelLines.forEach((l, j) => p.text(l, x + 14, y + 22 + j * 10, { size: 7, weight: 700, color: c.labelColor, tracking: 1.2 }));
    const valueStyle: TextStyle = { size: 32, weight: 600, color: c.valueColor };
    const value = String(c.value);
    p.text(value, x + 14, y + cardH - 16, valueStyle);
    p.text(c.unit, x + 14 + p.width(value, valueStyle) + 8, y + cardH - 18, { size: 9, color: c.unitColor });
  });
  p.y = y + cardH + 30;

  // --- 7-day chart ---
  const days = lastSevenDays(data);
  const max = chartMax(days);
  const trackH = 130;
  p.ensure(30 + trackH + 40);
  y = p.y;
  p.text("7-Day Incident Frequency", L, y + 12, { size: 13, weight: 600 });
  const legend: [string, string][] = [["Cough Events", C.blue600], ["Emergency Dose", C.red600], ["Daily Dose", C.orange600]];
  let lx = R;
  for (let i = legend.length - 1; i >= 0; i--) {
    const [label, color] = legend[i];
    const w = p.width(label, { size: 8, weight: 500 });
    p.text(label, lx, y + 11, { size: 8, weight: 500, color: C.gray600, align: "right" });
    p.dot(lx - w - 8, y + 8, 3.5, color);
    lx -= w + 22;
  }
  const top = y + 34;
  const colW = CONTENT_W / 7;
  const barW = Math.min(16, (colW - 12) / 2);
  const height = (n: number) => (n > 0 ? Math.max((n / max) * trackH, 3) : 0);
  days.forEach((d, i) => {
    const cx = L + colW * i + colW / 2;
    const coughX = cx - barW - 2;
    const inhalerX = cx + 2;
    const bottom = top + trackH;
    // Tracks
    p.box(coughX, top, barW, trackH, [5, 5, 0, 0], C.gray100, null);
    p.box(inhalerX, top, barW, trackH, [5, 5, 0, 0], C.gray100, null);
    // Coughs
    const ch = height(d.cough_count);
    if (ch) {
      p.box(coughX, bottom - ch, barW, ch, [5, 5, 0, 0], C.blue600, null);
      p.text(String(d.cough_count), coughX + barW / 2, bottom - ch - 3, { size: 7, weight: 600, color: C.blue600, align: "center" });
    }
    // Doses: daily at the bottom, emergency on top
    const dh = height(d.controller_count);
    const rh = height(d.rescue_count);
    if (dh) p.box(inhalerX, bottom - dh, barW, dh, 0, C.orange600, null);
    if (rh) p.box(inhalerX, bottom - dh - rh, barW, rh, 0, C.red600, null);
    let labelY = bottom - dh - rh - 3;
    if (d.controller_count) { p.text(String(d.controller_count), inhalerX + barW / 2, labelY, { size: 7, weight: 600, color: C.orange600, align: "center" }); labelY -= 8; }
    if (d.rescue_count) p.text(String(d.rescue_count), inhalerX + barW / 2, labelY, { size: 7, weight: 600, color: C.red600, align: "center" });
    // Day
    p.text(d.display_day.toUpperCase(), cx, bottom + 14, { size: 7.5, weight: 500, color: C.gray600, align: "center", tracking: 0.6 });
    p.text(d.display_date.toUpperCase(), cx, bottom + 24, { size: 7.5, weight: 500, color: C.gray600, align: "center", tracking: 0.6 });
  });
  p.y = top + trackH + 52;

  // --- Alert limit changes ---
  const { updates, severalRooms } = limitUpdates(data);
  const noteStyle: TextStyle = { size: 8.5, color: C.gray600 };
  p.ensure(60);
  y = p.y;
  p.text("Alert Limit Changes", L, y + 12, { size: 13, weight: 600 });
  p.y = y + 26 + p.paragraph(LIMITS_NOTE, L, y + 28, CONTENT_W, noteStyle, 11.5) + 8;
  const col = { when: L, change: L + 82, by: L + 262, reason: L + 300 };
  const reasonW = R - col.reason;
  const cell: TextStyle = { size: 8.5, color: C.gray700 };
  const tableHeader = () => {
    const hs: TextStyle = { size: 6.5, weight: 700, color: C.gray600, tracking: 1.2 };
    p.text("WHEN", col.when, p.y + 8, hs);
    p.text("CHANGE", col.change, p.y + 8, hs);
    p.text("BY", col.by, p.y + 8, hs);
    p.text("REASON", col.reason, p.y + 8, hs);
    p.y += 16;
  };
  if (updates.length === 0) {
    p.text("No limit changes this week.", L, p.y + 10, { size: 9.5, color: C.gray600 });
    p.y += 30;
  } else {
    tableHeader();
    for (const u of updates) {
      const changeLines = u.changes.map(changeText);
      const reasonLines = u.reason ? p.wrap(u.reason, reasonW, cell) : [];
      const rows = Math.max(changeLines.length + (severalRooms && u.device_name ? 1 : 0), reasonLines.length, 1);
      const rowH = rows * 11.5 + 10;
      if (p.y + rowH > PAGE_H - MARGIN - FOOTER_H) {
        p.newPage();
        tableHeader();
      }
      const ry = p.y;
      p.line(L, ry, R, ry, C.gray200);
      const base = ry + 14;
      p.text(whenText(u.created_at), col.when, base, { size: 8, color: C.gray700, font: MONO });
      let cy = base;
      if (severalRooms && u.device_name) {
        p.text(u.device_name.toUpperCase(), col.change, cy, { size: 6.5, color: C.gray500, tracking: 0.8 });
        cy += 11.5;
      }
      for (const c of changeLines) {
        p.text(`${c.name} `, col.change, cy, cell);
        p.text(c.value, col.change + p.width(`${c.name} `, cell), cy, { ...cell, weight: 500, color: C.black });
        cy += 11.5;
      }
      if (u.source === "user") {
        p.text("You", col.by, base, { size: 8, color: C.gray700 });
      } else {
        const pill = u.source === "ai" ? { text: "AI", bg: C.blue100, fg: C.blue600 } : { text: "RULE", bg: C.orange100, fg: C.orange600 };
        const pw = p.width(pill.text, { size: 6.5, weight: 700, tracking: 0.8 }) + 8;
        p.box(col.by, base - 8, pw, 11, 2.5, pill.bg, null);
        p.text(pill.text, col.by + 4, base, { size: 6.5, weight: 700, color: pill.fg, tracking: 0.8 });
      }
      reasonLines.forEach((l, i) => p.text(l, col.reason, base + i * 11.5, { size: 8, color: C.gray700 }));
      p.y = ry + rowH;
    }
    p.y += 24;
  }

  // --- Summary and notes ---
  const boxW = (CONTENT_W - 16) / 2;
  const textW = boxW - 40;
  const body: TextStyle = { size: 9, color: C.gray700 };
  const summaryH = 56 + p.wrap(observationText(data), textW, body).length * 13 + 26 + p.wrap(medicationText(data), textW, body).length * 13 + 20;
  const boxH = Math.max(summaryH, 150);
  p.ensure(boxH);
  y = p.y;
  // Summary of recorded events
  p.box(L, y, boxW, boxH, 12, "#ffffff", C.gray200);
  p.icon(["m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"], L + 20, y + 18, 13, C.blue600);
  p.text("SUMMARY OF RECORDED EVENTS", L + 39, y + 28, { size: 7.5, weight: 600, tracking: 1.2 });
  let ty = y + 52;
  p.text("Observation:", L + 20, ty, { ...body, weight: 600, color: C.black });
  ty += 13 + p.paragraph(observationText(data), L + 20, ty + 13, textW, body, 13);
  ty += 13;
  p.text("Medication Log:", L + 20, ty, { ...body, weight: 600, color: C.black });
  p.paragraph(medicationText(data), L + 20, ty + 13, textW, body, 13);
  // Activity notes (lines to write on)
  const nx = L + boxW + 16;
  p.box(nx, y, boxW, boxH, 12, "#ffffff", C.gray200);
  p.icon(["M12 20h9", "M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"], nx + 20, y + 18, 13, C.gray600);
  p.text("ACTIVITY NOTES", nx + 39, y + 28, { size: 7.5, weight: 600, tracking: 1.2 });
  for (let ly = y + 70; ly < y + boxH - 12; ly += 28) p.line(nx + 20, ly, nx + boxW - 20, ly, C.gray400);
  p.y = y + boxH;

  // --- Footer on every page ---
  p.canvases.forEach((canvas, i) => {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    p.ctx = ctx; // already scaled
    const fy = PAGE_H - MARGIN;
    p.line(L, fy - 16, R, fy - 16, C.gray200);
    p.text(generated, L, fy, { size: 7.5, color: C.gray400, font: MONO });
    const pages = p.canvases.length > 1 ? ` · PAGE ${i + 1} OF ${p.canvases.length}` : "";
    p.text(`RESPIROSYNC SYSTEM v${APP_VERSION}${pages}`, R, fy, { size: 7.5, color: C.gray400, align: "right", tracking: 0.8 });
  });

  return p.canvases;
}

/** A PDF with one full-page JPEG per page (written by hand: header, objects, xref, trailer). */
async function pdfFromCanvases(canvases: HTMLCanvasElement[]): Promise<Blob> {
  const enc = new TextEncoder();
  const chunks: Uint8Array[] = [];
  const offsets: number[] = [];
  let length = 0;
  const push = (part: string | Uint8Array) => {
    const bytes = typeof part === "string" ? enc.encode(part) : part;
    chunks.push(bytes);
    length += bytes.length;
  };
  const object = (id: number, ...parts: (string | Uint8Array)[]) => {
    offsets[id] = length;
    push(`${id} 0 obj\n`);
    parts.forEach(push);
    push("\nendobj\n");
  };

  const jpegs = await Promise.all(canvases.map(c => new Promise<Uint8Array>((resolve, reject) => {
    c.toBlob(b => (b ? b.arrayBuffer().then(a => resolve(new Uint8Array(a)), reject) : reject(new Error("toBlob failed"))), "image/jpeg", 0.9);
  })));

  push("%PDF-1.4\n");
  push(new Uint8Array([0x25, 0xe2, 0xe3, 0xcf, 0xd3, 0x0a])); // marks the file as binary
  // 1 catalog, 2 page tree, 3 info, then per page: page, content, image.
  const pageId = (i: number) => 4 + i * 3;
  object(1, "<< /Type /Catalog /Pages 2 0 R >>");
  object(2, `<< /Type /Pages /Kids [${canvases.map((_, i) => `${pageId(i)} 0 R`).join(" ")}] /Count ${canvases.length} >>`);
  object(3, `<< /Title (RespiroSync Activity Log) /Producer (RespiroSync ${APP_VERSION}) >>`);
  const size = `${PAGE_W} 0 0 ${PAGE_H}`;
  canvases.forEach((canvas, i) => {
    const id = pageId(i);
    const content = `q ${size} 0 0 cm /Im0 Do Q`;
    object(id, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_W} ${PAGE_H}] /Resources << /XObject << /Im0 ${id + 2} 0 R >> >> /Contents ${id + 1} 0 R >>`);
    object(id + 1, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
    object(id + 2,
      `<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpegs[i].length} >>\nstream\n`,
      jpegs[i],
      "\nendstream");
  });

  const count = 4 + canvases.length * 3;
  const xref = length;
  push(`xref\n0 ${count}\n0000000000 65535 f \n`);
  for (let id = 1; id < count; id++) push(`${String(offsets[id]).padStart(10, "0")} 00000 n \n`);
  push(`trailer\n<< /Size ${count} /Root 1 0 R /Info 3 0 R >>\nstartxref\n${xref}\n%%EOF\n`);

  return new Blob(chunks as BlobPart[], { type: "application/pdf" });
}

/** The report as a PDF file named after the child and the end date. */
export async function reportPdf(data: ReportData): Promise<File> {
  const blob = await pdfFromCanvases(drawReport(data));
  const child = (data.patient?.name ?? "child").replace(/[\s/\\:*?"<>|.]+/g, "-").replace(/^-|-$/g, "") || "child";
  return new File([blob], `RespiroSync-Activity-Log-${child}-${data.end_date}.pdf`, { type: "application/pdf" });
}

/** True in the iPhone/iPad Home Screen app, where window.print() does nothing. */
export function isIosHomeScreenApp(): boolean {
  if (typeof window === "undefined") return false;
  const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  const standalone = (navigator as Navigator & { standalone?: boolean }).standalone === true || window.matchMedia("(display-mode: standalone)").matches;
  return ios && standalone;
}

/**
 * Hand a file to the share sheet where there is one (Print, Save to Files, Mail...), else save it
 * as a download. Returns false only when the person closed the share sheet.
 */
export async function shareOrSave(file: File): Promise<boolean> {
  if (navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: file.name });
      return true;
    } catch (e) {
      if (e instanceof DOMException && e.name === "AbortError") return false;
      // NotAllowedError (e.g. too long after the tap): fall back to a download.
    }
  }
  const url = URL.createObjectURL(file);
  const a = document.createElement("a");
  a.href = url;
  a.download = file.name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return true;
}
