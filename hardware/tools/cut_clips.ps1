# Cuts recordings from record_audio.ps1 into 1-second training clips for Edge Impulse.
#
#   .\cut_clips.ps1 -In coughs-01.wav -Label cough -OutDir clips   # one clip per cough
#   .\cut_clips.ps1 -In room-01.wav   -Label noise -OutDir clips   # every second of the file
#
# -In takes a file or a folder of .wav files (16 kHz, 16-bit mono). Clips are named
# "<label>.<recording>-<n>.wav", so Edge Impulse's "Infer from filename" labels them.
# Coughs: a clip starts 150 ms before each loud burst (a 16 ms block 4x louder than the
# recording's typical level, and at least 600); one clip per burst, at most one per 0.8 s.
param(
    [Parameter(Mandatory = $true)][string]$In,
    [Parameter(Mandatory = $true)][ValidateSet('cough', 'noise')][string]$Label,
    [string]$OutDir = 'clips'
)
$ErrorActionPreference = 'Stop'
$rate = 16000
$block = 256
New-Item -ItemType Directory -Force $OutDir | Out-Null
$OutDir = (Resolve-Path $OutDir).Path  # .NET file calls resolve relative paths from elsewhere

function Read-Wav([string]$path) {
    $b = [IO.File]::ReadAllBytes($path)
    $i = 12
    while ($i -lt $b.Length - 8) {
        $id = [Text.Encoding]::ASCII.GetString($b, $i, 4)
        $len = [BitConverter]::ToInt32($b, $i + 4)
        if ($id -eq 'data') {
            $n = [Math]::Floor([Math]::Min($len, $b.Length - $i - 8) / 2)
            $s = New-Object int16[] $n
            [Buffer]::BlockCopy($b, $i + 8, $s, 0, $n * 2)
            return , $s
        }
        $i += 8 + $len
    }
    throw "no audio data in $path"
}

function Write-Clip([string]$path, [int16[]]$s, [int]$start) {
    $n = $rate
    $bytes = New-Object byte[] ($n * 2)
    [Buffer]::BlockCopy($s, $start * 2, $bytes, 0, $n * 2)
    $fs = [IO.File]::Create($path); $w = New-Object IO.BinaryWriter($fs)
    $w.Write([Text.Encoding]::ASCII.GetBytes('RIFF')); $w.Write([int](36 + $bytes.Length))
    $w.Write([Text.Encoding]::ASCII.GetBytes('WAVEfmt ')); $w.Write([int]16)
    $w.Write([int16]1); $w.Write([int16]1); $w.Write([int]$rate); $w.Write([int]($rate * 2))
    $w.Write([int16]2); $w.Write([int16]16)
    $w.Write([Text.Encoding]::ASCII.GetBytes('data')); $w.Write([int]$bytes.Length)
    $w.Write($bytes); $w.Close()
}

$files = if (Test-Path $In -PathType Container) { Get-ChildItem $In -Filter *.wav } else { Get-Item $In }
$total = 0
foreach ($f in $files) {
    $s = Read-Wav $f.FullName
    if ($s.Length -lt $rate) { continue }
    $name = ($f.BaseName -replace '[^A-Za-z0-9-]', '-')
    $starts = New-Object System.Collections.Generic.List[int]
    if ($Label -eq 'noise') {
        for ($p = 0; $p + $rate -le $s.Length; $p += $rate) { $starts.Add($p) }
    } else {
        $nb = [Math]::Floor($s.Length / $block)
        $rms = New-Object double[] $nb
        for ($k = 0; $k -lt $nb; $k++) {
            $sum = 0.0
            for ($j = $k * $block; $j -lt ($k + 1) * $block; $j++) { $sum += [double]$s[$j] * $s[$j] }
            $rms[$k] = [Math]::Sqrt($sum / $block)
        }
        $typical = ($rms | Sort-Object)[[int]($nb / 2)]
        $threshold = [Math]::Max($typical * 4, 600)
        $next = 0
        for ($k = 0; $k -lt $nb; $k++) {
            if ($rms[$k] -lt $threshold -or $k * $block -lt $next) { continue }
            $p = [Math]::Max(0, $k * $block - [int](0.15 * $rate))
            $p = [Math]::Min($p, $s.Length - $rate)
            $starts.Add($p)
            $next = $k * $block + [int](0.8 * $rate)
        }
    }
    $n = 0
    foreach ($p in $starts) { Write-Clip (Join-Path $OutDir "$Label.$name-$n.wav") $s $p; $n++ }
    Write-Host "$($f.Name): $n $Label clips"
    $total += $n
}
Write-Host "Total: $total clips in $OutDir"
