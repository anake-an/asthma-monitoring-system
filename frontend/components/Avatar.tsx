// An account's photo, or the first letter of its name when it has none.

const SIZES = {
  sm: "w-7 h-7 text-xs",
  md: "w-8 h-8 text-sm",
  xl: "w-16 h-16 text-2xl",
};

export default function Avatar({ name, src, size = "md", className = "" }: { name: string; src?: string | null; size?: keyof typeof SIZES; className?: string }) {
  const base = `shrink-0 rounded-full ${SIZES[size]} ${className}`;
  if (src) {
    return <img src={src} alt="" className={`${base} object-cover`} />;
  }
  return (
    <span aria-hidden="true" className={`${base} inline-flex items-center justify-center bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 font-bold`}>
      {Array.from(name.trim())[0]?.toUpperCase() ?? "?"}
    </span>
  );
}

/**
 * Crop a chosen photo to a centred square and shrink it to 256 x 256 JPEG, in the browser.
 * Redrawing it also drops everything stored in the file besides the pixels, such as the GPS
 * location of the photo. Browsers apply the photo's rotation when decoding it.
 */
export async function photoToAvatar(file: File): Promise<string> {
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.src = url;
    await img.decode();
    const side = Math.min(img.naturalWidth, img.naturalHeight);
    if (!side) throw new Error("empty image");
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = 256;
    const ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("no canvas");
    ctx.fillStyle = "#ffffff"; // transparent PNGs get a white background in the JPEG
    ctx.fillRect(0, 0, 256, 256);
    ctx.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, 256, 256);
    return canvas.toDataURL("image/jpeg", 0.85);
  } finally {
    URL.revokeObjectURL(url);
  }
}
