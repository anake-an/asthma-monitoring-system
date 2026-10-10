import Link from "next/link";
import ThemeToggle from "@/components/ThemeToggle";

export default function PrivacyPolicyPage() {
  return (
    <div className="min-h-[100dvh] bg-gray-50 dark:bg-[#09090b] text-zinc-900 dark:text-zinc-100 font-sans selection:bg-blue-500/30">

      {/* Header Bar */}
      <div className="sticky top-0 z-50 bg-white/80 dark:bg-[#12121e]/80 backdrop-blur-xl border-b border-zinc-200 dark:border-white/10 px-6 py-4 flex items-center justify-between shadow-sm">
        <Link href="/register" className="flex items-center gap-2 text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition-colors">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
          <span className="text-sm font-medium">Back to Registration</span>
        </Link>
        <ThemeToggle />
      </div>

      {/* Main Content */}
      <main className="max-w-3xl mx-auto px-6 py-12 sm:py-20">
        <div className="mb-12">
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight mb-4">Privacy Policy & Data Protection Notice</h1>
          <p className="text-zinc-500 dark:text-zinc-400">
            Last Updated: October 2026<br />
            Compliant with: <strong>Akta Perlindungan Data Peribadi 2010 (Akta 709) & Pindaan 2024 (Akta A1727)</strong>
          </p>
        </div>

        <div className="space-y-10 text-sm sm:text-base leading-relaxed text-zinc-700 dark:text-zinc-300">

          <section>
            <h2 className="text-xl font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
              <span className="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 w-8 h-8 rounded-lg flex items-center justify-center text-sm">1</span>
              Pengenalan (Introduction)
            </h2>
            <p className="mb-4">
              Welcome to RespiroSync. We take your privacy and the security of your health and sensor data seriously. This Privacy Notice is issued pursuant to the requirements of the <strong>Personal Data Protection Act 2010 (PDPA)</strong> and the <strong>2024 Amendments (Act A1727)</strong> of Malaysia.
            </p>
            <p>
              By registering an account and using the RespiroSync hardware devices, you explicitly consent to the collection, processing, and storage of your personal data and sensitive health data as outlined in this document.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
              <span className="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 w-8 h-8 rounded-lg flex items-center justify-center text-sm">2</span>
              Kutipan Data Sensitif (Collection of Sensitive Data)
            </h2>
            <p className="mb-3">Under the PDPA, health-related information is classified as <strong>Sensitive Personal Data</strong>. RespiroSync collects and processes the following:</p>
            <ul className="list-disc pl-5 space-y-2 text-zinc-600 dark:text-zinc-400">
              <li><strong>Environmental Telemetry:</strong> PM2.5 (Dust), VOC (Gas), Temperature, and Humidity readings collected via your local IoT device.</li>
              <li><strong>Health events:</strong> Logs of inhaler usage and automated microphone-based cough detection frequencies.</li>
              <li><strong>Account Data:</strong> Name, Email Address, and hashed authentication tokens.</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
              <span className="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 w-8 h-8 rounded-lg flex items-center justify-center text-sm">3</span>
              Tujuan Pemprosesan & AI (Purpose of Processing & AI)
            </h2>
            <p className="mb-3">Your data is strictly processed to provide the core services of RespiroSync:</p>
            <ul className="list-disc pl-5 space-y-2 text-zinc-600 dark:text-zinc-400">
              <li>To train a prediction model on your own account's data only, estimating the risk of an asthma-like event from your room's environmental readings.</li>
              <li>To generate personalized "Hybrid Thresholds" for your room's physical buzzer alarms.</li>
              <li>To provide you with a historical dashboard of your respiratory health.</li>
            </ul>
            <p className="mt-4 font-medium text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 p-3 rounded-xl border border-amber-200 dark:border-amber-500/20">
              Your health data is never sold to third parties, advertising agencies, or external brokers.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
              <span className="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 w-8 h-8 rounded-lg flex items-center justify-center text-sm">4</span>
              Prinsip Keselamatan (Security & Breach Notification)
            </h2>
            <p className="mb-3">
              In compliance with the mandatory breach notification requirements under <strong>Act A1727 (2024)</strong>, we employ the following protections:
            </p>
            <ul className="list-disc pl-5 space-y-2 text-zinc-600 dark:text-zinc-400">
              <li>Cryptographic hashing (Bcrypt) for all account passwords.</li>
              <li>Laravel Sanctum bearer tokens for the web app, and authenticated broker accounts with per-device topic access control for IoT devices.</li>
            </ul>
            <p className="mt-3">
              In the unlikely event of a data breach compromising your Sensitive Personal Data, we are legally bound to notify the <strong>Jabatan Perlindungan Data Peribadi (JPDP)</strong> and you within the mandated 72-hour window.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
              <span className="bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 w-8 h-8 rounded-lg flex items-center justify-center text-sm">5</span>
              Hak Akses & Padam (Right to Access & Erasure)
            </h2>
            <p>
              You maintain full ownership of your health data. At any time you can download a child&apos;s data yourself (Activity Log → Download: sensor readings, coughs, inhaler doses and alert limit changes, as spreadsheet files), and you can permanently delete a child or your whole account (Account Settings) together with their records (the &quot;Right to be Forgotten&quot;). Sensor readings older than 7 days are kept as 10-minute averages and deleted after a year.
            </p>
          </section>

          <div className="pt-8 pb-12 text-center">
            <p className="text-sm text-zinc-500 dark:text-zinc-400">
              If you have inquiries regarding your data under the PDPA, please contact our Data Protection Officer (DPO) at privacy@respirosync.online
            </p>
          </div>

        </div>
      </main>

    </div>
  );
}
