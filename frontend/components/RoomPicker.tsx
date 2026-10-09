"use client";
import { roomLabel, useRooms } from "@/lib/rooms";

const DOT: Record<string, string> = { online: "bg-emerald-500", offline: "bg-amber-500", pending: "bg-zinc-400" };

/** Which room (device) the dashboard shows. Hidden until a device is paired. */
export default function RoomPicker() {
  const { devices, device, selectDevice } = useRooms();
  if (!device) return null;

  return (
    <label className="flex items-center gap-2 bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/10 rounded-full pl-3 pr-1 py-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
      <span className={`w-2 h-2 rounded-full shrink-0 ${DOT[device.status] ?? DOT.pending}`} title={device.status} />
      <span className="sr-only">Room</span>
      <select
        value={device.id}
        onChange={e => selectDevice(Number(e.target.value))}
        disabled={devices.length < 2}
        className="bg-transparent outline-none py-1 pr-2 max-w-[14rem] truncate cursor-pointer disabled:cursor-default appearance-none"
      >
        {devices.map(d => (
          <option key={d.id} value={d.id} className="text-zinc-900">
            {roomLabel(d)}{d.status === "online" ? "" : ` (${d.status})`}
          </option>
        ))}
      </select>
    </label>
  );
}
