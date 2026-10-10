"use client";
// Who can see one child, and with which role (DESIGN_MULTI_PATIENT.md section 3).
// Owners invite by email, change roles, remove people, cancel invites and read the history;
// everyone can switch their own alerts and leave. The backend enforces all of it.

import { useCallback, useEffect, useState } from "react";
import { authHeaders, useRooms, type Patient } from "@/lib/rooms";
import ThemedSelect, { type SelectOption } from "@/components/ThemedSelect";
import Avatar from "@/components/Avatar";

type Member = { user_id: number; name: string; email: string; avatar: string | null; role: Patient["role"]; alerts: boolean; is_me: boolean };
type Invite = { id: number; email: string; role: Patient["role"]; expires_at: string };
type Entry = { action: string; by: string | null; details: Record<string, unknown> | null; created_at: string };

export const ROLE_HELP: Record<Patient["role"], string> = {
  owner: "Owner: everything, incl. limits, rooms and sharing",
  caregiver: "Caregiver: sees all, gets alerts, logs doses",
  viewer: "Viewer: sees readings and reports",
};

// Owner, caregiver, viewer, with what each may do (shown in the role dropdowns).
const ROLE_OPTIONS: SelectOption<Patient["role"]>[] = [
  { value: "owner", label: "Owner", hint: "Everything, incl. limits, rooms and sharing" },
  { value: "caregiver", label: "Caregiver", hint: "Sees all, gets alerts, logs doses" },
  { value: "viewer", label: "Viewer", hint: "Sees readings and reports" },
];

const input ="bg-white dark:bg-black/40 border border-zinc-200 dark:border-white/10 rounded-lg px-2.5 py-1.5 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-blue-500";

/** One readable line per audit entry. */
function describe(e: Entry): string {
  const d = (e.details ?? {}) as Record<string, string | number | boolean>;
  switch (e.action) {
    case "invite.sent": return `invited ${d.email} as ${d.role}`;
    case "invite.cancelled": return `cancelled the invitation for ${d.email}`;
    case "invite.accepted": return `joined as ${d.role}`;
    case "member.role": return `changed ${d.user} from ${d.from} to ${d.to}`;
    case "member.removed": return `removed ${d.user}`;
    case "member.left": return "left";
    case "patient.created": return `added ${d.name}`;
    case "patient.renamed": return `renamed ${d.from} to ${d.to}`;
    case "device.paired": return `paired a new device (${d.token})`;
    case "device.renamed": return `renamed room ${d.from} to ${d.to}`;
    case "device.moved": return `moved ${d.room} from ${d.from} to ${d.to}`;
    case "device.removed": return `removed room ${d.room}`;
    case "settings.saved": return "saved Smart Alerts";
    case "dose.logged": return `logged a ${d.type === "controller" ? "daily" : "rescue"} dose`;
    case "cough.marked": return d.verified ? (d.inhaler_used ? "confirmed a cough (inhaler used)" : "confirmed a cough") : "marked a cough as false alarm";
    default: return e.action;
  }
}

