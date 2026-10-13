# Records audio from the Edge AI module (Pico) over USB, exactly as the cough model hears it,
# and saves it as a 16 kHz 16-bit mono WAV file. Needs Edge AI 3.0.2 or newer.
#
#   .\record_audio.ps1 -Port COM4 -Seconds 10 -Out coughs-01.wav
#
# Close the Arduino IDE's Serial Monitor first (it holds the port). Pull the Pico's VSYS wire
# before plugging it into the computer.
param(
    [Parameter(Mandatory = $true)][string]$Port,
    [ValidateRange(1, 60)][int]$Seconds = 10,
    [Parameter(Mandatory = $true)][string]$Out
)
$ErrorActionPreference = 'Stop'
$rate = 16000

$sp = New-Object System.IO.Ports.SerialPort $Port, 115200
$sp.DtrEnable = $true
$sp.RtsEnable = $true
$sp.ReadTimeout = 5000
$sp.Open()
try {
    Start-Sleep -Milliseconds 300
    $sp.DiscardInBuffer()
    $sp.Write("rec $Seconds`n")
    Write-Host "Recording $Seconds s from $Port..."

    # Skip the cough-score lines until the header "REC <samples>".
    $samples = 0
    $deadline = (Get-Date).AddSeconds(5)
    while ($samples -eq 0) {
        if ((Get-Date) -gt $deadline) { throw "No answer from the Edge AI module (needs Edge AI 3.0.2 or newer, microphone working)." }
        $line = $sp.ReadLine().Trim()
        if ($line -match '^REC (\d+)$') { $samples = [int]$Matches[1] }
    }

    $bytes = New-Object byte[] ($samples * 2)
    $got = 0
    $sp.ReadTimeout = 3000
    while ($got -lt $bytes.Length) {
        $got += $sp.Read($bytes, $got, $bytes.Length - $got)
    }
} finally {
    $sp.Close()
}

# 44-byte WAV header + data
$fs = [IO.File]::Create((Join-Path (Get-Location) $Out))
$w = New-Object IO.BinaryWriter($fs)
$w.Write([Text.Encoding]::ASCII.GetBytes('RIFF')); $w.Write([int](36 + $bytes.Length))
$w.Write([Text.Encoding]::ASCII.GetBytes('WAVEfmt ')); $w.Write([int]16)
$w.Write([int16]1); $w.Write([int16]1); $w.Write([int]$rate); $w.Write([int]($rate * 2))
$w.Write([int16]2); $w.Write([int16]16)
$w.Write([Text.Encoding]::ASCII.GetBytes('data')); $w.Write([int]$bytes.Length)
$w.Write($bytes)
$w.Close()
Write-Host "Saved $Out ($Seconds s)"
