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
  patient: { id: number; name: string } | null;
  last_seen_at: string | null;
  can_configure: boolean;
};

export type Patient = {
  id: number;
  name: string;
  birth_year: number | null;
  role: "owner" | "caregiver" | "viewer";
  devices: { id: number; name: string; status: string }[];
};

type Rooms = {
  loaded: boolean;
  devices: Device[];
  patients: Patient[];
  device: Device | null; // the room on screen
  deviceId: number | null;
  patientId: number | null; // that room's child (or the first child for a shared room / no room)
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
  const [patients, setPatients] = useState<Patient[]>([]);
  const [chosen, setChosen] = useState<number | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    try {
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
      if (d.ok) setDevices((await d.json()).devices ?? []);
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

  const selectDevice = useCallback((id: number) => {
    setChosen(id);
    try {
      localStorage.setItem(STORAGE_KEY, String(id));
    } catch {}
  }, []);

  return (
    <RoomsContext.Provider value={{ loaded, devices, patients, device, deviceId: device?.id ?? null, patientId, selectDevice, refresh }}>
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
