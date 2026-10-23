# Run the whole test suite; only if EVERY test passes, commit and push to GitHub.
# Usage:  powershell -ExecutionPolicy Bypass -File tools\ship.ps1 ["commit message"]
# Needs: XAMPP MySQL running, ERP on http://localhost:8000 and licence portal on http://localhost:8081 (it starts them if they are down).
param([string]$Message = '')
$ErrorActionPreference = 'Continue'   # git/npx print harmless warnings on stderr; real failures are caught by their exit codes below
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$php = 'C:\xampp\php\php.exe'
$e2e = Join-Path $root 'tests\e2e'
$portal = Join-Path (Split-Path -Parent $root) 'app (4)'

function Up($url) { try { (Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 $url).StatusCode -ge 200 } catch { $false } }
function Sql($db, $q) { & $mysql -uroot $db -e $q 2>$null | Out-Null }

Write-Host '== 1/5 Servers' -ForegroundColor Cyan
if (-not (Up 'http://localhost:8000/login.php')) {
  Start-Process -WindowStyle Hidden $php -ArgumentList '-S', 'localhost:8000' -WorkingDirectory (Join-Path $root 'public')
}
if ((Test-Path $portal) -and -not (Up 'http://localhost:8081/login.php')) {
  Start-Process -WindowStyle Hidden $php -ArgumentList '-S', 'localhost:8081' -WorkingDirectory (Join-Path $portal 'public')
}
Start-Sleep -Seconds 3
if (-not (Up 'http://localhost:8000/login.php')) { Write-Host 'ERP is not reachable on :8000. Start XAMPP MySQL and try again. NOTHING was pushed.' -ForegroundColor Red; exit 2 }

Write-Host '== 2/5 Tests (all suites)' -ForegroundColor Cyan
Sql 'pimfi131_pratyangiportal' "update users set active=1 where username like 'bc%' and username<>'bc_license'; delete from login_attempts"
Sql 'pimfi131_cl' "update admin_users set active=1 where username='bc_license'; delete from admin_login_attempts"
$specs = 'erp','dashboards','auditor','workflow','import_count','extras','qr','purchase_module','order_api','barcode_cost','pm_responsive','license','central_link','central_suspend','responsive_audit','portal_responsive' | ForEach-Object { "$_.spec.js" }
$code = 1
try {
  $ErrorActionPreference = 'Continue'   # npx writes warnings to stderr; that is not a failure
  Push-Location $e2e
  & npx playwright test @specs --reporter=line 2>&1 | Tee-Object -Variable out | Select-Object -Last 12
  $code = $LASTEXITCODE
} finally {
  Pop-Location
  Sql 'pimfi131_pratyangiportal' "update users set active=0 where username like 'bc%'"
  Sql 'pimfi131_cl' "update admin_users set active=0 where username='bc_license'"
}
if ($code -ne 0) { Write-Host "`nTESTS FAILED. NOTHING was committed or pushed." -ForegroundColor Red; exit 1 }
$summary = ($out | Select-String -Pattern '\d+ passed' | Select-Object -Last 1).Line.Trim()
Write-Host "Tests OK: $summary" -ForegroundColor Green

Write-Host '== 3/5 Secret check' -ForegroundColor Cyan
git add -A 2>&1 | Out-Null
$staged = git diff --cached --name-only
if (-not $staged) { Write-Host 'No changes to push. Done.' -ForegroundColor Yellow; exit 0 }
$badNames = $staged | Where-Object { $_ -match '(^|/)\.env$|config\.secret\.php$|private\.pem$|\.key$|storage/backups/.+\.(sql|zip)$|Central License' }
$badText = git diff --cached -U0 -- . ':(exclude)tools/ship.ps1' | Select-String -Pattern 'BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY','DB_PASS=\S+','ghp_[A-Za-z0-9]{20,}','AKIA[0-9A-Z]{12,}'
if ($badNames -or $badText) {
  git reset -q
  Write-Host 'A secret or private file is about to be committed. Stopped. NOTHING was pushed:' -ForegroundColor Red
  $badNames; $badText | Select-Object -First 5
  exit 3
}

Write-Host '== 4/5 Commit' -ForegroundColor Cyan
if (-not $Message) { $Message = "Update $(Get-Date -Format 'yyyy-MM-dd HH:mm'): all tests passed" }
$files = ($staged | Select-Object -First 15) -join "`n- "
$body = "$Message`n`nTests: $summary`nChanged files:`n- $files`n`nCo-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
$tmp = New-TemporaryFile
Set-Content -Path $tmp -Value $body -Encoding utf8
git commit -q -F $tmp
$commitCode = $LASTEXITCODE
Remove-Item $tmp
if ($commitCode -ne 0) { Write-Host 'Commit failed. NOTHING was pushed.' -ForegroundColor Red; exit 5 }

Write-Host '== 5/5 Push' -ForegroundColor Cyan
$env:GIT_TERMINAL_PROMPT = '0'
git push origin HEAD
if ($LASTEXITCODE -ne 0) { Write-Host 'Push failed (the commit is saved locally). Check your GitHub sign-in or whether the remote has new commits.' -ForegroundColor Red; exit 4 }
Write-Host "`nPUSHED: $(git log -1 --format='%h %s')" -ForegroundColor Green
