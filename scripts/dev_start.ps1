# Levanta backend (PHP :8080) y frontend (Vite :5173)
Write-Host "Iniciando backend PHP en puerto 8080..." -ForegroundColor Cyan
Start-Process -FilePath "c:\xampp\php\php.exe" `
    -ArgumentList "-S", "0.0.0.0:8080", "-t", "c:\xampp\htdocs\beluga\public" `
    -WorkingDirectory "c:\xampp\htdocs\beluga" `
    -WindowStyle Minimized

Start-Sleep -Seconds 1
Write-Host "Iniciando frontend Vite..." -ForegroundColor Cyan
Set-Location "$PSScriptRoot\..\frontend"
npm run dev