export default function SharePanel({ patient, onToast }: {
  patient: Patient;
  onToast: (message: string, type: "success" | "error") => void;
}) {
  const { refresh } = useRooms();
  const isOwner = patient.role === "owner";
  const [members, setMembers] = useState<Member[]>([]);
  const [invites, setInvites] = useState<Invite[]>([]);
  const [history, setHistory] = useState<Entry[] | null>(null);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<Patient["role"]>("caregiver");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const res = await fetch(`/api/patients/${patient.id}/members`, { headers: authHeaders() });
    if (!res.ok) return;
    const data = await res.json();
    setMembers(data.members ?? []);
    setInvites(data.invites ?? []);
  }, [patient.id]);

  useEffect(() => { load(); }, [load]);

  const send = async (method: string, url: string, body: object | undefined, success: string) => {
    setBusy(true);
    try {
      const res = await fetch(url, { method, headers: { ...authHeaders(), "Content-Type": "application/json" }, body: body ? JSON.stringify(body) : undefined });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || "Request failed");
      onToast(success, "success");
      await Promise.all([load(), refresh()]);
      return true;
    } catch (e) {
      onToast(e instanceof Error ? e.message : "Error connecting to server", "error");
      return false;
    } finally {
      setBusy(false);
    }
  };

  const me = members.find(m => m.is_me);

  return (
    <div className="mt-3 p-3 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 space-y-3 text-sm">
      {/* Members */}
      <div className="space-y-2">
        {members.map(m => (
          <div key={m.user_id} className="flex items-center gap-2">
            <Avatar name={m.name} src={m.avatar} size="sm" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-zinc-800 dark:text-zinc-200">{m.name}{m.is_me && " (you)"}</p>
              <p className="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{m.email}</p>
            </div>
            {isOwner ? (
              <ThemedSelect<Patient["role"]>
                ariaLabel={`Role of ${m.name}`}
                className="shrink-0"
                value={m.role}
                disabled={busy}
                title={ROLE_HELP[m.role]}
                onChange={role => send("PATCH", `/api/patients/${patient.id}/members/${m.user_id}`, { role }, "Role changed")}
                options={ROLE_OPTIONS}
              />
            ) : (
              <span className="text-[11px] capitalize text-zinc-500 dark:text-zinc-400" title={ROLE_HELP[m.role]}>{m.role}</span>
            )}
            {(isOwner || m.is_me) && (
              <button
                disabled={busy}
                onClick={() => send("DELETE", `/api/patients/${patient.id}/members/${m.user_id}`, undefined, m.is_me ? "You left" : "Access removed")}
                className="text-[10px] text-zinc-400 hover:text-red-500 uppercase tracking-wider font-semibold shrink-0"
              >
                {m.is_me ? "Leave" : "Remove"}
              </button>
            )}
          </div>
        ))}
      </div>

      {me && (
        <label className="flex items-center gap-2 text-[12px] text-zinc-600 dark:text-zinc-400 cursor-pointer">
          <input
            type="checkbox"
            checked={me.alerts}
            disabled={busy}
            onChange={e => send("PATCH", `/api/patients/${patient.id}/alerts`, { alerts: e.target.checked }, e.target.checked ? "Alerts on" : "Alerts off")}
          />
          Send me alerts (email and push) for {patient.name}: repeated coughing, a reading over its limit for 5 minutes, and a device offline for 30 minutes
        </label>
      )}

      {isOwner && (
        <>
          {invites.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-[11px] uppercase tracking-wider text-zinc-500">Waiting to accept</p>
              {invites.map(i => (
                <div key={i.id} className="flex items-center gap-2 text-[12px]">
                  <span className="flex-1 truncate text-zinc-700 dark:text-zinc-300">{i.email} · {i.role}</span>
                  <span className="text-zinc-500 shrink-0">until {new Date(i.expires_at).toLocaleDateString()}</span>
                  <button disabled={busy} onClick={() => send("DELETE", `/api/invites/${i.id}`, undefined, "Invitation cancelled")} className="text-[10px] text-zinc-400 hover:text-red-500 uppercase tracking-wider font-semibold">Cancel</button>
                </div>
              ))}
            </div>
          )}

          <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={async e => {
              e.preventDefault();
              if (await send("POST", `/api/patients/${patient.id}/invites`, { email, role }, `Invitation sent to ${email}`)) setEmail("");
            }}
          >
            <input type="email" required placeholder="Email to invite" value={email} onChange={e => setEmail(e.target.value)} disabled={busy} className={`${input} flex-1 min-w-[10rem]`} />
            <ThemedSelect<Patient["role"]>
              ariaLabel="Role for the invited person"
              className="shrink-0"
              value={role}
              onChange={setRole}
              disabled={busy}
              title={ROLE_HELP[role]}
              options={[ROLE_OPTIONS[1], ROLE_OPTIONS[2], ROLE_OPTIONS[0]]}
            />
            <button type="submit" disabled={busy || !email} className="px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">Invite</button>
          </form>
          <p className="text-[11px] text-zinc-500 dark:text-zinc-400">{ROLE_HELP[role]}. The link works once, for that email, for 7 days.</p>

          <div>
            <button
              onClick={async () => {
                if (history) { setHistory(null); return; }
                const res = await fetch(`/api/patients/${patient.id}/audit`, { headers: authHeaders() });
                if (res.ok) setHistory((await res.json()).entries ?? []);
              }}
              className="text-[11px] text-blue-500 hover:underline"
            >
              {history ? "Hide history" : "Show history"}
            </button>
            {history && (
              <ul className="mt-2 space-y-1 max-h-48 overflow-y-auto text-[11px] text-zinc-600 dark:text-zinc-400">
                {history.length === 0 && <li>Nothing yet.</li>}
                {history.map((e, i) => (
                  <li key={i}>
                    <span className="font-mono text-zinc-500">{new Date(e.created_at).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })}</span>{" "}
                    <span className="text-zinc-800 dark:text-zinc-200">{e.by ?? "Someone"}</span> {describe(e)}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </div>
  );
}
