# fix-workspace-assets.ps1
# Run from repository root: powershell -NoProfile -ExecutionPolicy Bypass -File .\fix-workspace-assets.ps1

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not (Test-Path .git)) {
    Write-Error "No .git directory found. Run this from the repository root."
    exit 1
}

$ts = (Get-Date).ToString('yyyyMMdd-HHmmss')
$branch = "refactor/icons-fix-$ts"
git checkout -b $branch

$cssPath = "assets/css/app.css"
if (-not (Test-Path $cssPath)) {
    Write-Error "$cssPath not found. Aborting."
    exit 1
}

$cssText = Get-Content $cssPath -Raw -Encoding UTF8

$oldImportPattern = "(@import url\('https://fonts\.googleapis\.com/css2\?family=Material\+Symbols\+Outlined[^']*'\);)"
$recommendedImport = "@import url('https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0&display=swap');"

if ($cssText -match $oldImportPattern) {
    $cssText = [regex]::Replace($cssText, $oldImportPattern, $recommendedImport)
    Write-Host "Replaced existing Material Symbols @import in $cssPath"
} elseif ($cssText -notmatch "Material\+Symbols\+Outlined" -and $cssText -notmatch "Material Symbols Outlined") {
    $cssText = $cssText -replace "(@import[^;]+;)(\s*)", "`$1`n$recommendedImport`n"
    Write-Host "Inserted Material Symbols @import into $cssPath"
} else {
    Write-Host "Material Symbols import already present in $cssPath (no pattern matched for replace)."
}

if ($cssText -notmatch "\.material-symbols-outlined") {
    $iconRule = @"
.material-symbols-outlined {
  font-family: 'Material Symbols Outlined', 'Material Symbols', system-ui, sans-serif;
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
  line-height: 1;
  speak: none;
}
"@
    $cssText += "`n`n" + $iconRule
    Write-Host "Appended global .material-symbols-outlined rule to $cssPath"
} else {
    Write-Host "Global .material-symbols-outlined rule already exists in $cssPath"
}

Set-Content -Path $cssPath -Value $cssText -Encoding UTF8

$indexPath = "index.html"
if (Test-Path $indexPath) {
    $indexText = Get-Content $indexPath -Raw -Encoding UTF8
    $indexText = $indexText -replace 'href="app\.css(\?[^"]*)?"','href="assets/css/app.css"'
    $indexText = $indexText -replace 'src="app\.js(\?[^"]*)?"','src="assets/js/app.js"'
    Set-Content -Path $indexPath -Value $indexText -Encoding UTF8
    Write-Host "Updated $indexPath references to assets/"
} else {
    Write-Warning "index.html not found at repo root; skipping index update."
}

$allHtml = Get-ChildItem -Recurse -Filter *.html | Where-Object { $_.FullName -notlike "*\index.html" }
foreach ($file in $allHtml) {
    $path = $file.FullName
    $text = Get-Content $path -Raw -Encoding UTF8

    # safer, simpler replacements (do not attempt to preserve query params)
    $text = $text -replace 'href="(\.\./)?app\.css(\?[^"]*)?"','href="../assets/css/app.css"'
    $text = $text -replace 'src="(\.\./)?app\.js(\?[^"]*)?"','src="../assets/js/app.js"'

    # remove Material Symbols google-fonts link tags
    $lines = $text -split "`r?`n"
    $filtered = $lines | Where-Object { $_ -notmatch 'fonts\.googleapis\.com/css2.*Material\+Symbols\+Outlined' }
    $final = ($filtered -join "`n").Trim()

    if ($final -ne $text) {
        Set-Content -Path $path -Value $final -Encoding UTF8
        Write-Host "Patched $path (asset paths updated / duplicate Material Symbols link removed if present)"
    }
}

if (Test-Path $indexPath) {
    $indexText = Get-Content $indexPath -Raw -Encoding UTF8
    if ($indexText -notmatch "fonts\.googleapis\.com") {
        $indexText = $indexText -replace "(<head[^>]*>\s*)", "`$1`n<link href=`"https://fonts.googleapis.com`" rel=`"preconnect`"/>`n<link crossorigin href=`"https://fonts.gstatic.com`" rel=`"preconnect`"/>`n"
        Set-Content -Path $indexPath -Value $indexText -Encoding UTF8
        Write-Host "Inserted font preconnects into $indexPath"
    } else {
        Write-Host "Font preconnects already present in $indexPath"
    }
}

git add -A
git commit -m "chore: centralize Material Symbols import and update HTML asset paths (assets/), branch $branch"

Write-Host ""
Write-Host "Done. Changes committed on branch $branch."
Write-Host "Start local server to test:"
Write-Host "  python -m http.server 5173"
Write-Host "Open http://localhost:5173/ and verify icons load (DevTools → Network → filter 'font')."