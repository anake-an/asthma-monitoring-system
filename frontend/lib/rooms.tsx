"use client";
// Rooms (devices) and children (patients) of the signed-in account, and which room the dashboard
// shows. Every card reads the chosen room from here, so they always show the same one.
// The choice is remembered per browser (localStorage); the backend checks every id it receives.

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";

export type Device = {
  id: number;
  name: string;
  status: "pending" | "online" | "offline";
  device_token: string;
  patient_id: number | null; // null = shared room
  patient: { id: number; name: string; color: string | null; emoji: string | null } | null;
  last_seen_at: string | null;
  can_configure: boolean;
  firmware_version: string | null; // reported by the device when it connects (3.0.0 and newer)
  firmware_build: string | null; // build stamp, e.g. "261011.2"
  firmware_label: string | null; // "3.1.0 Build 261011"
  update_available: boolean; // a newer firmware is published on the server
  ota: { status: "updating" | "updated" | "failed" | null; target: string | null; error: string | null };
};

export type Patient = {
  id: number;
  name: string;
  birth_year: number | null;
  color: string | null; // badge colour (components/ChildBadge), null: automatic
  emoji: string | null; // badge emoji, null: the initial
  role: "owner" | "caregiver" | "viewer"; // my role for this child
  alerts: boolean; // whether I get its alerts (coughs, readings over a limit)
  devices: { id: number; name: string; status: string }[];
};

export type FirmwareInfo = { version: string; build: string | null; label: string; notes: string | null };

type Rooms = {
  loaded: boolean;
  devices: Device[];
  latestFirmware: FirmwareInfo | null; // newest firmware published on the server
  patients: Patient[];
  device: Device | null; // the room on screen
  deviceId: number | null;
  patientId: number | null; // that room's child (or the first child for a shared room / no room)
  canLogDose: boolean; // log doses for that child: owner or caregiver
  canMarkCoughs: boolean; // mark the room's coughs: its owner, or owner/caregiver of its child
  selectDevice: (id: number) => void;
  refresh: () => Promise<void>;
};

const STORAGE_KEY = "respirosync_device";
const RoomsContext = createContext<Rooms | null>(null);

export function authHeaders(): Record<string, string> {
  return { Authorization: `Bearer ${localStorage.getItem("auth_token")}`, Accept: "application/json" };
}

/** Add ?device_id= (or &device_id=) when a room is chosen; the backend defaults otherwise. */
export function withDevice(url: string, deviceId: number | null): string {
  return deviceId == null ? url : `${url}${url.includes("?") ? "&" : "?"}device_id=${deviceId}`;
}

export function RoomsProvider({ children }: { children: ReactNode }) {
  const [devices, setDevices] = useState<Device[]>([]);
  const [latestFirmware, setLatestFirmware] = useState<FirmwareInfo | null>(null);
  const [patients, setPatients] = useState<Patient[]>([]);
  const [chosen, setChosen] = useState<number | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    try {
      // A tapped alert push opens /?device=<id>: show that room (and remember it).
      const params = new URLSearchParams(window.location.search);
      const fromLink = Number(params.get("device"));
      if (fromLink) {
        localStorage.setItem(STORAGE_KEY, String(fromLink));
        params.delete("device");
        window.history.replaceState(null, "", window.location.pathname + (params.toString() ? `?${params}` : ""));
      }
      const saved = Number(localStorage.getItem(STORAGE_KEY));
      if (saved) setChosen(saved);
    } catch {}
  }, []);

  const refresh = useCallback(async () => {
    try {
      const headers = authHeaders();
      const [d, p] = await Promise.all([fetch("/api/devices", { headers }), fetch("/api/patients", { headers })]);
      if (d.status === 401) {
        window.location.href = "/login";
        return;
      }
      if (d.ok) {
        const data = await d.json();
        setDevices(data.devices ?? []);
        setLatestFirmware(data.latest_firmware ?? null);
      }
      if (p.ok) setPatients((await p.json()).patients ?? []);
    } catch {
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => {
    refresh();
    const interval = setInterval(refresh, 10000); // status / last seen
    return () => clearInterval(interval);
  }, [refresh]);

  // The chosen room while it still exists, else the most recently seen one (the API's order).
  const device = devices.find(d => d.id === chosen) ?? devices[0] ?? null;
  const patientId = device?.patient_id ?? patients.find(p => p.role === "owner")?.id ?? patients[0]?.id ?? null;
  const roleOf = (id: number | null | undefined) => patients.find(p => p.id === id)?.role;
  const canLogDose = ["owner", "caregiver"].includes(roleOf(patientId) ?? "");
  const canMarkCoughs = !!device?.can_configure || ["owner", "caregiver"].includes(roleOf(device?.patient_id) ?? "");

  const selectDevice = useCallback((id: number) => {
    setChosen(id);
    try {
      localStorage.setItem(STORAGE_KEY, String(id));
    } catch {}
  }, []);

  return (
    <RoomsContext.Provider value={{ loaded, devices, latestFirmware, patients, device, deviceId: device?.id ?? null, patientId, canLogDose, canMarkCoughs, selectDevice, refresh }}>
      {children}
    </RoomsContext.Provider>
  );
}

export function useRooms(): Rooms {
  const rooms = useContext(RoomsContext);
  if (!rooms) throw new Error("useRooms() needs a <RoomsProvider> above it");
  return rooms;
}

/** "Bedroom · Aiman", or "Bedroom · shared room". */
export function roomLabel(device: Device): string {
  return `${device.name} · ${device.patient ? device.patient.name : "shared room"}`;
}

/**
 * The firmware an owner can install on this room's device ("3.1.0"), or null: none newer, not an
 * owner, or an update is already running. The server decides what is newer (version, then build).
 */
export function firmwareUpdateFor(device: Device, latest: FirmwareInfo | null): string | null {
  if (!latest || !device.can_configure || !device.update_available || device.ota?.status === "updating") return null;
  return latest.version;
}
